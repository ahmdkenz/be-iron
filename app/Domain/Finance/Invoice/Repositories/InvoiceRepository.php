<?php

namespace App\Domain\Finance\Invoice\Repositories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class InvoiceRepository
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters(
            Invoice::with([
                'klienAr',
                'perusahaan',
                'karyawan',
                'createdBy',
                'updatedBy',
            ]),
            $filters
        )
            ->latest('tanggal_invoice')
            ->paginate($perPage);
    }

    public function findById(int $id): ?Invoice
    {
        return Invoice::with([
            'klienAr.perusahaan',
            'klienAr.karyawanAr',
            'perusahaan',
            'karyawan',
            'items.barang',
            'pembayarans.createdBy',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'approvalLogs.actor',
        ])->find($id);
    }

    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice->update($data);
        return $invoice->fresh([
            'klienAr',
            'perusahaan',
            'karyawan',
            'items',
            'pembayarans',
            'createdBy',
            'submittedBy',
            'approvedBy',
            'rejectedBy',
            'updatedBy',
            'approvalLogs.actor',
        ]);
    }

    public function delete(Invoice $invoice): bool
    {
        return (bool) $invoice->forceDelete();
    }

    public function getSummary(array $filters = []): array
    {
        $result = $this->applyFilters(Invoice::query(), $filters)
            ->selectRaw('
                COUNT(*) as total_invoice,
                COALESCE(SUM(total_tagihan), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(sisa_tagihan), 0) as total_sisa
            ')
            ->first();

        return [
            'total_invoice'    => (int) ($result?->total_invoice ?? 0),
            'total_tagihan'    => (float) ($result?->total_tagihan ?? 0),
            'total_pembayaran' => (float) ($result?->total_pembayaran ?? 0),
            'total_sisa'       => (float) ($result?->total_sisa ?? 0),
        ];
    }

    public function getRekapKlien(array $filters = []): array
    {
        $rows = $this->applyFilters(Invoice::query()->with('klienAr.perusahaan'), $filters)
            ->selectRaw('
                klien_ar_id,
                COUNT(*) as total_invoice,
                COALESCE(SUM(total_tagihan), 0) as total_tagihan,
                COALESCE(SUM(total_pembayaran), 0) as total_pembayaran,
                COALESCE(SUM(sisa_tagihan), 0) as sisa_tagihan,
                SUM(CASE WHEN status = "DRAFT"    THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = "TERKIRIM" THEN 1 ELSE 0 END) as terkirim,
                SUM(CASE WHEN status = "SEBAGIAN" THEN 1 ELSE 0 END) as sebagian,
                SUM(CASE WHEN status = "LUNAS"    THEN 1 ELSE 0 END) as lunas
            ')
            ->groupBy('klien_ar_id')
            ->get();

        return $rows->map(function ($row) {
            $klien = $row->klienAr;
            return [
                'klien_id'        => $klien?->id,
                'kode_klien'      => $klien?->kode_klien,
                'nama_klien'      => $klien?->nama_klien,
                'perusahaan'      => $klien?->perusahaan?->nama_singkatan_perusahaan,
                'total_invoice'   => (int) $row->total_invoice,
                'total_tagihan'   => (float) $row->total_tagihan,
                'total_pembayaran'=> (float) $row->total_pembayaran,
                'sisa_tagihan'    => (float) $row->sisa_tagihan,
                'draft'           => (int) $row->draft,
                'terkirim'        => (int) $row->terkirim,
                'sebagian'        => (int) $row->sebagian,
                'lunas'           => (int) $row->lunas,
            ];
        })->values()->all();
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(array_key_exists('is_opening_balance', $filters), fn($q) =>
                $q->where('is_opening_balance', $filters['is_opening_balance'])
            )
            ->when($filters['search'] ?? null, fn($q, $v) => $q->where(fn($q) => $q
                ->where('no_invoice', 'like', "%{$v}%")
                ->orWhereHas('klienAr', fn($q) => $q
                    ->where('nama_klien', 'like', "%{$v}%")
                    ->orWhere('kode_klien', 'like', "%{$v}%")
                )
            ))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when($filters['klien_ar_id'] ?? null, fn($q, $v) => $q->where('klien_ar_id', $v))
            ->when($filters['karyawan_id'] ?? null, fn($q, $v) => $q->where('karyawan_id', $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['approval_status'] ?? null, fn($q, $v) => $q->where('approval_status', $v))
            ->when($filters['periode_bulan'] ?? null, fn($q, $v) =>
                $q->whereMonth('tanggal_invoice', $v)
            )
            ->when($filters['periode_tahun'] ?? null, fn($q, $v) =>
                $q->whereYear('tanggal_invoice', $v)
            );
    }
}
