<?php

namespace App\Domain\Finance\PembayaranAp\Services;

use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\PembayaranAp;
use App\Models\PembayaranApLog;
use App\Models\TagihanAp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PembayaranApService
{
    public function __construct(private readonly TagihanApService $tagihanApService) {}

    /**
     * Satu-satunya jalur pembuatan Pembayaran AP. Membuat 1 voucher (header
     * tb_pembayaran_ap) yang bisa mencakup banyak Tagihan AP dari banyak
     * vendor sekaligus, asal semua tagihan berasal dari perusahaan_id yang
     * sama (voucher = satu transaksi kas/bank keluar dari satu entitas).
     *
     * $data['alokasi'] = [['tagihan_ap_id' => int, 'jumlah' => float], ...]
     */
    public function createVoucher(array $data, ?UploadedFile $buktiBayar = null): PembayaranAp
    {
        $alokasi = $data['alokasi'] ?? [];
        abort_if(empty($alokasi), 422, 'Voucher harus mencakup minimal 1 tagihan');

        $tagihanIds = collect($alokasi)->pluck('tagihan_ap_id')->map(fn($v) => (int) $v);
        abort_if(
            $tagihanIds->count() !== $tagihanIds->unique()->count(),
            422,
            'Tagihan duplikat tidak boleh muncul lebih dari sekali dalam satu voucher'
        );

        return DB::transaction(function () use ($data, $alokasi, $tagihanIds, $buktiBayar) {
            // Lock dalam urutan id ascending supaya voucher paralel yang menyentuh
            // tagihan yang sama tidak saling deadlock.
            $tagihans = TagihanAp::whereIn('id', $tagihanIds->unique()->sort()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if(
                $tagihans->count() !== $tagihanIds->unique()->count(),
                404,
                'Salah satu tagihan tidak ditemukan'
            );

            $perusahaanIds = $tagihans->pluck('perusahaan_id')->unique();
            abort_if(
                $perusahaanIds->count() > 1,
                422,
                'Tagihan dalam satu voucher harus berasal dari entitas perusahaan yang sama'
            );

            foreach ($alokasi as $row) {
                $tagihan = $tagihans[(int) $row['tagihan_ap_id']];
                $jumlah  = (float) $row['jumlah'];

                abort_if(
                    $tagihan->approval_status !== 'APPROVED',
                    422,
                    "Tagihan {$tagihan->no_tagihan} belum disetujui, pembayaran belum dapat dicatat"
                );
                abort_if(
                    $tagihan->status === 'LUNAS',
                    422,
                    "Tagihan {$tagihan->no_tagihan} sudah berstatus LUNAS, tidak dapat menambah pembayaran"
                );
                abort_if(
                    $jumlah <= 0,
                    422,
                    "Jumlah pembayaran untuk tagihan {$tagihan->no_tagihan} harus lebih dari 0"
                );
                abort_if(
                    $jumlah > (float) $tagihan->sisa_tagihan,
                    422,
                    "Jumlah pembayaran melebihi sisa tagihan {$tagihan->no_tagihan}"
                );
            }

            // Total header selalu diturunkan dari sum alokasi — bukan input klien
            // mentah — supaya tidak ada kelas bug "total tidak sama dengan detail".
            $totalVoucher = collect($alokasi)->sum(fn($row) => (float) $row['jumlah']);

            $pembayaran = PembayaranAp::create([
                'tagihan_ap_id'            => null,
                'tanggal_pembayaran'       => $data['tanggal_pembayaran'],
                'jumlah_pembayaran'        => $totalVoucher,
                'metode_pembayaran'        => $data['metode_pembayaran'],
                'kategori_voucher'         => $data['kategori_voucher'] ?? null,
                'no_referensi'             => $this->generateKodeVoucher($data['kategori_voucher'], $data['tanggal_pembayaran']),
                'keterangan'               => $data['keterangan'] ?? null,
                'dibuat_dari_rekonsiliasi' => $data['dibuat_dari_rekonsiliasi'] ?? false,
                'created_by'               => auth()->id(),
            ]);

            foreach ($alokasi as $row) {
                $tagihan     = $tagihans[(int) $row['tagihan_ap_id']];
                $jumlah      = (float) $row['jumlah'];
                $sisaSebelum = (float) $tagihan->sisa_tagihan;
                $sisaSesudah = max(0.0, $sisaSebelum - $jumlah);

                $pembayaran->items()->create([
                    'tagihan_ap_id'       => $tagihan->id,
                    'vendor_ap_id'        => $tagihan->vendor_ap_id,
                    'jumlah_dialokasikan' => $jumlah,
                    'sisa_sebelum'        => $sisaSebelum,
                    'sisa_sesudah'        => $sisaSesudah,
                ]);

                PembayaranApLog::create([
                    'pembayaran_ap_id' => $pembayaran->id,
                    'aksi'             => 'DIBUAT',
                    'actor_id'         => auth()->id(),
                    'keterangan'       => "Alokasi ke tagihan {$tagihan->no_tagihan} sebesar " . number_format($jumlah, 2, '.', ''),
                    'data_sesudah'     => [
                        'tagihan_ap_id' => $tagihan->id,
                        'no_tagihan'    => $tagihan->no_tagihan,
                        'jumlah'        => $jumlah,
                    ],
                ]);
            }

            foreach ($tagihans as $tagihan) {
                $this->tagihanApService->recalculate($tagihan);
            }

            if ($buktiBayar) {
                try {
                    $this->storeBukti($pembayaran, $buktiBayar);
                } catch (\Throwable $e) {
                    Log::error('PembayaranApService: gagal menyimpan bukti bayar', [
                        'pembayaran_id' => $pembayaran->id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            return $pembayaran->load(['items.tagihanAp.vendorAp', 'createdBy']);
        });
    }

    /**
     * Suffix diacak lalu kandidatnya dicek ke DB agar beberapa voucher di tanggal
     * & kategori yang sama tidak bentrok; unique index uniq_pembayaran_ap_no_referensi
     * tetap jadi pengaman terakhir kalau ada race.
     */
    private function generateKodeVoucher(string $kategoriVoucher, string $tanggalPembayaran): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $kandidat = $this->buildKodeVoucherCandidate($kategoriVoucher, $tanggalPembayaran);

            if (!PembayaranAp::where('no_referensi', $kandidat)->exists()) {
                return $kandidat;
            }
        }

        return $kandidat;
    }

    /** Format: VBK-{BB|NBB}-{DDMMYYYY}-{XXX}, XXX = 000-999 acak. */
    private function buildKodeVoucherCandidate(string $kategoriVoucher, string $tanggalPembayaran): string
    {
        $prefix  = 'VBK-' . $kategoriVoucher;
        $tanggal = \Carbon\Carbon::parse($tanggalPembayaran)->format('dmY');
        $suffix  = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        return "{$prefix}-{$tanggal}-{$suffix}";
    }

    /**
     * Wrapper untuk endpoint lama (POST /tagihan/{id}/pembayaran, 1 tagihan) —
     * signature & response dipertahankan agar TagihanApPembayaranForm.vue tidak
     * perlu diubah. Internal-nya delegasi penuh ke createVoucher() dengan 1 item.
     */
    public function create(TagihanAp $tagihan, array $data, ?UploadedFile $buktiBayar = null): PembayaranAp
    {
        return $this->createVoucher(array_merge($data, [
            'alokasi' => [
                ['tagihan_ap_id' => $tagihan->id, 'jumlah' => $data['jumlah_pembayaran']],
            ],
        ]), $buktiBayar);
    }

    private function storeBukti(PembayaranAp $pembayaran, UploadedFile $file): void
    {
        $path = $this->buildBuktiPath($pembayaran, $file);

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $pembayaran->update([
            'bukti_disk'        => 'local',
            'bukti_path'        => $path,
            'bukti_file_name'   => $file->getClientOriginalName(),
            'bukti_file_size'   => $file->getSize(),
            'bukti_mime_type'   => $file->getMimeType() ?? $file->getClientMimeType(),
            'bukti_uploaded_at' => now(),
        ]);
    }

    private function buildBuktiPath(PembayaranAp $pembayaran, UploadedFile $file): string
    {
        $periode    = optional($pembayaran->tanggal_pembayaran)->format('Y/m') ?? now()->format('Y/m');
        $refSegment = $this->sanitizePathSegment($pembayaran->no_referensi, "voucher-{$pembayaran->id}");

        $ext      = $file->getClientOriginalExtension();
        $fileName = "pembayaran-{$pembayaran->id}-" . Str::uuid()->toString() . ($ext ? ".{$ext}" : '');

        // Voucher bisa lintas vendor, jadi path tidak lagi dinamai per-vendor
        // seperti sebelumnya — file lama tetap di path lamanya, ini cuma skema
        // untuk upload baru.
        return "bukti-bayar-ap/VOUCHER/{$periode}/{$refSegment}/{$fileName}";
    }

    private function sanitizePathSegment(?string $segment, string $fallback): string
    {
        $segment = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', (string) $segment);
        $segment = preg_replace('/\s+/', ' ', $segment);
        $segment = trim($segment, " .-");

        return $segment !== '' ? $segment : $fallback;
    }

    public function delete(PembayaranAp $pembayaran): void
    {
        DB::transaction(function () use ($pembayaran) {
            $tagihanIds = $pembayaran->items()->pluck('tagihan_ap_id')->unique()->sort()->values();
            $tagihans   = TagihanAp::whereIn('id', $tagihanIds)->lockForUpdate()->get();

            PembayaranApLog::create([
                'pembayaran_ap_id' => $pembayaran->id,
                'aksi'             => 'DIHAPUS',
                'actor_id'         => auth()->id(),
                'data_sebelum'     => $pembayaran->load('items')->toArray(),
            ]);

            // Lepas tautan rekonsiliasi bank SEBELUM hard delete, meniru
            // PembayaranArService::delete() — kalau voucher ini dulu dicocokkan
            // ke sebuah detail rekening koran, detail itu harus kembali UNMATCHED
            // supaya bisa dicocokkan ulang.
            $linkedStatementIds = BankStatementDetail::where('pembayaran_ap_id', $pembayaran->id)
                ->pluck('bank_statement_id')
                ->unique()
                ->all();
            BankStatementDetail::where('pembayaran_ap_id', $pembayaran->id)
                ->update([
                    'pembayaran_ap_id' => null,
                    'status_cocok'     => 'UNMATCHED',
                    'status_posting_2' => 'PENDING',
                    'posted_at'        => null,
                    'posted_by'        => null,
                    'matched_by'       => null,
                ]);

            $pembayaran->delete();

            foreach ($tagihans as $tagihan) {
                $this->tagihanApService->recalculate($tagihan);
            }

            foreach ($linkedStatementIds as $statementId) {
                $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
                $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();
                BankStatement::where('id', $statementId)->update([
                    'jumlah_matched'   => $matched,
                    'jumlah_unmatched' => $unmatched,
                ]);
            }
        });
    }

    public function cekDuplikatReferensi(string $noRef): ?array
    {
        $existing = PembayaranAp::with(['items.tagihanAp.karyawan', 'items.tagihanAp.vendorAp'])
            ->where('no_referensi', $noRef)
            ->first();

        if (!$existing) {
            return null;
        }

        $items     = $existing->items;
        $firstItem = $items->first();
        $isSingle  = $items->count() === 1;

        return [
            'pembayaran_id'      => $existing->id,
            'tagihan_count'      => $items->count(),
            'vendor_names'       => $items->pluck('tagihanAp.vendorAp.nama_vendor')->filter()->unique()->values()->all(),
            'no_tagihan'         => $isSingle ? $firstItem?->tagihanAp?->no_tagihan : null,
            'vendor'             => $isSingle ? $firstItem?->tagihanAp?->vendorAp?->nama_vendor : null,
            'pic'                => $isSingle ? $firstItem?->tagihanAp?->karyawan?->nama_karyawan : null,
            'tanggal_pembayaran' => $existing->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $existing->jumlah_pembayaran,
            'metode_pembayaran'  => $existing->metode_pembayaran,
        ];
    }
}
