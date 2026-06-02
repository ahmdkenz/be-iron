<?php

namespace App\Domain\Finance\PendapatanDiMuka\Services;

use App\Models\BankStatementDetail;
use App\Models\PendapatanDiMuka;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PendapatanDiMukaService
{
    public function store(
        BankStatementDetail $detail,
        float $jumlah,
        ?string $keterangan,
        string $tanggalPencatatan
    ): PendapatanDiMuka {
        $pembayaran = $detail->pembayaranAr;
        abort_if(!$pembayaran, 422, 'Transaksi belum memiliki pembayaran yang dicocokkan.');

        $inv = $pembayaran->invoice;
        abort_if(!$inv, 422, 'Pembayaran tidak memiliki invoice.');

        // Hitung sisa kelebihan — sama persis dengan logika di BankStatementService::getDetail()
        $kelebihanFromInvoice = max(0, round((float) $inv->total_pembayaran - (float) $inv->total_tagihan, 2));
        $kelebihanFromBank    = max(0, round((float) $detail->kredit - (float) $pembayaran->jumlah_pembayaran, 2));
        $total                = max($kelebihanFromInvoice, $kelebihanFromBank);
        $dialokasi            = (float) $pembayaran->alokasiKelebihan()->sum('jumlah_pembayaran');
        $sisa                 = max(0, round($total - $dialokasi, 2));

        abort_if($sisa <= 0.01, 422, 'Tidak ada sisa kelebihan bayar untuk dicatat sebagai Pendapatan di Muka.');
        abort_if(
            $jumlah > $sisa + 0.01,
            422,
            'Jumlah melebihi sisa kelebihan (Rp ' . number_format($sisa, 0, ',', '.') . ').'
        );

        $inv->loadMissing('klienAr.resto.investor');
        $investorId = $inv->klienAr?->resto?->investor_id;
        $klienArId  = $inv->klien_ar_id;

        return PendapatanDiMuka::updateOrCreate(
            ['sumber_pembayaran_ar_id' => $pembayaran->id],
            [
                'bank_statement_detail_id' => $detail->id,
                'investor_id'              => $investorId,
                'klien_ar_id'              => $klienArId,
                'jumlah'                   => $jumlah,
                'tanggal_pencatatan'       => $tanggalPencatatan,
                'keterangan'               => $keterangan,
                'status'                   => 'AKTIF',
                'updated_by'               => auth()->id(),
            ]
        );
    }

    public function cancel(PendapatanDiMuka $pdm): void
    {
        abort_if($pdm->status === 'DIBATALKAN', 422, 'Pendapatan di Muka ini sudah dibatalkan.');

        $pdm->update(['status' => 'DIBATALKAN']);
    }

    public function getReport(array $filters): array
    {
        $query = PendapatanDiMuka::query()
            ->with([
                'klienAr',
                'investor',
                'sumberPembayaran',
                'createdBy.karyawan',
            ])
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '<=', $v)
            )
            ->when(
                $filters['investor_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('investor_id', $v)
            )
            ->when(
                $filters['klien_ar_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('klien_ar_id', $v)
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $q, string $v) => $q->where('status', $v)
            );

        $paginator = (clone $query)
            ->latest('tanggal_pencatatan')
            ->paginate($filters['per_page'] ?? 20);

        $totalAktif     = (clone $query)->where('status', 'AKTIF')->sum('jumlah');
        $totalDibatalkan = (clone $query)->where('status', 'DIBATALKAN')->sum('jumlah');
        $jumlahRecord   = (clone $query)->count();

        return [
            'data'     => $paginator->through(fn($pdm) => $this->formatRow($pdm)),
            'meta'     => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'summary'  => [
                'total_aktif'      => (float) $totalAktif,
                'total_dibatalkan' => (float) $totalDibatalkan,
                'jumlah_record'    => $jumlahRecord,
            ],
        ];
    }

    public function getAll(array $filters): \Illuminate\Database\Eloquent\Collection
    {
        return PendapatanDiMuka::query()
            ->with(['klienAr', 'investor', 'sumberPembayaran', 'createdBy.karyawan'])
            ->when(
                $filters['tanggal_dari'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '>=', $v)
            )
            ->when(
                $filters['tanggal_sampai'] ?? null,
                fn(Builder $q, string $v) => $q->whereDate('tanggal_pencatatan', '<=', $v)
            )
            ->when(
                $filters['investor_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('investor_id', $v)
            )
            ->when(
                $filters['klien_ar_id'] ?? null,
                fn(Builder $q, int $v) => $q->where('klien_ar_id', $v)
            )
            ->when(
                $filters['status'] ?? null,
                fn(Builder $q, string $v) => $q->where('status', $v)
            )
            ->latest('tanggal_pencatatan')
            ->get();
    }

    private function formatRow(PendapatanDiMuka $pdm): array
    {
        return [
            'id'                  => $pdm->id,
            'tanggal_pencatatan'  => $pdm->tanggal_pencatatan?->toDateString(),
            'klien'               => $pdm->klienAr?->nama_klien,
            'investor'            => $pdm->investor?->nama_investor,
            'no_referensi_sumber' => $pdm->sumberPembayaran?->no_referensi,
            'jumlah'              => (float) $pdm->jumlah,
            'status'              => $pdm->status,
            'keterangan'          => $pdm->keterangan,
            'created_by'          => $pdm->createdBy?->name,
        ];
    }
}
