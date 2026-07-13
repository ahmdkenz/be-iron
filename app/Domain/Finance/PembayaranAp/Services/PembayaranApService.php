<?php

namespace App\Domain\Finance\PembayaranAp\Services;

use App\Domain\Finance\TagihanAp\Services\TagihanApService;
use App\Models\PembayaranAp;
use App\Models\PembayaranApLog;
use App\Models\TagihanAp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PembayaranApService
{
    public function __construct(private readonly TagihanApService $tagihanApService) {}

    public function create(TagihanAp $tagihan, array $data, ?UploadedFile $buktiBayar = null): PembayaranAp
    {
        abort_if(
            $tagihan->approval_status !== 'APPROVED',
            422,
            'Tagihan belum disetujui, pembayaran belum dapat dicatat'
        );

        abort_if(
            $tagihan->status === 'LUNAS',
            422,
            'Tagihan ini sudah berstatus LUNAS, tidak dapat menambah pembayaran'
        );

        $pembayaran = PembayaranAp::create([
            'tagihan_ap_id'      => $tagihan->id,
            'tanggal_pembayaran' => $data['tanggal_pembayaran'],
            'jumlah_pembayaran'  => $data['jumlah_pembayaran'],
            'metode_pembayaran'  => $data['metode_pembayaran'],
            'no_referensi'       => $data['no_referensi'] ?? null,
            'keterangan'         => $data['keterangan'] ?? null,
            'dibuat_dari_rekonsiliasi' => $data['dibuat_dari_rekonsiliasi'] ?? false,
            'created_by'         => auth()->id(),
        ]);

        PembayaranApLog::create([
            'pembayaran_ap_id' => $pembayaran->id,
            'aksi'             => 'DIBUAT',
            'actor_id'         => auth()->id(),
            'data_sesudah'     => $pembayaran->toArray(),
        ]);

        $this->tagihanApService->recalculate($tagihan->fresh());

        if ($buktiBayar) {
            try {
                $this->storeBukti($pembayaran, $buktiBayar, $tagihan->loadMissing('vendorAp'));
            } catch (\Throwable $e) {
                Log::error('PembayaranApService: gagal menyimpan bukti bayar', [
                    'pembayaran_id' => $pembayaran->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        return $pembayaran->load('createdBy');
    }

    private function storeBukti(PembayaranAp $pembayaran, UploadedFile $file, TagihanAp $tagihan): void
    {
        $path = $this->buildBuktiPath($pembayaran, $file, $tagihan);

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

    private function buildBuktiPath(PembayaranAp $pembayaran, UploadedFile $file, TagihanAp $tagihan): string
    {
        $vendorSegment = $this->sanitizePathSegment(
            $tagihan->vendorAp
                ? trim("{$tagihan->vendorAp->kode_vendor} - {$tagihan->vendorAp->nama_vendor}", ' -')
                : null,
            "Vendor {$tagihan->vendor_ap_id}"
        );

        $periode = optional($tagihan->tanggal_tagihan)->format('Y/m') ?? now()->format('Y/m');

        $tagihanSegment = $this->sanitizePathSegment($tagihan->no_tagihan, "tagihan-{$tagihan->id}");

        $ext      = $file->getClientOriginalExtension();
        $fileName = "pembayaran-{$pembayaran->id}-" . Str::uuid()->toString() . ($ext ? ".{$ext}" : '');

        return "bukti-bayar-ap/{$vendorSegment}/{$periode}/{$tagihanSegment}/{$fileName}";
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
        $tagihanId = $pembayaran->tagihan_ap_id;

        PembayaranApLog::create([
            'pembayaran_ap_id' => $pembayaran->id,
            'aksi'             => 'DIHAPUS',
            'actor_id'         => auth()->id(),
            'data_sebelum'     => $pembayaran->toArray(),
        ]);

        $pembayaran->delete();

        $tagihan = TagihanAp::find($tagihanId);
        if ($tagihan) {
            $this->tagihanApService->recalculate($tagihan);
        }
    }

    public function cekDuplikatReferensi(string $noRef): ?array
    {
        $existing = PembayaranAp::with(['tagihanAp.karyawan', 'tagihanAp.vendorAp'])
            ->where('no_referensi', $noRef)
            ->first();

        if (!$existing) {
            return null;
        }

        return [
            'pembayaran_id'      => $existing->id,
            'no_tagihan'         => $existing->tagihanAp?->no_tagihan,
            'vendor'             => $existing->tagihanAp?->vendorAp?->nama_vendor,
            'pic'                => $existing->tagihanAp?->karyawan?->nama_karyawan,
            'tanggal_pembayaran' => $existing->tanggal_pembayaran?->format('d-m-Y'),
            'jumlah_pembayaran'  => (float) $existing->jumlah_pembayaran,
            'metode_pembayaran'  => $existing->metode_pembayaran,
        ];
    }
}
