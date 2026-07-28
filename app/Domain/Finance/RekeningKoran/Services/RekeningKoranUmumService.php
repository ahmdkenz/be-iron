<?php

namespace App\Domain\Finance\RekeningKoran\Services;

use App\Models\BankStatementDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class RekeningKoranUmumService
{
    private const EAGER_LOADS = [
        'bankStatement:id,bank_type,nama_file',
        'matchedBy:id,username,karyawan_id',
        'matchedBy.karyawan:id,nama_karyawan',
        'postedBy:id,username,karyawan_id',
        'postedBy.karyawan:id,nama_karyawan',
        'pembayaranAr:id,invoice_id,jumlah_pembayaran,no_referensi',
        'pembayaranAr.invoice:id,no_invoice,klien_ar_id',
        'pembayaranAr.invoice.klienAr:id,nama_klien,kode_klien',
    ];

    public function getReport(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $query = $this->buildQuery($filters);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginated->map(fn(BankStatementDetail $detail) => $this->mapRow($detail))->values()->all();

        return array_merge($this->getSummary($filters), [
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    /**
     * Seluruh baris yang cocok dengan filter — tanpa paginasi (dipakai export).
     * Bentuk barisnya identik dengan getReport() supaya kolom export dan kolom
     * tabel di layar tidak pernah berbeda.
     */
    public function getAllRows(array $filters = []): array
    {
        return $this->buildQuery($filters)
            ->get()
            ->map(fn(BankStatementDetail $detail) => $this->mapRow($detail))
            ->values()
            ->all();
    }

    public function getSummary(array $filters = []): array
    {
        $base = fn() => $this->buildQuery($filters);

        return [
            'total_transaksi'    => $base()->count(),
            'total_matched'      => $base()->where('tb_bank_statement_detail.status_cocok', 'MATCHED')->count(),
            'total_unmatched'    => $base()->where('tb_bank_statement_detail.status_cocok', 'UNMATCHED')->count(),
            'total_posted'       => $base()->where('tb_bank_statement_detail.status_posting_2', 'POSTED')->count(),
            'total_pending'      => $base()->where('tb_bank_statement_detail.status_posting_2', 'PENDING')->count(),
            'total_mutasi_masuk' => (float) $base()->sum('tb_bank_statement_detail.kredit'),
        ];
    }

    private function buildQuery(array $filters): Builder
    {
        $query = BankStatementDetail::query()
            ->with(self::EAGER_LOADS)
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

        // Filter D/K
        if (!empty($filters['dk'])) {
            if ($filters['dk'] === 'K') {
                $query->where('tb_bank_statement_detail.kredit', '>', 0);
            } elseif ($filters['dk'] === 'D') {
                $query->where('tb_bank_statement_detail.debit', '>', 0);
            }
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

        return $query
            ->orderBy('tb_bank_statement_detail.tanggal', 'asc')
            ->orderBy('tb_bank_statement_detail.id', 'asc');
    }

    private function mapRow(BankStatementDetail $detail): array
    {
        $mutasi  = $detail->kredit > 0 ? $detail->kredit : $detail->debit;
        $dk      = $detail->kredit > 0 ? 'K' : 'D';
        $noArRef = null;
        $selisih = 0;

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
    }

    public function getPicArList(): array
    {
        return User::query()
            ->with('karyawan:id,nama_karyawan')
            ->whereHas('roles', fn($q) => $q->whereIn('name', ['AR', 'SUPERVISOR', 'MANAGER']))
            ->orderBy('username')
            ->get(['id', 'username', 'karyawan_id'])
            ->map(fn($u) => [
                'id'   => $u->id,
                'name' => $u->name,
            ])
            ->toArray();
    }
}
