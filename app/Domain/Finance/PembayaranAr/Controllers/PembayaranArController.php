<?php

namespace App\Domain\Finance\PembayaranAr\Controllers;

use App\Domain\Finance\Invoice\Resources\InvoiceResource;
use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\PembayaranAr\Requests\StorePembayaranArRequest;
use App\Domain\Finance\PembayaranAr\Resources\PembayaranArResource;
use App\Domain\Finance\PembayaranAr\Services\PembayaranArService;
use App\Domain\Finance\PembayaranAr\Services\RiwayatPembayaranService;
use App\Domain\Notification\Services\FinanceNotificationService;
use App\Http\Controllers\Controller;
use App\Models\KlienAr;
use App\Models\PembayaranAr;
use App\Models\PendapatanDiMuka;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PembayaranArController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PembayaranArService $service,
        private readonly InvoiceService $invoiceService,
        private readonly RiwayatPembayaranService $riwayatService,
        private readonly FinanceNotificationService $financeNotificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $filters = $request->only([
            'klien_ar_id', 'karyawan_id', 'metode_pembayaran', 'tanggal_dari', 'tanggal_sampai', 'segment',
        ]);

        $totalJumlah = $this->riwayatService->totalJumlah($filters, $user);
        $list        = $this->riwayatService->paginate($filters, $user, (int) ($request->per_page ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data'    => $list->through(fn($p) => new PembayaranArResource($p))->items(),
            'meta'    => [
                'current_page' => $list->currentPage(),
                'last_page'    => $list->lastPage(),
                'per_page'     => $list->perPage(),
                'total'        => $list->total(),
                'total_jumlah' => $totalJumlah,
            ],
        ]);
    }

    public function store(StorePembayaranArRequest $request, int $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceService->findOrFail($invoiceId);

        $user = $request->user()->loadMissing('karyawan');
        if (!RoleHelper::hasGlobalArAccess($user) && $user->karyawan) {
            abort_if(
                $invoice->perusahaan_id !== $user->karyawan->perusahaan_id,
                403,
                'Anda tidak memiliki akses untuk mencatat pembayaran invoice ini.'
            );
        }

        // PIC AR (AR murni) hanya boleh mencatat pembayaran untuk invoice klien
        // yang di-assign ke mereka — mirip authorizeInvoiceOwnership() di
        // BankStatementController, sebelumnya endpoint ini tidak dibatasi sama
        // sekali di luar cek entitas di atas.
        $picArKaryawanId = RoleHelper::picArKaryawanIdFor($user);
        if ($picArKaryawanId !== null) {
            $klienKaryawanArId = $invoice->klienAr()->withTrashed()->value('karyawan_ar_id');
            abort_unless(
                $klienKaryawanArId !== null && (int) $klienKaryawanArId === $picArKaryawanId,
                403,
                'Anda tidak memiliki akses untuk mencatat pembayaran invoice klien ini.'
            );
        }

        $pembayaran = $this->service->create(
            $invoice,
            $request->validated(),
            $request->file('bukti_pembayaran'),
        );

        Log::channel('security')->info('Pembayaran dicatat', [
            'user_id'        => $user->id,
            'invoice_id'     => $invoice->id,
            'no_invoice'     => $invoice->no_invoice,
            'jumlah'         => $request->jumlah_pembayaran,
            'metode'         => $request->metode_pembayaran,
            'ip'             => $request->ip(),
        ]);

        return $this->createdResponse(
            new PembayaranArResource($pembayaran),
            'Pembayaran berhasil dicatat'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $pembayaran = PembayaranAr::with(['invoice.klienAr', 'items.invoice.klienAr'])->find($id);
        abort_if(!$pembayaran, 404, 'Data pembayaran tidak ditemukan');

        $this->authorizeDeleteOwnership($pembayaran, $request->user()->loadMissing('karyawan'));

        $noInvoice = $pembayaran->invoice?->no_invoice;
        $klienArId = $pembayaran->invoice?->klien_ar_id;

        $this->service->delete($pembayaran);

        $this->financeNotificationService->bankReconciliationAction(
            'batal_pembayaran_ar',
            'Pembayaran AR dibatalkan',
            sprintf('%s membatalkan pembayaran untuk invoice %s.', auth()->user()?->name ?? '-', $noInvoice ?? '-'),
            klienArId: $klienArId,
        );

        return $this->successResponse(null, 'Pembayaran berhasil dihapus');
    }

    /**
     * Hapus pembayaran sebelumnya tidak dibatasi sama sekali (bukan cuma FE-only
     * gap) — cek di sini mencakup 3 bentuk pembayaran AR: invoice tunggal
     * (invoice_id terisi), Multi Payment (invoice_id null, alokasi lewat items),
     * dan shell PDM murni (invoice_id null, tanpa items — ownership diturunkan
     * dari klien_ar_id milik PendapatanDiMuka yang menaunginya).
     */
    private function authorizeDeleteOwnership(PembayaranAr $pembayaran, $user): void
    {
        $invoices = $pembayaran->invoice
            ? collect([$pembayaran->invoice])
            : $pembayaran->items->map(fn($item) => $item->invoice)->filter()->unique('id')->values();

        if (!RoleHelper::hasGlobalArAccess($user) && $user->karyawan) {
            $luarEntitas = $invoices->contains(
                fn($inv) => (int) $inv->perusahaan_id !== (int) $user->karyawan->perusahaan_id
            );
            abort_if($luarEntitas, 403, 'Anda tidak memiliki akses untuk menghapus pembayaran ini.');
        }

        $picArKaryawanId = RoleHelper::picArKaryawanIdFor($user);
        if ($picArKaryawanId === null) {
            return;
        }

        if ($invoices->isNotEmpty()) {
            $luarScope = $invoices->contains(
                fn($inv) => (int) ($inv->klienAr?->karyawan_ar_id ?? 0) !== $picArKaryawanId
            );
            abort_if($luarScope, 403, 'Anda tidak memiliki akses untuk menghapus pembayaran klien ini.');

            return;
        }

        $klienArId = PendapatanDiMuka::where('sumber_pembayaran_ar_id', $pembayaran->id)->value('klien_ar_id');
        $ownerKaryawanArId = $klienArId
            ? KlienAr::withTrashed()->whereKey($klienArId)->value('karyawan_ar_id')
            : null;

        abort_if(
            $ownerKaryawanArId === null || (int) $ownerKaryawanArId !== $picArKaryawanId,
            403,
            'Anda tidak memiliki akses untuk menghapus pembayaran ini.'
        );
    }

    public function publicBukti(PembayaranAr $pembayaran): StreamedResponse
    {
        abort_if(!$pembayaran->bukti_path, 404);

        return Storage::disk($pembayaran->bukti_disk)->response(
            $pembayaran->bukti_path,
            $pembayaran->bukti_file_name,
            ['Content-Type' => $pembayaran->bukti_mime_type],
        );
    }

    public function cekReferensi(Request $request): JsonResponse
    {
        $request->validate([
            'no_referensi' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->service->cekDuplikatReferensi($request->no_referensi);

        return $this->successResponse($result);
    }
}
