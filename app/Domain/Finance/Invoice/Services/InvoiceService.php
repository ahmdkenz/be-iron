<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Repositories\InvoiceRepository;
use App\Models\Invoice;
use App\Models\InvoiceApprovalLog;
use App\Models\KlienAr;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function findOrFail(int $id): Invoice
    {
        $invoice = $this->repository->findById($id);
        abort_if(!$invoice, 404, 'Invoice tidak ditemukan');
        return $invoice;
    }

    public function getSummary(array $filters = []): array
    {
        return $this->repository->getSummary($filters);
    }

    public function getCarryover(int $klienArId): float
    {
        $lastInvoice = Invoice::where('klien_ar_id', $klienArId)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where(function ($query) {
                $query
                    ->where('is_opening_balance', false)
                    ->orWhere(function ($openingBalanceQuery) {
                        $openingBalanceQuery
                            ->where('is_opening_balance', true)
                            ->where('approval_status', 'APPROVED');
                    });
            })
            ->latest('tanggal_invoice')
            ->first();

        return $lastInvoice ? (float) $lastInvoice->sisa_tagihan : 0.0;
    }

    public function generateNoInvoice(KlienAr $klien, string $tanggal): string
    {
        $segment = $this->resolveInvoiceSegment($klien);
        $date    = Carbon::parse($tanggal);
        $prefix  = 'SI-' . $segment . '-' . $date->format('dmY');
        $count   = Invoice::withTrashed()
            ->where('no_invoice', 'like', $prefix . '-%')
            ->count();
        $seq     = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return $prefix . '-' . $seq;
    }

    public function generateOpeningBalanceNoInvoice(KlienAr $klien, string $tanggal): string
    {
        $kode   = strtoupper($klien->kode_klien);
        $date   = Carbon::parse($tanggal);
        $prefix = 'OB-' . $kode . '-' . $date->format('dmy');

        $count = Invoice::where('no_invoice', 'like', $prefix . '%')->count();
        $seq   = str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        return $prefix . '-' . $seq;
    }

    public function create(InvoiceDTO $dto): Invoice
    {
        $klien    = KlienAr::findOrFail($dto->klien_ar_id);
        $carryover = $this->getCarryover($dto->klien_ar_id);
        $noInvoice = $this->generateNoInvoice($klien, $dto->tanggal_invoice);

        $subtotal = collect($dto->items)->sum(
            fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
        );
        $totalTagihan = $subtotal + $carryover;

        $invoice = $this->repository->create([
            'no_invoice'                 => $noInvoice,
            'tanggal_invoice'            => $dto->tanggal_invoice,
            'periode_awal'               => $dto->periode_awal,
            'periode_akhir'              => $dto->periode_akhir,
            'klien_ar_id'                => $dto->klien_ar_id,
            'perusahaan_id'              => $klien->perusahaan_id,
            'karyawan_id'                => auth()->user()->karyawan->id,
            'no_surat_jalan'             => $dto->no_surat_jalan,
            'subtotal'                   => $subtotal,
            'tagihan_periode_sebelumnya' => $carryover,
            'total_tagihan'              => $totalTagihan,
            'total_pembayaran'           => 0,
            'sisa_tagihan'               => $totalTagihan,
            'status'                     => $dto->status,
            'keterangan'                 => $dto->keterangan,
            'created_by'                 => auth()->id(),
        ]);

        foreach ($dto->items as $item) {
            $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
            $invoice->items()->create([
                'barang_id'    => $item['barang_id'] ?? null,
                'nama_barang'  => $item['nama_barang'],
                'qty'          => $item['qty'],
                'satuan'       => $item['satuan'] ?? null,
                'harga_satuan' => $item['harga_satuan'],
                'subtotal'     => $itemSubtotal,
                'keterangan'   => $item['keterangan'] ?? null,
            ]);
        }

        return $invoice->load([
            'klienAr',
            'perusahaan',
            'karyawan',
            'items.barang',
            'pembayarans',
        ]);
    }

    public function createOpeningBalance(array $data): Invoice
    {
        $user = auth()->user()->loadMissing('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        $klien = KlienAr::findOrFail($data['klien_ar_id']);
        $noInvoice = $this->generateOpeningBalanceNoInvoice($klien, $data['tanggal']);

        return DB::transaction(function () use ($data, $klien, $noInvoice, $user) {
            $invoice = $this->repository->create([
                'no_invoice'                 => $noInvoice,
                'tanggal_invoice'            => $data['tanggal'],
                'periode_awal'               => $data['periode_awal'],
                'periode_akhir'              => $data['periode_akhir'],
                'klien_ar_id'                => $data['klien_ar_id'],
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $user->karyawan->id,
                'subtotal'                   => $data['saldo_awal'],
                'tagihan_periode_sebelumnya' => 0,
                'total_tagihan'              => $data['saldo_awal'],
                'total_pembayaran'           => 0,
                'sisa_tagihan'               => $data['saldo_awal'],
                'status'                     => 'DRAFT',
                'approval_status'            => 'PENDING',
                'submitted_at'               => now(),
                'submitted_by'               => auth()->id(),
                'is_opening_balance'         => true,
                'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
                'created_by'                 => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'SUBMITTED');

            return $this->findOrFail($invoice->id);
        });
    }

    public function updateOpeningBalance(Invoice $invoice, array $data): Invoice
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !($invoice->status === 'DRAFT' && $invoice->approval_status === 'REJECTED'),
            422,
            'Opening balance hanya dapat diedit setelah ditolak'
        );

        $klien = KlienAr::findOrFail($data['klien_ar_id']);

        $invoice->update([
            'tanggal_invoice'            => $data['tanggal'],
            'periode_awal'               => $data['periode_awal'],
            'periode_akhir'              => $data['periode_akhir'],
            'klien_ar_id'                => $data['klien_ar_id'],
            'perusahaan_id'              => $klien->perusahaan_id,
            'subtotal'                   => $data['saldo_awal'],
            'total_tagihan'              => $data['saldo_awal'],
            'sisa_tagihan'               => $data['saldo_awal'] - $invoice->total_pembayaran,
            'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
            'updated_by'                 => auth()->id(),
        ]);

        return $this->findOrFail($invoice->id);
    }

    public function resubmitOpeningBalance(Invoice $invoice, ?string $note = null): Invoice
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !$invoice->canBeResubmitted(),
            422,
            'Opening balance hanya dapat diajukan ulang jika status approval ditolak'
        );

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'approval_status' => 'PENDING',
                'submitted_at'    => now(),
                'submitted_by'    => auth()->id(),
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => null,
                'rejected_by'     => null,
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'RESUBMITTED', $note);

            return $this->findOrFail($invoice->id);
        });
    }

    public function approveOpeningBalance(Invoice $invoice, ?string $note = null): Invoice
    {
        $this->ensurePendingOpeningBalance($invoice);

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'status'          => 'TERKIRIM',
                'approval_status' => 'APPROVED',
                'approved_at'     => now(),
                'approved_by'     => auth()->id(),
                'rejected_at'     => null,
                'rejected_by'     => null,
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'APPROVED', $note);

            return $this->findOrFail($invoice->id);
        });
    }

    public function rejectOpeningBalance(Invoice $invoice, string $note): Invoice
    {
        $this->ensurePendingOpeningBalance($invoice);

        return DB::transaction(function () use ($invoice, $note) {
            $invoice->update([
                'status'          => 'DRAFT',
                'approval_status' => 'REJECTED',
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => now(),
                'rejected_by'     => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'REJECTED', $note);

            return $this->findOrFail($invoice->id);
        });
    }

    public function update(Invoice $invoice, InvoiceDTO $dto): Invoice
    {
        abort_if(
            $invoice->status !== 'DRAFT',
            422,
            'Invoice hanya dapat diedit jika berstatus DRAFT'
        );

        $klien    = KlienAr::findOrFail($dto->klien_ar_id);
        $carryover = $invoice->tagihan_periode_sebelumnya;

        $subtotal     = collect($dto->items)->sum(
            fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
        );
        $totalTagihan = $subtotal + $carryover;

        $invoice->update([
            'tanggal_invoice'            => $dto->tanggal_invoice,
            'periode_awal'               => $dto->periode_awal,
            'periode_akhir'              => $dto->periode_akhir,
            'klien_ar_id'                => $dto->klien_ar_id,
            'perusahaan_id'              => $klien->perusahaan_id,
            'no_surat_jalan'             => $dto->no_surat_jalan,
            'subtotal'                   => $subtotal,
            'total_tagihan'              => $totalTagihan,
            'sisa_tagihan'               => $totalTagihan - $invoice->total_pembayaran,
            'keterangan'                 => $dto->keterangan,
            'updated_by'                 => auth()->id(),
        ]);

        // Replace all items
        $invoice->items()->delete();
        foreach ($dto->items as $item) {
            $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
            $invoice->items()->create([
                'barang_id'    => $item['barang_id'] ?? null,
                'nama_barang'  => $item['nama_barang'],
                'qty'          => $item['qty'],
                'satuan'       => $item['satuan'] ?? null,
                'harga_satuan' => $item['harga_satuan'],
                'subtotal'     => $itemSubtotal,
                'keterangan'   => $item['keterangan'] ?? null,
            ]);
        }

        return $invoice->fresh(['klienAr', 'perusahaan', 'karyawan', 'items.barang', 'pembayarans']);
    }

    public function changeStatus(Invoice $invoice, string $status): Invoice
    {
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, status piutang belum dapat diubah'
        );

        $allowedTransitions = [
            'DRAFT'    => ['TERKIRIM'],
            'TERKIRIM' => ['SEBAGIAN', 'LUNAS'],
            'SEBAGIAN' => ['LUNAS'],
            'LUNAS'    => [],
        ];

        abort_if(
            !in_array($status, $allowedTransitions[$invoice->status] ?? []),
            422,
            "Invoice tidak dapat diubah dari status {$invoice->status} ke {$status}"
        );

        $invoice->update(['status' => $status, 'updated_by' => auth()->id()]);
        return $invoice->fresh();
    }

    public function recalculate(Invoice $invoice): void
    {
        abort_if(
            $invoice->requiresApproval() && !$invoice->isApprovedForFinanceFlow(),
            422,
            'Opening balance belum disetujui, pembayaran belum dapat diproses'
        );

        $totalPembayaran = $invoice->pembayarans()->sum('jumlah_pembayaran');
        $sisaTagihan     = $invoice->total_tagihan - $totalPembayaran;

        $status = $invoice->status;
        if ($sisaTagihan <= 0) {
            $status      = 'LUNAS';
            $sisaTagihan = 0;
        } elseif ($totalPembayaran > 0) {
            $status = 'SEBAGIAN';
        }

        $invoice->update([
            'total_pembayaran' => $totalPembayaran,
            'sisa_tagihan'     => $sisaTagihan,
            'status'           => $status,
            'updated_by'       => auth()->id(),
        ]);
    }

    public function delete(Invoice $invoice): void
    {
        abort_if(
            $invoice->status !== 'DRAFT',
            422,
            'Hanya invoice berstatus DRAFT yang dapat dihapus'
        );
        $invoice->items()->delete();
        $this->repository->delete($invoice);
    }

    private function ensureOpeningBalance(Invoice $invoice): void
    {
        abort_if(
            !$invoice->is_opening_balance,
            422,
            'Data yang dipilih bukan opening balance'
        );
    }

    private function ensurePendingOpeningBalance(Invoice $invoice): void
    {
        $this->ensureOpeningBalance($invoice);

        abort_if(
            !($invoice->status === 'DRAFT' && $invoice->approval_status === 'PENDING'),
            422,
            'Opening balance tidak berada pada status menunggu persetujuan'
        );
    }

    private function createApprovalLog(Invoice $invoice, string $action, ?string $note = null): void
    {
        InvoiceApprovalLog::create([
            'invoice_id' => $invoice->id,
            'action'     => $action,
            'actor_id'   => auth()->id(),
            'note'       => $note,
        ]);
    }

    private function resolveInvoiceSegment(KlienAr $klien): string
    {
        return strtoupper($klien->tipe_klien) === 'RESTO' ? 'B2C' : 'B2B';
    }
}
