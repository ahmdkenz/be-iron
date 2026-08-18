<?php

namespace App\Domain\Finance\PembayaranAr\Services;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceService;
use App\Domain\Finance\EndingBalance\Services\EndingBalanceSyncBatcher;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PendapatanDiMuka\Services\PendapatanDiMukaService;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\EndingBalanceKoreksi;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\PembayaranArLog;
use App\Models\PendapatanDiMuka;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PembayaranArService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PendapatanDiMukaService $pdmService,
        private readonly EndingBalanceService $endingBalanceService,
    ) {}

    public function create(Invoice $invoice, array $data, ?UploadedFile $buktiBayar = null): PembayaranAr
    {
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, pembayaran belum dapat dicatat'
        );

        abort_if(
            $invoice->status === 'LUNAS',
            422,
            'Invoice ini sudah berstatus LUNAS, tidak dapat menambah pembayaran'
        );

        $pembayaran = PembayaranAr::create([
            'invoice_id'         => $invoice->id,
            'tanggal_pembayaran' => $data['tanggal_pembayaran'],
            'jumlah_pembayaran'  => $data['jumlah_pembayaran'],
            'metode_pembayaran'  => $data['metode_pembayaran'],
            'no_referensi'       => $data['no_referensi'] ?? null,
            'keterangan'         => $data['keterangan'] ?? null,
            'dibuat_dari_rekonsiliasi' => $data['dibuat_dari_rekonsiliasi'] ?? false,
            'created_by'         => auth()->id(),
        ]);

        PembayaranArLog::create([
            'pembayaran_ar_id' => $pembayaran->id,
            'aksi'             => 'DIBUAT',
            'actor_id'         => auth()->id(),
            'data_sesudah'     => $pembayaran->toArray(),
        ]);

        $this->invoiceService->recalculate($invoice->fresh());

        // Pelunasan OB → lunaskan invoice reguler periode sebelumnya yang dipilih user.
        if ($invoice->is_opening_balance && !empty($data['settle_original_invoice_ids'])) {
            $this->invoiceService->settleOriginalsFromOpeningBalance(
                $invoice->fresh(),
                $pembayaran,
                $data['settle_original_invoice_ids'],
            );
        }

        if ($buktiBayar) {
            try {
                $this->storeBukti($pembayaran, $buktiBayar, $invoice->loadMissing(['klienAr.resto', 'resto', 'items']));
            } catch (\Throwable $e) {
                Log::error('PembayaranArService: gagal menyimpan bukti bayar', [
                    'pembayaran_id' => $pembayaran->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return $pembayaran->load('createdBy');
    }

    /**
     * Satu-satunya jalur pembuatan "Multi Payment" — 1 header (tb_pembayaran_ar,
     * invoice_id SELALU NULL) yang mencakup banyak invoice sekaligus lewat
     * tb_pembayaran_ar_items, mirip PembayaranApService::createVoucher(). Jalur
     * pembayaran invoice-tunggal lama (create() di atas) TIDAK disentuh sama
     * sekali — method ini murni tambahan.
     *
     * $data['alokasi'] = [['invoice_id' => int, 'jumlah' => float], ...]
     */
    public function createMultiPayment(array $data, ?UploadedFile $buktiBayar = null): PembayaranAr
    {
        $alokasi = $data['alokasi'] ?? [];
        abort_if(empty($alokasi), 422, 'Multi Payment harus mencakup minimal 1 invoice');
        abort_if(count($alokasi) > 50, 422, 'Maksimum 50 invoice per Multi Payment');

        $invoiceIds = collect($alokasi)->pluck('invoice_id')->map(fn($v) => (int) $v);
        abort_if(
            $invoiceIds->count() !== $invoiceIds->unique()->count(),
            422,
            'Invoice duplikat tidak boleh muncul lebih dari sekali dalam satu Multi Payment'
        );

        return DB::transaction(function () use ($data, $alokasi, $invoiceIds, $buktiBayar) {
            // Lock dalam urutan id ascending supaya Multi Payment paralel yang
            // menyentuh invoice yang sama tidak saling deadlock (pola sama
            // seperti PembayaranApService::createVoucher()).
            $invoices = Invoice::whereIn('id', $invoiceIds->unique()->sort()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            abort_if(
                $invoices->count() !== $invoiceIds->unique()->count(),
                404,
                'Salah satu invoice tidak ditemukan'
            );

            $perusahaanIds = $invoices->pluck('perusahaan_id')->unique();
            abort_if(
                $perusahaanIds->count() > 1,
                422,
                'Invoice dalam satu Multi Payment harus berasal dari entitas penagih yang sama'
            );

            foreach ($alokasi as $row) {
                $invoice = $invoices[(int) $row['invoice_id']];
                $jumlah  = (float) $row['jumlah'];

                abort_if(
                    $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
                    422,
                    "Opening balance invoice {$invoice->no_invoice} belum disetujui, pembayaran belum dapat dicatat"
                );
                abort_if(
                    $invoice->status === 'LUNAS',
                    422,
                    "Invoice {$invoice->no_invoice} sudah berstatus LUNAS, tidak dapat menambah pembayaran"
                );
                abort_if(
                    $jumlah <= 0,
                    422,
                    "Jumlah pembayaran untuk invoice {$invoice->no_invoice} harus lebih dari 0"
                );
                abort_if(
                    $jumlah > (float) $invoice->sisa_tagihan,
                    422,
                    "Jumlah pembayaran melebihi sisa tagihan invoice {$invoice->no_invoice}"
                );
            }

            // Total header selalu diturunkan dari sum alokasi — bukan input klien
            // mentah — supaya tidak ada kelas bug "total tidak sama dengan detail".
            $totalPayment = collect($alokasi)->sum(fn($row) => (float) $row['jumlah']);

            $pembayaran = PembayaranAr::create([
                'invoice_id'               => null,
                'tanggal_pembayaran'       => $data['tanggal_pembayaran'],
                'jumlah_pembayaran'        => $totalPayment,
                'metode_pembayaran'        => $data['metode_pembayaran'],
                'no_referensi'             => $data['no_referensi'] ?? null,
                'keterangan'               => $data['keterangan'] ?? null,
                'dibuat_dari_rekonsiliasi' => $data['dibuat_dari_rekonsiliasi'] ?? false,
                'created_by'               => auth()->id(),
            ]);

            foreach ($alokasi as $row) {
                $invoice     = $invoices[(int) $row['invoice_id']];
                $jumlah      = (float) $row['jumlah'];
                $sisaSebelum = (float) $invoice->sisa_tagihan;
                $sisaSesudah = max(0.0, $sisaSebelum - $jumlah);

                $pembayaran->items()->create([
                    'invoice_id'          => $invoice->id,
                    'klien_ar_id'         => $invoice->klien_ar_id,
                    'jumlah_dialokasikan' => $jumlah,
                    'sisa_sebelum'        => $sisaSebelum,
                    'sisa_sesudah'        => $sisaSesudah,
                ]);

                PembayaranArLog::create([
                    'pembayaran_ar_id' => $pembayaran->id,
                    'aksi'             => 'DIBUAT',
                    'actor_id'         => auth()->id(),
                    'keterangan'       => "Alokasi ke invoice {$invoice->no_invoice} sebesar " . number_format($jumlah, 2, '.', ''),
                    'data_sesudah'     => [
                        'invoice_id' => $invoice->id,
                        'no_invoice' => $invoice->no_invoice,
                        'jumlah'     => $jumlah,
                    ],
                ]);
            }

            // Dedup resync Ending Balance per klien+periode ditangani oleh pemanggil
            // (BankStatementService::matchWithNewMultiPayment()) yang membungkus
            // EndingBalanceSyncBatcher::run() DI LUAR DB::transaction() ini —
            // membungkusnya DI DALAM transaksi (seperti sebelumnya di sini) tidak
            // pernah efektif: DB::afterCommit yang memicu sync baru jalan setelah
            // transaksi paling luar commit, dan saat itu scope run() di sini sudah
            // lama closed (isActive() sudah balik false), jadi InvoiceObserver
            // selalu jatuh ke jalur sync langsung per-invoice alih-alih collect().
            foreach ($invoices as $invoice) {
                $this->invoiceService->recalculate($invoice);
            }

            if ($buktiBayar) {
                try {
                    $this->storeBuktiForMulti($pembayaran, $buktiBayar, $invoices->first());
                } catch (\Throwable $e) {
                    Log::error('PembayaranArService: gagal menyimpan bukti bayar Multi Payment', [
                        'pembayaran_id' => $pembayaran->id,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            return $pembayaran->load(['items.invoice.klienAr', 'createdBy']);
        });
    }

    private function storeBuktiForMulti(PembayaranAr $pembayaran, UploadedFile $file, Invoice $primaryInvoice): void
    {
        $primaryInvoice->loadMissing(['klienAr.resto', 'resto', 'items']);
        $path = $this->buildBuktiPath($pembayaran, $file, $primaryInvoice);
        // Sub-folder pembeda MULTI-{id} supaya tidak collision dengan bukti bayar
        // invoice tunggal milik invoice yang sama.
        $path = str_replace(
            "pembayaran-{$pembayaran->id}-",
            "MULTI-{$pembayaran->id}-",
            $path
        );

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

    private function storeBukti(PembayaranAr $pembayaran, UploadedFile $file, Invoice $invoice): void
    {
        $path = $this->buildBuktiPath($pembayaran, $file, $invoice);

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

    private function buildBuktiPath(PembayaranAr $pembayaran, UploadedFile $file, Invoice $invoice): string
    {
        $klienSegment = $this->sanitizePathSegment(
            $invoice->klienAr
                ? trim("{$invoice->klienAr->kode_klien} - {$invoice->klienAr->nama_klien}", ' -')
                : null,
            "Klien {$invoice->klien_ar_id}"
        );

        $kodeResto  = $invoice->resto?->kode_resto ?? $invoice->klienAr?->resto?->kode_resto;
        $namaResto  = $invoice->resto?->nama_resto ?? $invoice->klienAr?->resto?->nama_resto ?? $invoice->resolveRestoName();
        $restoLabel = $namaResto
            ? trim(($kodeResto ? "{$kodeResto} - " : '') . $namaResto)
            : null;
        $restoSegment = $this->sanitizePathSegment($restoLabel, 'Tanpa Resto');

        $periode = optional($invoice->tanggal_invoice)->format('Y/m') ?? now()->format('Y/m');

        $invoiceSegment = $this->sanitizePathSegment($invoice->no_invoice, "invoice-{$invoice->id}");

        $ext      = $this->sanitizeExtension($file);
        $fileName = "pembayaran-{$pembayaran->id}-" . Str::uuid()->toString() . ($ext ? ".{$ext}" : '');

        return "bukti-bayar/{$klienSegment}/{$restoSegment}/{$periode}/{$invoiceSegment}/{$fileName}";
    }

    private function sanitizePathSegment(?string $segment, string $fallback): string
    {
        $segment = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', (string) $segment);
        $segment = preg_replace('/\s+/', ' ', $segment);
        $segment = trim($segment, " .-");

        return $segment !== '' ? $segment : $fallback;
    }

    private const ALLOWED_BUKTI_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    // Ekstensi dari nama file yang dikirim client tidak bisa dipercaya untuk membangun
    // path penyimpanan (bisa "shell.php" walau isi filenya PNG asli — validasi FormRequest
    // 'mimes:pdf,jpg,jpeg,png' mengecek KONTEN file, bukan nama file). Cocokkan ke allowlist
    // yang sama dengan aturan mimes: itu; kalau ekstensi klien di luar allowlist, tebak dari
    // MIME type asli hasil deteksi konten file sebagai fallback.
    private function sanitizeExtension(UploadedFile $file): string
    {
        $clientExt = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($clientExt, self::ALLOWED_BUKTI_EXTENSIONS, true)) {
            return $clientExt;
        }

        $guessedExt = strtolower((string) $file->extension());

        return in_array($guessedExt, self::ALLOWED_BUKTI_EXTENSIONS, true) ? $guessedExt : 'bin';
    }

    public function storeBuktiForPdm(PembayaranAr $pembayaran, UploadedFile $file, KlienAr $klienAr): void
    {
        $path = $this->buildBuktiPathForPdm($pembayaran, $file, $klienAr->loadMissing('resto'));

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

    private function buildBuktiPathForPdm(PembayaranAr $pembayaran, UploadedFile $file, KlienAr $klienAr): string
    {
        $klienSegment = $this->sanitizePathSegment(
            trim("{$klienAr->kode_klien} - {$klienAr->nama_klien}", ' -'),
            "Klien {$klienAr->id}"
        );

        $restoLabel = $klienAr->resto?->nama_resto
            ? trim(($klienAr->resto->kode_resto ? "{$klienAr->resto->kode_resto} - " : '') . $klienAr->resto->nama_resto)
            : null;
        $restoSegment = $this->sanitizePathSegment($restoLabel, 'Tanpa Resto');

        $periode = optional($pembayaran->tanggal_pembayaran)->format('Y/m') ?? now()->format('Y/m');

        $ext      = $this->sanitizeExtension($file);
        $fileName = "pdm-{$pembayaran->id}-" . Str::uuid()->toString() . ($ext ? ".{$ext}" : '');

        return "bukti-bayar/{$klienSegment}/{$restoSegment}/{$periode}/PDM/{$fileName}";
    }

    public function delete(PembayaranAr $pembayaran): void
    {
        $pdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $pembayaran->id)->first();

        abort_if(
            $pdm && $pdm->status === 'TERPAKAI',
            422,
            'Tidak dapat menghapus pembayaran ini karena Pendapatan di Muka sudah digunakan untuk melunasi invoice. Batalkan penggunaan PDM terlebih dahulu.'
        );

        // Guard ini sebelumnya hanya dicek di jalur BankStatementService::unmatch()
        // (hasApprovedKoreksi) — bisa dilewati lewat "Hapus" langsung di halaman
        // detail invoice / PembayaranArController::destroy(). Dipindah ke sini
        // supaya berlaku untuk SEMUA jalur hapus, termasuk Multi Payment (cek
        // semua invoice yang tercakup lewat items).
        $invoiceIds = $pembayaran->invoice_id
            ? [$pembayaran->invoice_id]
            : $pembayaran->items()->pluck('invoice_id')->filter()->unique()->values()->all();

        if (!empty($invoiceIds)) {
            abort_if(
                EndingBalanceKoreksi::whereIn('invoice_id', $invoiceIds)->where('status', 'APPROVED')->exists(),
                422,
                'Tidak dapat menghapus pembayaran ini karena invoice terkait sudah memiliki Credit Note/Debit Note yang disetujui.'
            );

            $lockedInvoiceExists = Invoice::whereIn('id', $invoiceIds)
                ->get(['id', 'klien_ar_id', 'tanggal_invoice'])
                ->contains(fn(Invoice $inv) => $inv->klien_ar_id && $inv->tanggal_invoice
                    && $this->endingBalanceService->isLockedForPeriod($inv->klien_ar_id, $inv->tanggal_invoice->toDateString()));

            abort_if(
                $lockedInvoiceExists,
                422,
                'Tidak dapat menghapus pembayaran ini karena periode invoice terkait sudah dikunci di Ending Balance.'
            );
        }

        // Kumpulkan alokasi anak DAN invoice tujuannya SEBELUM FK nullOnDelete berjalan.
        // Setelah $pembayaran->delete(), sumber_pembayaran_ar_id di-NULL sehingga
        // query WHERE sumber_pembayaran_ar_id = X tidak akan menemukan records lagi.
        $childAllocations = $pembayaran->alokasiKelebihan()->with('invoice')->get();
        $affectedInvoices = $childAllocations->map->invoice->filter()->unique('id')->values();

        $invoiceId = $pembayaran->invoice_id;
        $sumberId  = $pembayaran->sumber_pembayaran_ar_id;

        // Multi Payment (invoice_id null, alokasi lewat items): kumpulkan invoice
        // id-nya sebelum items ikut terhapus oleh cascadeOnDelete saat header dihapus.
        $multiInvoiceIds = $pembayaran->items()->pluck('invoice_id')->unique()->sort()->values();

        // EndingBalanceSyncBatcher::run() HARUS membungkus DI LUAR DB::transaction()
        // (bukan di dalam) supaya dedup sync EB benar-benar aktif — lihat catatan di
        // PembayaranArService::createMultiPayment(). delete() punya 2 pemanggil nyata
        // (langsung lewat PembayaranArController::destroy(), dan lewat
        // BankStatementService::unmatch() yang membungkus transaksinya sendiri di
        // luar ini) — membungkus di sini membuat kedua jalur benar tanpa saling
        // konflik, karena EndingBalanceSyncBatcher::run() aman untuk nesting.
        EndingBalanceSyncBatcher::run(function () use ($pembayaran, $childAllocations, $affectedInvoices, $invoiceId, $pdm, $sumberId, $multiInvoiceIds) {
            DB::transaction(function () use ($pembayaran, $childAllocations, $affectedInvoices, $invoiceId, $pdm, $sumberId, $multiInvoiceIds) {
                // Lock semua invoice Multi Payment terkait di urutan id ascending
                // (sama seperti PembayaranApService::delete()) sebelum header dihapus,
                // supaya recalculate() di bawah tidak balapan dengan proses lain yang
                // menyentuh invoice yang sama.
                $multiInvoices = $multiInvoiceIds->isNotEmpty()
                    ? Invoice::whereIn('id', $multiInvoiceIds)->lockForUpdate()->get()->keyBy('id')
                    : collect();

                PembayaranArLog::create([
                    'pembayaran_ar_id' => $pembayaran->id,
                    'aksi'             => 'DIHAPUS',
                    'actor_id'         => auth()->id(),
                    'data_sebelum'     => $pembayaran->toArray(),
                ]);

                foreach ($childAllocations as $alloc) {
                    PembayaranArLog::create([
                        'pembayaran_ar_id' => $alloc->id,
                        'aksi'             => 'DIHAPUS',
                        'actor_id'         => auth()->id(),
                        'data_sebelum'     => $alloc->toArray(),
                    ]);
                }

                $pembayaran->alokasiKelebihan()->delete();

                if ($pdm) {
                    $pdm->delete();
                }

                $linkedStatementIds = BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
                    ->pluck('bank_statement_id')
                    ->unique()
                    ->all();
                BankStatementDetail::where('pembayaran_ar_id', $pembayaran->id)
                    ->update([
                        'pembayaran_ar_id' => null,
                        'status_cocok'     => 'UNMATCHED',
                        'status_posting_2' => 'PENDING',
                        'posted_at'        => null,
                        'posted_by'        => null,
                        'matched_by'       => null,
                    ]);

                $pembayaran->delete();

                if ($sumberId) {
                    $parentPdm = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $sumberId)->first();
                    if ($parentPdm && $parentPdm->status !== 'DIBATALKAN') {
                        $this->pdmService->recalculate($parentPdm->fresh());
                    }
                }

                foreach ($linkedStatementIds as $statementId) {
                    $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
                    $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();
                    BankStatement::where('id', $statementId)->update([
                        'jumlah_matched'   => $matched,
                        'jumlah_unmatched' => $unmatched,
                    ]);
                }

                // invoice_id bisa null untuk pembayaran shell PDM tanpa invoice (Catat sebagai PDM).
                $invoice = $invoiceId ? Invoice::find($invoiceId) : null;
                if ($invoice) {
                    $this->invoiceService->recalculate($invoice);
                }

                foreach ($affectedInvoices as $targetInvoice) {
                    if ($targetInvoice->id !== $invoiceId) {
                        $this->invoiceService->recalculate($targetInvoice->fresh());
                    }
                }

                foreach ($multiInvoices as $invoice) {
                    $this->invoiceService->recalculate($invoice);
                }
            });
        });
    }
}
