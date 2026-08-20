<?php

namespace App\Domain\Finance\Invoice\Jobs;

use App\Domain\Finance\Invoice\Services\EmailBlastCacheService;
use App\Mail\InvoiceDocumentEmail;
use App\Models\Invoice;
use App\Models\KlienAr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Kirim Invoice/OB ke email klien AR, satu-satu per klien lewat SMTP milik
 * PIC AR yang menangani klien tsb (tb_users.smtp_*) — pengganti WA blast
 * Fonnte lama. Progres & hasil per-penerima numpang di Cache lewat
 * EmailBlastCacheService, meniru pola async+Cache "Approve Semua" OB.
 */
class SendKlienArEmailBatchJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    private const DYNAMIC_MAILER = 'pic_smtp_dynamic';

    /**
     * @param array<int, array{klien_ar_id:int,invoice_ids:int[]}> $recipients
     */
    public function __construct(
        private readonly string $batchId,
        private readonly array $recipients,
        private readonly int $userId,
    ) {
        $this->onQueue('invoice-email');
    }

    public function handle(EmailBlastCacheService $cache): void
    {
        $cache->update($this->batchId, ['status' => 'processing']);

        $sent    = 0;
        $results = [];
        $delayMs = (int) config('services.email_blast.delay_ms', 1000);
        $lastIndex = array_key_last($this->recipients);

        try {
            foreach ($this->recipients as $index => $recipient) {
                $result    = $this->sendOne($recipient);
                $results[] = $result;
                if ($result['success']) {
                    $sent++;
                }

                $cache->update($this->batchId, [
                    'processed' => $index + 1,
                    'sent'      => $sent,
                    'results'   => $results,
                ]);

                // Jeda antar pengiriman — tanpa ini banyak provider SMTP (mis.
                // Mailtrap sandbox) menolak dengan "550 5.7.0 Too many emails
                // per second" karena request beruntun tanpa jeda sama sekali.
                if ($index !== $lastIndex && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }

            $cache->update($this->batchId, ['status' => 'completed']);
        } catch (Throwable $e) {
            Log::error('SendKlienArEmailBatchJob: gagal', ['batch_id' => $this->batchId, 'error' => $e->getMessage()]);
            $cache->update($this->batchId, [
                'status'  => 'failed',
                'message' => 'Terjadi kesalahan sistem saat proses kirim email: ' . $e->getMessage(),
            ]);
        } finally {
            $cache->clearActive($this->userId, $this->batchId);
        }
    }

    /**
     * @param array{klien_ar_id:int,invoice_ids:int[]} $recipient
     */
    private function sendOne(array $recipient): array
    {
        $klien = KlienAr::with(['perusahaan', 'resto.investor', 'karyawanAr.user.karyawan'])
            ->find($recipient['klien_ar_id']);

        $label = $klien?->resolveDisplayLabel() ?? "Klien #{$recipient['klien_ar_id']}";

        if (!$klien) {
            return $this->skip($label, 'Klien AR tidak ditemukan');
        }

        $email = $klien->resolveContactEmail();
        if (!$email) {
            return $this->skip($label, 'Klien ini belum punya alamat email terdaftar (isi di Data Investor/Entitas)');
        }

        $picUser = $klien->karyawanAr?->user;
        if (!$picUser || !$picUser->hasSmtpConfigured()) {
            return $this->skip($label, 'PIC AR untuk klien ini belum setup SMTP email');
        }

        $documents = Invoice::whereIn('id', $recipient['invoice_ids'])
            ->where('klien_ar_id', $klien->id)
            ->get()
            ->filter(fn (Invoice $inv) => $inv->isApprovedForFinanceFlow() && $inv->prepared_token)
            ->map(fn (Invoice $inv) => [
                'no_invoice'         => $inv->no_invoice,
                'is_opening_balance' => (bool) $inv->is_opening_balance,
                'subtotal'           => (float) $inv->subtotal,
                'share_url'          => URL::temporarySignedRoute(
                    'invoice.public-print', now()->addDays(30), ['token' => $inv->prepared_token]
                ),
            ])
            ->values()
            ->all();

        if (empty($documents)) {
            return $this->skip($label, 'Tidak ada dokumen yang siap dibagikan (belum disetujui)');
        }

        try {
            // Nama mailer HARUS unik per pengiriman (bukan konstanta tetap) — MailManager
            // meng-cache instance Mailer/Transport per nama (Mail::mailer($name) hanya
            // resolve config sekali lalu simpan di cache internal), jadi kalau nama dipakai
            // ulang untuk PIC berikutnya dalam loop yang sama, kredensial SMTP PIC PERTAMA
            // yang terpakai diam-diam untuk semua penerima berikutnya juga.
            $mailerName = self::DYNAMIC_MAILER . '_' . Str::uuid();

            config(['mail.mailers.' . $mailerName => [
                'transport'  => 'smtp',
                'host'       => $picUser->smtp_host,
                'port'       => $picUser->smtp_port,
                'username'   => $picUser->smtp_username,
                'password'   => $picUser->smtp_password,
                'encryption' => $picUser->smtp_encryption,
            ]]);

            Mail::mailer($mailerName)
                ->to($email)
                ->send(new InvoiceDocumentEmail(
                    klienNama: $klien->resolveDisplayLabel(),
                    documents: $documents,
                    fromEmail: $picUser->resolveSmtpFromEmail(),
                    fromName: $picUser->name,
                ));

            return ['target' => $email, 'label' => $label, 'success' => true, 'detail' => null];
        } catch (Throwable $e) {
            Log::warning('Kirim email klien AR gagal', [
                'klien_ar_id' => $klien->id,
                'email'       => $email,
                'error'       => $e->getMessage(),
            ]);

            return $this->skip($label, 'Gagal mengirim email: ' . $e->getMessage(), $email);
        }
    }

    private function skip(string $label, string $reason, ?string $target = null): array
    {
        return ['target' => $target, 'label' => $label, 'success' => false, 'detail' => $reason];
    }

    /**
     * Jaring pengaman kalau handle() tidak sempat sampai ke finally-nya sendiri
     * (mis. proses worker mati total) — tanpa ini, lock "batch aktif" milik user
     * bisa nyangkut sampai TTL-nya habis.
     */
    public function failed(?Throwable $exception): void
    {
        $cache = app(EmailBlastCacheService::class);
        $cache->update($this->batchId, [
            'status'  => 'failed',
            'message' => 'Job kirim email gagal: ' . $exception?->getMessage(),
        ]);
        $cache->clearActive($this->userId, $this->batchId);
    }
}
