<?php

namespace App\Domain\Finance\Invoice\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class InvoiceListResource extends JsonResource
{
    public function __construct($resource, private readonly array $ebLockedIds = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'no_invoice'                 => $this->no_invoice,
            'tanggal_invoice'            => $this->tanggal_invoice?->format('d-m-Y'),
            'klien_ar_id'                => $this->klien_ar_id,
            'klien_ar'                   => $this->whenLoaded('klienAr', fn() => $this->klienAr ? [
                'id'         => $this->klienAr->id,
                'kode_klien' => $this->klienAr->kode_klien,
                'nama_klien' => $this->klienAr->nama_klien,
                'no_wa'      => $this->klienAr->resolveContactPhone(),
            ] : null),
            'resto'                      => $this->whenLoaded('resto', fn() => $this->resto ? [
                'id'         => $this->resto->id,
                'kode_resto' => $this->resto->kode_resto,
                'nama_resto' => $this->resto->nama_resto,
            ] : null),
            'subtotal'                   => (float) $this->subtotal,
            'tagihan_periode_sebelumnya' => (float) $this->tagihan_periode_sebelumnya,
            'total_pembayaran'           => (float) $this->total_pembayaran,
            'total_penyesuaian'          => (float) $this->total_penyesuaian,
            'sisa_tagihan'               => (float) $this->sisa_tagihan,
            'status'                     => $this->status,
            'is_opening_balance'         => $this->is_opening_balance,
            'is_eb_locked'               => in_array($this->id, $this->ebLockedIds, true),
            'can_record_payment'         => $this->isApprovedForFinanceFlow(),
            'can_print'                  => $this->isApprovedForFinanceFlow(),
            'share_url'                  => $this->isApprovedForFinanceFlow() && $this->prepared_token
                ? URL::temporarySignedRoute(
                    'invoice.public-print', now()->addDays(30), ['token' => $this->prepared_token]
                )
                : null,
        ];
    }
}
