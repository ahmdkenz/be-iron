<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Email berisi tautan (signed URL, sama seperti yang dipakai pesan WA) ke
 * Invoice reguler dan/atau Opening Balance milik satu klien AR. Dikirim
 * sinkron dari dalam SendKlienArEmailBatchJob (bukan Mailable::queue())
 * supaya kegagalan per-penerima bisa ditangkap & dicatat ke progres batch.
 */
class InvoiceDocumentEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{no_invoice:string,is_opening_balance:bool,subtotal:float,share_url:string}> $documents
     */
    public function __construct(
        public readonly string $klienNama,
        public readonly array $documents,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {}

    public function build(): self
    {
        $label = count($this->documents) === 1
            ? ($this->documents[0]['is_opening_balance'] ? 'Opening Balance' : 'Invoice')
            : 'Tagihan';

        return $this
            ->from($this->fromEmail, $this->fromName)
            ->subject("{$label} - {$this->klienNama}")
            ->view('emails.invoice-document')
            ->with([
                'klienNama' => $this->klienNama,
                'documents' => $this->documents,
            ]);
    }
}
