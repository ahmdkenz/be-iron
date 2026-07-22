<?php

namespace App\Domain\Finance\ApLaporan\Services;

use App\Models\PembayaranApItem;
use App\Models\TagihanAp;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ApLaporanService
{
    /**
     * Laporan Hutang per Vendor: agregasi outstanding per vendor dari TagihanAp
     * (termasuk Opening Balance AP yang sudah APPROVED, lihat approvedScope()).
     */
    public function getHutangVendor(array $filters = []): array
    {
        $asOf = isset($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : Carbon::today();

        $tagihanList = $this->baseTagihanQuery($filters)
            ->with(['vendorAp.karyawanAp', 'perusahaan'])
            ->get();

        $rows = $tagihanList->groupBy('vendor_ap_id')->map(function ($group) use ($asOf) {
            $vendor  = $group->first()->vendorAp;
            $entitas = $group->pluck('perusahaan.nama_singkatan_perusahaan')->filter()->unique()->values()->implode(', ');

            $overdue = $group->filter(
                fn($t) => (float) $t->sisa_tagihan > 0 && $t->tanggal_jatuh_tempo && $t->tanggal_jatuh_tempo->lt($asOf)
            );

            $jatuhTempoTerdekat = $group
                ->filter(fn($t) => (float) $t->sisa_tagihan > 0 && $t->tanggal_jatuh_tempo)
                ->sortBy('tanggal_jatuh_tempo')
                ->first()?->tanggal_jatuh_tempo?->toDateString();

            return [
                'vendor_id'            => $vendor?->id,
                'kode_vendor'          => $vendor?->kode_vendor,
                'nama_vendor'          => $vendor?->nama_vendor,
                'pic_ap'               => $vendor?->karyawanAp?->nama_karyawan,
                'entitas'              => $entitas,
                'jumlah_tagihan'       => $group->count(),
                'total_tagihan'        => (float) $group->sum('total_tagihan'),
                'total_pembayaran'     => (float) $group->sum('total_pembayaran'),
                'total_penyesuaian'    => (float) $group->sum('total_penyesuaian'),
                'sisa_tagihan'         => (float) $group->sum('sisa_tagihan'),
                'overdue_amount'       => (float) $overdue->sum('sisa_tagihan'),
                'overdue_count'        => $overdue->count(),
                'jatuh_tempo_terdekat' => $jatuhTempoTerdekat,
                'details'              => $group->sortByDesc('tanggal_tagihan')->map(fn($t) => [
                    'tagihan_id'          => $t->id,
                    'no_tagihan'          => $t->no_tagihan,
                    'no_invoice_vendor'   => $t->no_invoice_vendor,
                    'tanggal_tagihan'     => $t->tanggal_tagihan?->toDateString(),
                    'tanggal_jatuh_tempo' => $t->tanggal_jatuh_tempo?->toDateString(),
                    'total_tagihan'       => (float) $t->total_tagihan,
                    'total_pembayaran'    => (float) $t->total_pembayaran,
                    'total_penyesuaian'   => (float) $t->total_penyesuaian,
                    'sisa_tagihan'        => (float) $t->sisa_tagihan,
                    'status'              => $t->status,
                    'is_opening_balance'  => (bool) $t->is_opening_balance,
                    'perusahaan'          => $t->perusahaan?->nama_singkatan_perusahaan,
                ])->values()->all(),
            ];
        })->sortByDesc('sisa_tagihan')->values()->all();

        $summary = [
            'jumlah_vendor'     => count($rows),
            'total_tagihan'     => array_sum(array_column($rows, 'total_tagihan')),
            'total_pembayaran'  => array_sum(array_column($rows, 'total_pembayaran')),
            'total_penyesuaian' => array_sum(array_column($rows, 'total_penyesuaian')),
            'sisa_tagihan'      => array_sum(array_column($rows, 'sisa_tagihan')),
            'overdue_amount'    => array_sum(array_column($rows, 'overdue_amount')),
            'overdue_count'     => array_sum(array_column($rows, 'overdue_count')),
        ];

        return [
            'as_of_date' => $asOf->toDateString(),
            'summary'    => $summary,
            'rows'       => $rows,
        ];
    }

    /**
     * Laporan Aging Hutang: bucket current/1-30/31-60/61-90/90+ group by vendor.
     * Hanya tagihan dengan sisa_tagihan > 0 yang dihitung.
     */
    public function getAging(array $filters = []): array
    {
        $asOf = isset($filters['as_of_date'])
            ? Carbon::parse($filters['as_of_date'])->startOfDay()
            : Carbon::today();

        $tagihanList = $this->baseTagihanQuery($filters)
            ->where('sisa_tagihan', '>', 0)
            ->with(['vendorAp.karyawanAp', 'perusahaan'])
            ->get();

        $rows = $tagihanList->groupBy('vendor_ap_id')->map(function ($group) use ($asOf) {
            $vendor  = $group->first()->vendorAp;
            $buckets = ['current' => 0, 'hari_1_30' => 0, 'hari_31_60' => 0, 'hari_61_90' => 0, 'hari_91_plus' => 0];
            $details = [];

            foreach ($group as $t) {
                $anchor  = $t->tanggal_jatuh_tempo ?: $t->tanggal_tagihan;
                $ageDays = (int) Carbon::parse($anchor)->startOfDay()->diffInDays($asOf, false);
                $bucket  = $this->classifyBucket($ageDays);
                $sisa    = (float) $t->sisa_tagihan;

                $buckets[$bucket] += $sisa;

                $details[] = [
                    'tagihan_id'          => $t->id,
                    'no_tagihan'          => $t->no_tagihan,
                    'no_invoice_vendor'   => $t->no_invoice_vendor,
                    'tanggal_tagihan'     => $t->tanggal_tagihan?->toDateString(),
                    'tanggal_jatuh_tempo' => $t->tanggal_jatuh_tempo?->toDateString(),
                    'hari_terlambat'      => max(0, $ageDays),
                    'bucket'              => $bucket,
                    'sisa_tagihan'        => $sisa,
                    'status'              => $t->status,
                    'perusahaan'          => $t->perusahaan?->nama_singkatan_perusahaan,
                ];
            }

            $total = array_sum($buckets);

            return [
                'vendor_id'    => $vendor?->id,
                'kode_vendor'  => $vendor?->kode_vendor,
                'nama_vendor'  => $vendor?->nama_vendor,
                'pic_ap'       => $vendor?->karyawanAp?->nama_karyawan,
                'current'      => $buckets['current'],
                'hari_1_30'    => $buckets['hari_1_30'],
                'hari_31_60'   => $buckets['hari_31_60'],
                'hari_61_90'   => $buckets['hari_61_90'],
                'hari_91_plus' => $buckets['hari_91_plus'],
                'total'        => $total,
                'details'      => $details,
            ];
        })->sortByDesc('total')->values()->all();

        $summary = [
            'current'      => array_sum(array_column($rows, 'current')),
            'hari_1_30'    => array_sum(array_column($rows, 'hari_1_30')),
            'hari_31_60'   => array_sum(array_column($rows, 'hari_31_60')),
            'hari_61_90'   => array_sum(array_column($rows, 'hari_61_90')),
            'hari_91_plus' => array_sum(array_column($rows, 'hari_91_plus')),
            'total'        => array_sum(array_column($rows, 'total')),
        ];

        return [
            'as_of_date' => $asOf->toDateString(),
            'summary'    => $summary,
            'rows'       => $rows,
        ];
    }

    /**
     * Laporan Histori Pembayaran: basis tb_pembayaran_ap_items (bukan header
     * voucher) supaya PIC AP hanya melihat alokasi vendor miliknya dalam
     * voucher multi-vendor.
     */
    public function getHistoriPembayaran(array $filters = []): array
    {
        $from = isset($filters['tanggal_dari'])
            ? Carbon::parse($filters['tanggal_dari'])->startOfDay()
            : null;

        $to = isset($filters['tanggal_sampai'])
            ? Carbon::parse($filters['tanggal_sampai'])->endOfDay()
            : null;

        $query = $this->baseHistoriQuery($filters, $from, $to);

        $perMetode = (clone $query)
            ->select(
                'tb_pembayaran_ap.metode_pembayaran',
                DB::raw('COUNT(*) as jumlah_transaksi'),
                DB::raw('SUM(tb_pembayaran_ap_items.jumlah_dialokasikan) as total')
            )
            ->groupBy('tb_pembayaran_ap.metode_pembayaran')
            ->get()
            ->keyBy('metode_pembayaran');

        $rows = $this->historiRows($query);

        $grandTotal = (float) $perMetode->sum('total');

        $summary = [
            'total'            => $grandTotal,
            'transfer'         => (float) ($perMetode['TRANSFER']->total ?? 0),
            'cash'             => (float) ($perMetode['CASH']->total ?? 0),
            'giro'             => (float) ($perMetode['GIRO']->total ?? 0),
            'jumlah_transaksi' => (int) $perMetode->sum('jumlah_transaksi'),
        ];

        return [
            'tanggal_dari'   => $from?->toDateString(),
            'tanggal_sampai' => $to?->toDateString(),
            'summary'        => $summary,
            'rows'           => $rows,
        ];
    }

    private function historiRows(Builder $query): array
    {
        return $query
            ->select(
                'tb_pembayaran_ap_items.id',
                'tb_pembayaran_ap.id as pembayaran_ap_id',
                'tb_pembayaran_ap.tanggal_pembayaran',
                'tb_pembayaran_ap.no_referensi',
                'tb_pembayaran_ap.metode_pembayaran',
                'tb_pembayaran_ap.kategori_voucher',
                'tb_vendor_ap.kode_vendor',
                'tb_vendor_ap.nama_vendor',
                'tb_tagihan_ap.no_tagihan',
                'tb_tagihan_ap.no_invoice_vendor',
                'tb_pembayaran_ap_items.jumlah_dialokasikan',
                'tb_pembayaran_ap_items.sisa_sebelum',
                'tb_pembayaran_ap_items.sisa_sesudah',
                DB::raw('COALESCE(pic_karyawan.nama_karyawan, "") as pic_ap'),
                DB::raw('COALESCE(tb_perusahaan.nama_singkatan_perusahaan, "") as perusahaan')
            )
            ->orderByDesc('tb_pembayaran_ap.tanggal_pembayaran')
            ->orderByDesc('tb_pembayaran_ap_items.id')
            ->get()
            ->map(fn($r) => [
                'id'                  => $r->id,
                'pembayaran_ap_id'    => $r->pembayaran_ap_id,
                'tanggal_pembayaran'  => $r->tanggal_pembayaran,
                'no_referensi'        => $r->no_referensi,
                'metode_pembayaran'   => $r->metode_pembayaran,
                'kategori_voucher'    => $r->kategori_voucher,
                'kode_vendor'         => $r->kode_vendor,
                'nama_vendor'         => $r->nama_vendor,
                'no_tagihan'          => $r->no_tagihan,
                'no_invoice_vendor'   => $r->no_invoice_vendor,
                'jumlah_dialokasikan' => (float) $r->jumlah_dialokasikan,
                'sisa_sebelum'        => (float) $r->sisa_sebelum,
                'sisa_sesudah'        => (float) $r->sisa_sesudah,
                'pic_ap'              => $r->pic_ap,
                'perusahaan'          => $r->perusahaan,
            ])
            ->values()
            ->all();
    }

    private function baseHistoriQuery(array $filters, ?Carbon $from, ?Carbon $to): Builder
    {
        return PembayaranApItem::query()
            ->join('tb_pembayaran_ap', 'tb_pembayaran_ap_items.pembayaran_ap_id', '=', 'tb_pembayaran_ap.id')
            ->join('tb_tagihan_ap', 'tb_pembayaran_ap_items.tagihan_ap_id', '=', 'tb_tagihan_ap.id')
            ->join('tb_vendor_ap', 'tb_pembayaran_ap_items.vendor_ap_id', '=', 'tb_vendor_ap.id')
            ->leftJoin('tb_perusahaan', 'tb_tagihan_ap.perusahaan_id', '=', 'tb_perusahaan.id')
            ->leftJoin('tb_karyawan as pic_karyawan', 'tb_vendor_ap.karyawan_ap_id', '=', 'pic_karyawan.id')
            ->when($from, fn($q) => $q->whereDate('tb_pembayaran_ap.tanggal_pembayaran', '>=', $from->toDateString()))
            ->when($to, fn($q) => $q->whereDate('tb_pembayaran_ap.tanggal_pembayaran', '<=', $to->toDateString()))
            ->when($filters['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('tb_pembayaran_ap_items.vendor_ap_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('tb_tagihan_ap.perusahaan_id', $v))
            ->when($filters['metode_pembayaran'] ?? null, fn($q, $v) => $q->where('tb_pembayaran_ap.metode_pembayaran', $v))
            ->when($filters['kategori_voucher'] ?? null, fn($q, $v) => $q->where('tb_pembayaran_ap.kategori_voucher', $v))
            ->when($filters['pic_ap_karyawan_id'] ?? null, fn($q, $v) => $q->where('tb_vendor_ap.karyawan_ap_id', $v));
    }

    private function baseTagihanQuery(array $filters): Builder
    {
        return TagihanAp::query()
            ->where(fn($q) => $this->approvedScope($q))
            ->when($filters['tanggal_dari'] ?? null, fn($q, $v) => $q->whereDate('tanggal_tagihan', '>=', $v))
            ->when($filters['tanggal_sampai'] ?? null, fn($q, $v) => $q->whereDate('tanggal_tagihan', '<=', $v))
            ->when($filters['vendor_ap_id'] ?? null, fn($q, $v) => $q->where('vendor_ap_id', $v))
            ->when($filters['perusahaan_id'] ?? null, fn($q, $v) => $q->where('perusahaan_id', $v))
            ->when(
                $filters['pic_ap_karyawan_id'] ?? null,
                fn($q, $v) => $q->whereHas('vendorAp', fn($q) => $q->where('karyawan_ap_id', $v))
            );
    }

    /**
     * Tagihan reguler otomatis APPROVED saat dibuat; hanya Opening Balance AP
     * yang melalui alur PENDING/APPROVED/REJECTED. Baris OB yang belum
     * APPROVED tidak dihitung sebagai hutang berjalan.
     */
    private function approvedScope(Builder $query): void
    {
        $query
            ->where('is_opening_balance', false)
            ->orWhere(fn($q) => $q->where('is_opening_balance', true)->where('approval_status', 'APPROVED'));
    }

    private function classifyBucket(int $ageDays): string
    {
        if ($ageDays <= 0)  return 'current';
        if ($ageDays <= 30) return 'hari_1_30';
        if ($ageDays <= 60) return 'hari_31_60';
        if ($ageDays <= 90) return 'hari_61_90';

        return 'hari_91_plus';
    }
}
