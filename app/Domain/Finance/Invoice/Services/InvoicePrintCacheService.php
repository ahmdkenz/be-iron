<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cache hasil PDF cetak Opening Balance di filesystem — sengaja tanpa tabel DB
 * baru. "Siap" ditentukan oleh keberadaan file di disk privat (path deterministik
 * dari invoice_id + hash versi konten); status proses/gagal numpang di Laravel
 * Cache (tabel `cache` bawaan, sudah dipakai fitur lain di project ini), dengan
 * TTL pendek supaya otomatis pulih kalau job crash tanpa sempat membersihkan
 * penanda. Lihat catatan performa cetak Opening Balance.
 */
class InvoicePrintCacheService
{
    private const DISK = 'local';

    private const DISPATCH_TTL_MINUTES = 15;

    private const FAILURE_TTL_MINUTES = 15;

    /**
     * OB dengan riwayat saldo awal sangat panjang (ribuan baris "Rincian Invoice
     * Periode Sebelumnya") pernah menghabiskan default memory_limit PHP (512M) dan
     * membuat DomPDF fatal error saat menyusun tabel — dipakai di sekitar setiap
     * pemanggilan Pdf::loadView(...)->output() untuk cetak OB (lihat
     * withBoostedRenderLimits()).
     */
    private const RENDER_MEMORY_LIMIT = '2048M';

    /**
     * OB super besar juga bisa melewati max_execution_time default PHP (30 detik)
     * di production sebelum sempat selesai render — beda dari memory_limit, ini
     * tetap berlaku walau job sudah punya $timeout=900 di level Laravel/worker,
     * karena itu batas proses worker, bukan batas engine PHP itu sendiri. Diset di
     * bawah 900 supaya job-level timeout tetap jadi batas akhir yang mengontrol.
     */
    private const RENDER_TIME_LIMIT_SECONDS = 850;

    public function __construct(private readonly InvoiceService $invoiceService) {}

    /**
     * Naikkan memory_limit & max_execution_time PHP sementara khusus untuk render
     * PDF OB yang berat, lalu kembalikan ke nilai semula setelahnya — supaya tidak
     * "bocor" ke pekerjaan lain yang berjalan di proses/worker yang sama.
     */
    public function withBoostedRenderLimits(callable $callback): mixed
    {
        $originalMemoryLimit = ini_get('memory_limit');
        $originalTimeLimit   = (int) ini_get('max_execution_time');

        ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
        set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);

        try {
            return $callback();
        } finally {
            set_time_limit($originalTimeLimit);
            $this->restoreMemoryLimitSafely($originalMemoryLimit);
        }
    }

    /**
     * PHP menolak menurunkan memory_limit lewat ini_set() selama usage saat ini
     * masih >= limit baru — fatal level-engine yang tidak bisa ditangkap
     * try/catch ("Failed to set memory limit..."), persis yang terjadi kalau
     * blok finally di atas langsung mengembalikan ke nilai semula tepat setelah
     * render PDF OB besar (objek DomPDF belum sempat di-GC). gc_collect_cycles()
     * dulu untuk melepas siklus objek yang sudah tidak dipakai; kalau usage
     * masih di atas limit lama setelah itu, biarkan tetap boosted — proses
     * worker (--stop-when-empty) umurnya pendek jadi risiko "bocor" ke job lain
     * minim, jauh lebih aman daripada memicu fatal di jalur cetak.
     */
    private function restoreMemoryLimitSafely(string $originalMemoryLimit): void
    {
        gc_collect_cycles();

        $originalBytes = $this->parseMemoryLimitBytes($originalMemoryLimit);

        if ($originalBytes === -1 || memory_get_usage(true) < $originalBytes) {
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }

    private function parseMemoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => (int) $value,
        };
    }

    /**
     * Hash versi dari agregat murah (COUNT/MAX/SUM, bukan fetch semua baris invoice
     * reguler) — otomatis berubah kalau OB diedit/disetujui, atau ada invoice
     * reguler baru/berubah di bulan yang sama, sehingga file cache lama otomatis
     * dianggap basi tanpa perlu logika invalidasi eksplisit.
     */
    public function resolveVersion(Invoice $invoice): string
    {
        $count      = 0;
        $maxUpdated = '';
        $total      = 0;

        if ($invoice->klien_ar_id && $invoice->tanggal_invoice) {
            $agg = $this->invoiceService->regularInvoicesInPeriodQuery($invoice)
                ->selectRaw('COUNT(*) as cnt, MAX(updated_at) as max_updated, SUM(subtotal) as total')
                ->first();

            $count      = $agg->cnt ?? 0;
            $maxUpdated = $agg->max_updated ?? '';
            $total      = $agg->total ?? 0;
        }

        return md5(implode('|', [$invoice->id, $invoice->updated_at, $count, $maxUpdated, $total]));
    }

    public function path(int $invoiceId, string $version): string
    {
        return "invoice-prints/{$invoiceId}/{$version}.pdf";
    }

    public function isReady(int $invoiceId, string $version): bool
    {
        return Storage::disk(self::DISK)->exists($this->path($invoiceId, $version));
    }

    public function response(int $invoiceId, string $version, string $filename): StreamedResponse
    {
        return Storage::disk(self::DISK)->response($this->path($invoiceId, $version), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Simpan hasil generate lalu bersihkan file versi lama untuk invoice yang sama
     * supaya storage tidak menumpuk (setiap invoice hanya menyisakan 1 file: versi
     * paling baru).
     */
    public function store(int $invoiceId, string $version, string $pdfBinary): void
    {
        $disk        = Storage::disk(self::DISK);
        $currentPath = $this->path($invoiceId, $version);

        $disk->put($currentPath, $pdfBinary);

        foreach ($disk->files("invoice-prints/{$invoiceId}") as $file) {
            if ($file !== $currentPath) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Guard idempotent anti-dispatch-ganda: balas true hanya untuk pemanggil
     * pertama untuk kombinasi invoice+version ini (klik ganda dari List & Detail
     * tidak akan memicu 2 job untuk versi yang sama).
     */
    public function markDispatched(int $invoiceId, string $version): bool
    {
        return Cache::add(
            $this->dispatchKey($invoiceId, $version),
            true,
            now()->addMinutes(self::DISPATCH_TTL_MINUTES)
        );
    }

    public function clearDispatched(int $invoiceId, string $version): void
    {
        Cache::forget($this->dispatchKey($invoiceId, $version));
    }

    public function markFailed(int $invoiceId, string $version, string $message): void
    {
        Cache::put(
            $this->failureKey($invoiceId, $version),
            $message,
            now()->addMinutes(self::FAILURE_TTL_MINUTES)
        );
    }

    public function getFailureMessage(int $invoiceId, string $version): ?string
    {
        return Cache::get($this->failureKey($invoiceId, $version));
    }

    public function clearFailureMessage(int $invoiceId, string $version): void
    {
        Cache::forget($this->failureKey($invoiceId, $version));
    }

    private function dispatchKey(int $invoiceId, string $version): string
    {
        return "invoice-print-dispatched:{$invoiceId}:{$version}";
    }

    private function failureKey(int $invoiceId, string $version): string
    {
        return "invoice-print-failed:{$invoiceId}:{$version}";
    }
}
