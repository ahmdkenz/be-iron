<?php

namespace App\Domain\Finance\RekeningKoran\Services;

use App\Models\BankStatementDetail;
use App\Models\User;
use Carbon\Carbon;

class RekeningKoranUmumService
{
    public function getReport(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $query = BankStatementDetail::query()
            ->with([
                'bankStatement:id,bank_type,nama_file',
                'matchedBy:id,username,karyawan_id',
                'matchedBy.karyawan:id,nama_karyawan',
                'postedBy:id,username,karyawan_id',
                'postedBy.karyawan:id,nama_karyawan',
                'pembayaranAr:id,invoice_id,jumlah_pembayaran,no_referensi',
                'pembayaranAr.invoice:id,no_invoice,klien_ar_id',
                'pembayaranAr.invoice.klienAr:id,nama_klien,kode_klien',
            ])
            ->join('tb_bank_statement', 'tb_bank_statement_detail.bank_statement_id', '=', 'tb_bank_statement.id')
            ->select('tb_bank_statement_detail.*');

        // Filter periode berdasarkan tanggal transaksi bank
        if (!empty($filters['periode_awal'])) {
            $query->where('tb_bank_statement_detail.tanggal', '>=', Carbon::parse($filters['periode_awal'])->toDateString());
        }
        if (!empty($filters['periode_akhir'])) {
            $query->where('tb_bank_statement_detail.tanggal', '<=', Carbon::parse($filters['periode_akhir'])->toDateString());
        }

        // Filter bank type
        if (!empty($filters['bank_type'])) {
            $query->where('tb_bank_statement.bank_type', $filters['bank_type']);
        }

        // Filter PIC AR (user yang melakukan match)
        if (!empty($filters['pic_ar_id'])) {
            $query->where('tb_bank_statement_detail.matched_by', $filters['pic_ar_id']);
        }

        // Filter status rekonsiliasi (STATUS POSTING 1)
        if (!empty($filters['status_posting_1'])) {
            $query->where('tb_bank_statement_detail.status_cocok', $filters['status_posting_1']);
        }

        // Filter status posting jurnal (STATUS POSTING 2)
        if (!empty($filters['status_posting_2'])) {
            $query->where('tb_bank_statement_detail.status_posting_2', $filters['status_posting_2']);
        }

        $query->orderBy('tb_bank_statement_detail.tanggal', 'asc')
              ->orderBy('tb_bank_statement_detail.id', 'asc');

        // Summary sebelum paginate
        $summaryQuery = clone $query;
        $totalTransaksi   = $summaryQuery->count();
        $totalMatched     = (clone $query)->where('tb_bank_statement_detail.status_cocok', 'MATCHED')->count();
        $totalUnmatched   = (clone $query)->where('tb_bank_statement_detail.status_cocok', 'UNMATCHED')->count();
        $totalPosted      = (clone $query)->where('tb_bank_statement_detail.status_posting_2', 'POSTED')->count();
        $totalPending     = (clone $query)->where('tb_bank_statement_detail.status_posting_2', 'PENDING')->count();
        $totalMutasiMasuk = (clone $query)->sum('tb_bank_statement_detail.kredit');

        // Paginate
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginated->map(function (BankStatementDetail $detail) {
            $mutasi    = $detail->kredit > 0 ? $detail->kredit : $detail->debit;
            $dk        = $detail->kredit > 0 ? 'K' : 'D';
            $noArRef   = null;
            $selisih   = 0;

            if ($detail->pembayaranAr) {
                $noArRef = $detail->pembayaranAr->invoice?->no_invoice;
                $selisih = $mutasi - (float) $detail->pembayaranAr->jumlah_pembayaran;
            }

            return [
                'id'               => $detail->id,
                'tanggal'          => $detail->tanggal?->toDateString(),
                'waktu_transaksi'  => $detail->waktu_transaksi,
                'bank_type'        => $detail->bankStatement?->bank_type,
                'no_referensi'     => $detail->no_referensi,
                'dk'               => $dk,
                'mutasi'           => $mutasi,
                'saldo'            => $detail->saldo,
                'deskripsi'        => $detail->pembayaranAr?->keterangan,
                'keterangan'       => $detail->keterangan,
                'status_posting_1' => $detail->status_cocok,
                'no_dokumen_ar'    => $noArRef,
                'selisih'          => $selisih,
                'status_posting_2' => $detail->status_posting_2 ?? 'PENDING',
                'pic_ar'           => $detail->matchedBy?->name,
                'posted_by'        => $detail->postedBy?->name,
                'posted_at'        => $detail->posted_at?->toDateTimeString(),
            ];
        })->values()->all();

        return [
            'rows'               => $rows,
            'total_transaksi'    => $totalTransaksi,
            'total_matched'      => $totalMatched,
            'total_unmatched'    => $totalUnmatched,
            'total_posted'       => $totalPosted,
            'total_pending'      => $totalPending,
            'total_mutasi_masuk' => (float) $totalMutasiMasuk,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ];
    }

    public function getPicArList(): array
    {
        return User::query()
            ->with('karyawan:id,nama_karyawan')
            ->whereHas('roles', fn($q) => $q->whereIn('name', ['AR', 'SUPERVISOR', 'DIREKTUR']))
            ->orderBy('username')
            ->get(['id', 'username', 'karyawan_id'])
            ->map(fn($u) => [
                'id'   => $u->id,
                'name' => $u->name,
            ])
            ->toArray();
    }
}
