<?php

namespace App\Domain\Finance\EndingBalance\Controllers;

use App\Domain\Finance\EndingBalance\Services\EndingBalanceKoreksiService;
use App\Http\Controllers\Controller;
use App\Models\EndingBalance;
use App\Models\EndingBalanceKoreksi;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndingBalanceKoreksiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EndingBalanceKoreksiService $service) {}

    /**
     * Submit koreksi baru untuk satu ending balance.
     */
    public function store(Request $request, int $ebId): JsonResponse
    {
        $this->authorizeOperate();

        $eb = EndingBalance::findOrFail($ebId);

        $data = $request->validate([
            'tipe'           => ['required', 'string', 'in:KOREKSI_SALDO,CREDIT_NOTE,DEBIT_NOTE,KOREKSI_QTY_HARGA'],
            'alasan_koreksi' => ['required', 'string', 'max:1000'],
            'dokumen_url'    => ['nullable', 'string', 'max:500'],
            'invoice_id'     => ['nullable', 'integer', 'exists:tb_invoice,id'],

            // Untuk CREDIT_NOTE / DEBIT_NOTE / KOREKSI_SALDO
            'nilai_koreksi'  => ['nullable', 'numeric', 'not_in:0'],

            // Untuk KOREKSI_QTY_HARGA
            'items'                        => ['nullable', 'array', 'min:1'],
            'items.*.invoice_item_id'      => ['required_with:items', 'integer', 'exists:tb_invoice_item,id'],
            'items.*.qty_baru'             => ['required_with:items', 'numeric', 'min:0'],
            'items.*.harga_satuan_baru'    => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $tipe = $data['tipe'];

        if ($tipe === 'KOREKSI_QTY_HARGA') {
            abort_if(empty($data['items']), 422, 'Items wajib diisi untuk koreksi qty/harga.');
            $this->validateKoreksiQtyHarga($eb, $data['invoice_id'], $data['items']);
        } elseif (in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])) {
            abort_if(empty($data['invoice_id']), 422, 'Invoice wajib dipilih untuk ' . $tipe . '.');
            abort_if(empty($data['nilai_koreksi']), 422, 'Nilai koreksi wajib diisi.');

            if ($tipe === 'CREDIT_NOTE') {
                abort_if((float) $data['nilai_koreksi'] >= 0, 422, 'Credit Note harus bernilai negatif (pengurangan tagihan).');
                $this->validateInvoiceBelongToEb($eb, (int) $data['invoice_id']);
            } else {
                abort_if((float) $data['nilai_koreksi'] <= 0, 422, 'Debit Note harus bernilai positif (penambahan tagihan).');
                $this->validateInvoiceBelongToEb($eb, (int) $data['invoice_id']);
            }
        } else {
            // KOREKSI_SALDO
            abort_if(empty($data['nilai_koreksi']), 422, 'Nilai koreksi wajib diisi.');
        }

        $koreksi = $this->service->submit($eb, $data, auth()->id());

        return $this->createdResponse(
            $this->formatKoreksi($koreksi),
            'Koreksi berhasil diajukan dan menunggu persetujuan SPV.'
        );
    }

    /**
     * Kembalikan data koreksi untuk dicetak (hanya CN dan DN).
     */
    public function printDocument(int $id): JsonResponse
    {
        $this->authorizeOperate();

        $koreksi = EndingBalanceKoreksi::with([
            'endingBalance.klienAr',
            'invoice',
            'submittedBy',
            'spv',
            'manager',
        ])->findOrFail($id);

        abort_unless(
            in_array($koreksi->tipe, ['CREDIT_NOTE', 'DEBIT_NOTE']),
            422,
            'Hanya Credit Note dan Debit Note yang dapat dicetak.'
        );

        $eb     = $koreksi->endingBalance;
        $klien  = $eb->klienAr;
        $inv    = $koreksi->invoice;

        return $this->successResponse([
            'no_dokumen'       => $koreksi->no_dokumen,
            'tipe'             => $koreksi->tipe,
            'tipe_label'       => $koreksi->tipe === 'CREDIT_NOTE' ? 'CREDIT NOTE' : 'DEBIT NOTE',
            'tanggal'          => $koreksi->manager_actioned_at?->toDateString() ?? $koreksi->submitted_at?->toDateString(),
            'klien'            => [
                'nama'    => $klien?->nama_klien,
                'kode'    => $klien?->kode_klien,
                'no_wa'   => $klien?->no_wa,
                'no_npwp' => $klien?->no_npwp,
            ],
            'invoice'          => [
                'no_invoice'      => $inv?->no_invoice,
                'tanggal_invoice' => $inv?->tanggal_invoice?->toDateString(),
                'total_tagihan'   => (float) ($inv?->subtotal ?? $inv?->total_tagihan ?? 0),
            ],
            'nilai_koreksi'    => abs((float) $koreksi->nilai_koreksi),
            'alasan_koreksi'   => $koreksi->alasan_koreksi,
            'dokumen_url'      => $koreksi->dokumen_url,
            'status'           => $koreksi->status,
            'submitted_by'     => $koreksi->submittedBy?->name ?? $koreksi->submittedBy?->username,
            'spv_name'         => $koreksi->spv?->name ?? $koreksi->spv?->username,
            'spv_actioned_at'  => $koreksi->spv_actioned_at?->toDateString(),
            'manager_name'     => $koreksi->manager?->name ?? $koreksi->manager?->username,
            'manager_actioned_at' => $koreksi->manager_actioned_at?->toDateString(),
        ]);
    }

    /**
     * SPV approve.
     */
    public function approveSpv(Request $request, int $id): JsonResponse
    {
        $this->authorizeSpv();

        $data    = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::with('endingBalance')->findOrFail($id);
        $updated = $this->service->approveSpv($koreksi, $data['note'] ?? null, auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi disetujui oleh SPV, menunggu persetujuan Manager.'
        );
    }

    /**
     * SPV reject.
     */
    public function rejectSpv(Request $request, int $id): JsonResponse
    {
        $this->authorizeSpv();

        $data    = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::findOrFail($id);
        $updated = $this->service->rejectSpv($koreksi, $data['note'], auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi ditolak oleh SPV.'
        );
    }

    /**
     * Manager approve.
     */
    public function approveManager(Request $request, int $id): JsonResponse
    {
        $this->authorizeManager();

        $data    = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::with('endingBalance')->findOrFail($id);
        $updated = $this->service->approveManager($koreksi, $data['note'] ?? null, auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi disetujui oleh Manager. Saldo akhir final telah diperbarui.'
        );
    }

    /**
     * Manager reject.
     */
    public function rejectManager(Request $request, int $id): JsonResponse
    {
        $this->authorizeManager();

        $data    = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $koreksi = EndingBalanceKoreksi::findOrFail($id);
        $updated = $this->service->rejectManager($koreksi, $data['note'], auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi ditolak oleh Manager.'
        );
    }

    /**
     * Inbox: koreksi yang menunggu aksi dari user yang sedang login.
     */
    public function pending(): JsonResponse
    {
        $user  = auth()->user();
        $roles = $user->getRoleNames()->map(fn($r) => strtoupper($r));
        $role  = $roles->first(fn($r) => in_array($r, ['SUPERVISOR', 'MANAGER']));

        $list = $this->service->pendingForUser($role ?? '');

        return $this->successResponse(
            $list->map(fn($k) => $this->formatKoreksi($k))->values()->all()
        );
    }

    /**
     * Semua koreksi yang sudah APPROVED.
     */
    public function approved(): JsonResponse
    {
        $list = $this->service->approved();

        return $this->successResponse(
            $list->map(fn($k) => $this->formatKoreksi($k))->values()->all()
        );
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function formatKoreksi(EndingBalanceKoreksi $k): array
    {
        $tipeKlien = $k->klienAr?->tipe_klien ?? $k->endingBalance?->klienAr?->tipe_klien;

        return [
            'id'                  => $k->id,
            'ending_balance_id'   => $k->ending_balance_id,
            'klien_ar_id'         => $k->klien_ar_id,
            'invoice_id'          => $k->invoice_id,
            'no_invoice'          => $k->invoice?->no_invoice,
            'tipe'                => $k->tipe,
            'no_dokumen'          => $k->no_dokumen,
            'nama_klien'          => $k->klienAr?->nama_klien ?? $k->endingBalance?->klienAr?->nama_klien,
            'segment'             => match($tipeKlien) { 'PT' => 'B2B', 'RESTO' => 'B2C', default => 'B2B' },
            'nilai_koreksi'       => (float) $k->nilai_koreksi,
            'saldo_sebelum'       => (float) ($k->endingBalance?->saldo_akhir_sistem ?? 0),
            'saldo_sesudah'       => (float) ($k->endingBalance?->saldo_akhir_final  ?? 0),
            'alasan_koreksi'      => $k->alasan_koreksi,
            'dokumen_url'         => $k->dokumen_url,
            'status'              => $k->status,
            'submitted_by'        => $k->submittedBy?->username,
            'submitted_at'        => $k->submitted_at?->toIso8601String(),
            'spv'                 => $k->spv?->username,
            'spv_note'            => $k->spv_note,
            'spv_actioned_at'     => $k->spv_actioned_at?->toIso8601String(),
            'manager'             => $k->manager?->username,
            'manager_note'        => $k->manager_note,
            'manager_actioned_at' => $k->manager_actioned_at?->toIso8601String(),
            'items'               => $k->relationLoaded('items')
                ? $k->items->map(fn($i) => [
                    'id'                => $i->id,
                    'invoice_item_id'   => $i->invoice_item_id,
                    'nama_barang'       => $i->nama_barang,
                    'qty_lama'          => (float) $i->qty_lama,
                    'harga_satuan_lama' => (float) $i->harga_satuan_lama,
                    'subtotal_lama'     => (float) $i->subtotal_lama,
                    'qty_baru'          => (float) $i->qty_baru,
                    'harga_satuan_baru' => (float) $i->harga_satuan_baru,
                    'subtotal_baru'     => (float) $i->subtotal_baru,
                    'selisih'           => (float) $i->selisih,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Validasi bahwa invoice milik klien EB dan dalam periode EB.
     */
    private function validateInvoiceBelongToEb(EndingBalance $eb, int $invoiceId): void
    {
        $invoice = Invoice::findOrFail($invoiceId);

        abort_if(
            (int) $invoice->klien_ar_id !== (int) $eb->klien_ar_id,
            422,
            'Invoice yang dipilih bukan milik klien ending balance ini.'
        );

        $tgl = $invoice->tanggal_invoice?->toDateString();
        abort_if(
            !$tgl || $tgl < $eb->periode_awal->toDateString() || $tgl > $eb->periode_akhir->toDateString(),
            422,
            'Invoice yang dipilih berada di luar periode ending balance ini.'
        );
    }

    /**
     * Validasi item koreksi qty/harga: invoice dan items harus milik EB yang sama.
     */
    private function validateKoreksiQtyHarga(EndingBalance $eb, ?int $invoiceId, array $items): void
    {
        abort_if(empty($invoiceId), 422, 'Invoice wajib dipilih untuk koreksi qty/harga.');

        $this->validateInvoiceBelongToEb($eb, $invoiceId);

        foreach ($items as $idx => $item) {
            $invoiceItem = InvoiceItem::find($item['invoice_item_id']);
            abort_if(!$invoiceItem, 422, "Item ke-" . ($idx + 1) . " tidak ditemukan.");
            abort_if(
                (int) $invoiceItem->invoice_id !== (int) $invoiceId,
                422,
                "Item '{$invoiceItem->nama_barang}' bukan bagian dari invoice yang dipilih."
            );
        }
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateEndingBalance(auth()->user()),
            403, 'Tidak memiliki akses untuk mengajukan koreksi.'
        );
    }

    private function authorizeSpv(): void
    {
        abort_if(
            !RoleHelper::canApproveEndingBalanceSpv(auth()->user()),
            403, 'Hanya SPV yang dapat melakukan aksi ini.'
        );
    }

    private function authorizeManager(): void
    {
        abort_if(
            !RoleHelper::canApproveEndingBalanceManager(auth()->user()),
            403, 'Hanya Manager yang dapat melakukan aksi ini.'
        );
    }
}
