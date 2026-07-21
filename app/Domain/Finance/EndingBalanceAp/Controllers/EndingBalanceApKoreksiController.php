<?php

namespace App\Domain\Finance\EndingBalanceAp\Controllers;

use App\Domain\Finance\EndingBalanceAp\Services\EndingBalanceApKoreksiService;
use App\Http\Controllers\Controller;
use App\Models\EndingBalanceAp;
use App\Models\EndingBalanceApKoreksi;
use App\Models\TagihanAp;
use App\Models\TagihanApItem;
use App\Support\Helpers\RoleHelper;
use App\Support\Helpers\SignatureBarcodeHelper;
use App\Support\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndingBalanceApKoreksiController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EndingBalanceApKoreksiService $service) {}

    /**
     * Submit koreksi baru untuk satu ending balance AP.
     */
    public function store(Request $request, int $ebId): JsonResponse
    {
        $this->authorizeOperate();

        $eb = EndingBalanceAp::findOrFail($ebId);

        $data = $request->validate([
            'tipe'           => ['required', 'string', 'in:KOREKSI_SALDO,CREDIT_NOTE,DEBIT_NOTE'],
            'alasan_koreksi' => ['required', 'string', 'max:1000'],
            'dokumen_url'    => ['nullable', 'string', 'max:500'],
            'tagihan_ap_id'  => ['nullable', 'integer', 'exists:tb_tagihan_ap,id'],

            'nilai_koreksi'  => ['nullable', 'numeric', 'not_in:0'],

            'items'                            => ['nullable', 'array', 'min:1'],
            'items.*.tagihan_ap_item_id'       => ['required_with:items', 'integer', 'exists:tb_tagihan_ap_item,id'],
            'items.*.qty_baru'                 => ['required_with:items', 'numeric', 'min:0'],
            'items.*.harga_satuan_baru'        => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $tipe = $data['tipe'];

        if (in_array($tipe, ['CREDIT_NOTE', 'DEBIT_NOTE'])) {
            abort_if(empty($data['tagihan_ap_id']), 422, 'Tagihan wajib dipilih untuk ' . $tipe . '.');
            $this->validateTagihanBelongToEb($eb, (int) $data['tagihan_ap_id']);

            $hasItems = !empty($data['items']);
            if ($hasItems) {
                $this->validateKoreksiQtyHarga($eb, $data['tagihan_ap_id'], $data['items']);
            } else {
                abort_if(empty($data['nilai_koreksi']), 422, 'Nilai koreksi wajib diisi.');
                if ($tipe === 'CREDIT_NOTE') {
                    abort_if((float) $data['nilai_koreksi'] >= 0, 422, 'Credit Note harus bernilai negatif (pengurangan hutang).');
                } else {
                    abort_if((float) $data['nilai_koreksi'] <= 0, 422, 'Debit Note harus bernilai positif (penambahan hutang).');
                }
            }
        } else {
            // KOREKSI_SALDO
            abort_if(empty($data['nilai_koreksi']), 422, 'Nilai koreksi wajib diisi.');
        }

        $koreksi = $this->service->submit($eb, $data, auth()->id());

        return $this->createdResponse(
            $this->formatKoreksi($koreksi),
            'Koreksi berhasil diajukan dan menunggu persetujuan Manager/Supervisor.'
        );
    }

    /**
     * Cetak Credit Note / Debit Note sebagai PDF (buka di tab baru).
     */
    public function printDocument(Request $request, int $id): mixed
    {
        $this->authorizeOperate();

        $koreksi = EndingBalanceApKoreksi::with([
            'endingBalanceAp.vendorAp',
            'endingBalanceAp.perusahaan',
            'tagihanAp.perusahaan',
            'tagihanAp.karyawan.perusahaan',
            'tagihanAp.vendorAp',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
            'items',
        ])->findOrFail($id);

        abort_unless(
            in_array($koreksi->tipe, ['CREDIT_NOTE', 'DEBIT_NOTE']),
            422,
            'Hanya Credit Note dan Debit Note yang dapat dicetak.'
        );

        $tagihan       = $koreksi->tagihanAp;
        $vendor        = $koreksi->endingBalanceAp?->vendorAp;
        $signatureData = $this->buildKoreksiSignatureData($koreksi);
        $tipeLabel     = $koreksi->tipe === 'CREDIT_NOTE' ? 'CreditNote' : 'DebitNote';
        $filename      = $tipeLabel . '-' . $koreksi->no_dokumen . '.pdf';

        if ($request->has('html')) {
            return view('ap.credit-note-print', compact('koreksi', 'tagihan', 'vendor', 'signatureData'))->render();
        }

        return Pdf::loadView('ap.credit-note-print', compact('koreksi', 'tagihan', 'vendor', 'signatureData'))
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled'      => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont'          => 'Arial',
                'dpi'                  => 150,
            ])
            ->stream($filename);
    }

    /**
     * Manager/Supervisor/Admin approve.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $data    = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $koreksi = EndingBalanceApKoreksi::with('endingBalanceAp')->findOrFail($id);
        $updated = $this->service->approve($koreksi, $data['note'] ?? null, auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi disetujui. Saldo akhir final telah diperbarui.'
        );
    }

    /**
     * Manager/Supervisor/Admin reject.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeApprove();

        $data    = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $koreksi = EndingBalanceApKoreksi::findOrFail($id);
        $updated = $this->service->reject($koreksi, $data['note'], auth()->id());

        return $this->successResponse(
            $this->formatKoreksi($updated),
            'Koreksi ditolak.'
        );
    }

    /**
     * Inbox: koreksi yang menunggu aksi dari user yang sedang login.
     */
    public function pending(): JsonResponse
    {
        $user  = auth()->user();
        $roles = $user->getRoleNames()->map(fn($r) => strtoupper($r));
        $role  = $roles->first(fn($r) => in_array($r, ['MANAGER', 'SUPERVISOR', 'ADMIN']));

        $list = $this->service->pendingForUser($role ?? '');

        return $this->successResponse(
            $list->map(fn($k) => $this->formatKoreksi($k))->values()->all()
        );
    }

    /**
     * Semua koreksi yang sudah APPROVED (paginated).
     */
    public function approved(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->service->approved(
            $request->integer('per_page', 20),
            $request->integer('page', 1),
        );

        return $this->successResponse([
            'rows' => $paginator->getCollection()->map(fn($k) => $this->formatKoreksi($k))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function buildKoreksiSignatureData(EndingBalanceApKoreksi $koreksi): array
    {
        $preparedName    = $koreksi->submittedBy?->name ?? $koreksi->submittedBy?->username ?? '___';
        $preparedPayload = SignatureBarcodeHelper::buildKoreksiApPreparedPayload($koreksi, $preparedName);
        $preparedQr      = SignatureBarcodeHelper::generateDataUri($preparedPayload, 250);

        $approvedQr   = null;
        $approvedName = null;
        if ($koreksi->approvedBy && $koreksi->status === 'APPROVED') {
            $approvedName    = $koreksi->approvedBy->name ?? $koreksi->approvedBy->username ?? '___';
            $approvedPayload = SignatureBarcodeHelper::buildKoreksiApApprovedPayload($koreksi, $approvedName);
            $approvedQr      = SignatureBarcodeHelper::generateDataUri($approvedPayload, 250);
        }

        return [
            'prepared_by_name' => $preparedName,
            'prepared_qr_src'  => $preparedQr,
            'approved_by_name' => $approvedName,
            'approved_qr_src'  => $approvedQr,
            'received_by_name' => $koreksi->endingBalanceAp?->vendorAp?->nama_vendor ?? '___',
        ];
    }

    private function formatKoreksi(EndingBalanceApKoreksi $k): array
    {
        return [
            'id'                   => $k->id,
            'ending_balance_ap_id' => $k->ending_balance_ap_id,
            'vendor_ap_id'         => $k->vendor_ap_id,
            'tagihan_ap_id'        => $k->tagihan_ap_id,
            'no_tagihan'           => $k->tagihanAp?->no_tagihan,
            'tipe'                 => $k->tipe,
            'no_dokumen'           => $k->no_dokumen,
            'nama_vendor'          => $k->vendorAp?->nama_vendor ?? $k->endingBalanceAp?->vendorAp?->nama_vendor,
            'nilai_koreksi'        => (float) $k->nilai_koreksi,
            'saldo_sebelum'        => (float) ($k->endingBalanceAp?->saldo_akhir_sistem ?? 0),
            'saldo_sesudah'        => (float) ($k->endingBalanceAp?->saldo_akhir_final  ?? 0),
            'alasan_koreksi'       => $k->alasan_koreksi,
            'dokumen_url'          => $k->dokumen_url,
            'status'               => $k->status,
            'submitted_by'         => $k->submittedBy?->username,
            'submitted_at'         => $k->submitted_at?->toIso8601String(),
            'approved_by'          => $k->approvedBy?->username,
            'approved_note'        => $k->approved_note,
            'approved_at'          => $k->approved_at?->toIso8601String(),
            'items'                => $k->relationLoaded('items')
                ? $k->items->map(fn($i) => [
                    'id'                 => $i->id,
                    'tagihan_ap_item_id' => $i->tagihan_ap_item_id,
                    'nama_barang'        => $i->nama_barang,
                    'qty_lama'           => (float) $i->qty_lama,
                    'harga_satuan_lama'  => (float) $i->harga_satuan_lama,
                    'subtotal_lama'      => (float) $i->subtotal_lama,
                    'qty_baru'           => (float) $i->qty_baru,
                    'harga_satuan_baru'  => (float) $i->harga_satuan_baru,
                    'subtotal_baru'      => (float) $i->subtotal_baru,
                    'selisih'            => (float) $i->selisih,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * Validasi bahwa tagihan milik vendor EB dan dalam periode EB.
     */
    private function validateTagihanBelongToEb(EndingBalanceAp $eb, int $tagihanId): void
    {
        $tagihan = TagihanAp::findOrFail($tagihanId);

        abort_if(
            (int) $tagihan->vendor_ap_id !== (int) $eb->vendor_ap_id,
            422,
            'Tagihan yang dipilih bukan milik vendor ending balance ini.'
        );

        $tgl = $tagihan->tanggal_tagihan?->toDateString();
        abort_if(
            !$tgl || $tgl < $eb->periode_awal->toDateString() || $tgl > $eb->periode_akhir->toDateString(),
            422,
            'Tagihan yang dipilih berada di luar periode ending balance ini.'
        );
    }

    /**
     * Validasi item koreksi qty/harga: tagihan dan items harus milik EB yang sama.
     */
    private function validateKoreksiQtyHarga(EndingBalanceAp $eb, ?int $tagihanId, array $items): void
    {
        abort_if(empty($tagihanId), 422, 'Tagihan wajib dipilih untuk koreksi qty/harga.');

        $this->validateTagihanBelongToEb($eb, $tagihanId);

        foreach ($items as $idx => $item) {
            $tagihanItem = TagihanApItem::find($item['tagihan_ap_item_id']);
            abort_if(!$tagihanItem, 422, "Item ke-" . ($idx + 1) . " tidak ditemukan.");
            abort_if(
                (int) $tagihanItem->tagihan_ap_id !== (int) $tagihanId,
                422,
                "Item '{$tagihanItem->nama_barang}' bukan bagian dari tagihan yang dipilih."
            );
        }
    }

    private function authorizeOperate(): void
    {
        abort_if(
            !RoleHelper::canOperateEndingBalanceAp(auth()->user()),
            403, 'Tidak memiliki akses untuk mengajukan koreksi.'
        );
    }

    private function authorizeApprove(): void
    {
        abort_if(
            !RoleHelper::canApproveEndingBalanceAp(auth()->user()),
            403, 'Hanya Manager/Supervisor yang dapat melakukan aksi ini.'
        );
    }
}
