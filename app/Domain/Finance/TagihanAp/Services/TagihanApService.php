<?php

namespace App\Domain\Finance\TagihanAp\Services;

use App\Domain\Finance\TagihanAp\DTO\TagihanApDTO;
use App\Domain\Finance\TagihanAp\Repositories\TagihanApRepository;
use App\Models\Karyawan;
use App\Models\TagihanAp;
use App\Models\TagihanApApprovalLog;
use App\Models\VendorAp;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagihanApService
{
    public function __construct(private readonly TagihanApRepository $repository) {}

    public function paginate(array $filters = [], ?array $with = null): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, with: $with);
    }

    public function getSummary(array $filters = []): array
    {
        return $this->repository->getSummary($filters);
    }

    public function findOrFail(int $id): TagihanAp
    {
        $tagihan = $this->repository->findById($id);
        abort_if(!$tagihan, 404, 'Tagihan tidak ditemukan');
        return $tagihan;
    }

    public function findForPrintOrFail(int $id): TagihanAp
    {
        $tagihan = TagihanAp::with([
            'vendorAp',
            'perusahaan',
            'karyawan.perusahaan',
            'items',
            'pembayarans',
            'createdBy.karyawan',
            'submittedBy.karyawan',
            'approvedBy.karyawan',
            'openingBalanceApDetails.items',
        ])->find($id);

        abort_if(!$tagihan, 404, 'Tagihan tidak ditemukan');

        return $tagihan;
    }

    public function generateNoTagihan(string $tanggal): string
    {
        $date = \Carbon\Carbon::parse($tanggal);
        $now  = \Carbon\Carbon::now();

        return 'AP-' . $date->format('dmY') . $now->format('Hisv');
    }

    public function generateOpeningBalanceNoTagihan(string $tanggal): string
    {
        $date = \Carbon\Carbon::parse($tanggal);
        $now  = \Carbon\Carbon::now();

        return 'OB-AP-' . $date->format('dmY') . $now->format('Hisv');
    }

    public function createOpeningBalance(array $data): TagihanAp
    {
        $user = auth()->user()->loadMissing('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');
        abort_if(!$user->karyawan->perusahaan_id, 422, 'Karyawan tidak terhubung dengan entitas perusahaan');

        $vendor = VendorAp::findOrFail($data['vendor_ap_id']);

        return DB::transaction(function () use ($data, $vendor, $user) {
            $saldoAwal = !empty($data['details'])
                ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
                : (float) ($data['saldo_awal'] ?? 0);

            $tagihan = $this->repository->create([
                'no_tagihan'          => $data['no_tagihan'],
                'tanggal_tagihan'     => $data['tanggal'],
                'tanggal_jatuh_tempo' => $data['tanggal_jatuh_tempo'] ?? null,
                'vendor_ap_id'        => $vendor->id,
                'perusahaan_id'       => $user->karyawan->perusahaan_id,
                'karyawan_id'         => $user->karyawan->id,
                'subtotal'            => $saldoAwal,
                'total_tagihan'       => $saldoAwal,
                'total_pembayaran'    => 0,
                'sisa_tagihan'        => $saldoAwal,
                'status'              => 'DRAFT',
                'approval_status'     => 'PENDING',
                'submitted_at'        => now(),
                'submitted_by'        => auth()->id(),
                'is_opening_balance'  => true,
                'keterangan'          => $data['keterangan'] ?? 'Opening Balance AP',
                'prepared_token'      => Str::uuid()->toString(),
                'created_by'          => auth()->id(),
            ]);

            $this->createApprovalLog($tagihan, 'SUBMITTED');

            if (!empty($data['details'])) {
                $this->syncOpeningBalanceDetails($tagihan, $data['details']);
            }

            return $this->findOrFail($tagihan->id);
        });
    }

    public function updateOpeningBalance(TagihanAp $tagihan, array $data): TagihanAp
    {
        $this->ensureOpeningBalance($tagihan);

        abort_if(
            !($tagihan->status === 'DRAFT' && $tagihan->approval_status === 'REJECTED'),
            422,
            'Opening balance hanya dapat diedit setelah ditolak'
        );

        $vendor = VendorAp::findOrFail($data['vendor_ap_id']);

        $saldoAwal = !empty($data['details'])
            ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
            : (float) ($data['saldo_awal'] ?? 0);

        $tagihan->update([
            'no_tagihan'          => $data['no_tagihan'],
            'tanggal_tagihan'     => $data['tanggal'],
            'tanggal_jatuh_tempo' => $data['tanggal_jatuh_tempo'] ?? null,
            'vendor_ap_id'        => $vendor->id,
            'subtotal'            => $saldoAwal,
            'total_tagihan'       => $saldoAwal,
            'sisa_tagihan'        => $saldoAwal - $tagihan->total_pembayaran,
            'keterangan'          => $data['keterangan'] ?? 'Opening Balance AP',
            'updated_by'          => auth()->id(),
        ]);

        $tagihan->openingBalanceApDetails()->delete();
        if (!empty($data['details'])) {
            $this->syncOpeningBalanceDetails($tagihan, $data['details']);
        }

        return $this->findOrFail($tagihan->id);
    }

    private function syncOpeningBalanceDetails(TagihanAp $tagihan, array $details): void
    {
        foreach ($details as $detail) {
            $items = $detail['items'] ?? [];

            $jumlahTagihan = !empty($items)
                ? collect($items)->sum('subtotal')
                : ($detail['jumlah_tagihan_asal'] ?? 0);

            $obDetail = $tagihan->openingBalanceApDetails()->create([
                'no_invoice_asal'      => $detail['no_invoice_asal'],
                'tanggal_invoice_asal' => $detail['tanggal_invoice_asal'],
                'deskripsi'            => $detail['deskripsi'],
                'jumlah_tagihan_asal'  => $jumlahTagihan,
                'sisa_tagihan_asal'    => $detail['sisa_tagihan_asal'],
                'keterangan'           => $detail['keterangan'] ?? null,
                'created_by'           => auth()->id(),
            ]);

            foreach ($items as $item) {
                $obDetail->items()->create([
                    'barang_id'    => $item['barang_id'] ?? null,
                    'kode_barang'  => $item['kode_barang'] ?? null,
                    'nama_barang'  => $item['nama_barang'],
                    'qty'          => $item['qty'],
                    'satuan'       => $item['satuan'] ?? null,
                    'harga_satuan' => $item['harga_satuan'],
                    'subtotal'     => $item['subtotal'],
                    'keterangan'   => $item['keterangan'] ?? null,
                ]);
            }
        }
    }

    public function resubmitOpeningBalance(TagihanAp $tagihan, ?string $note = null): TagihanAp
    {
        $this->ensureOpeningBalance($tagihan);

        abort_if(
            !($tagihan->status === 'DRAFT' && $tagihan->approval_status === 'REJECTED'),
            422,
            'Opening balance hanya dapat diajukan ulang jika status approval ditolak'
        );

        return DB::transaction(function () use ($tagihan, $note) {
            $tagihan->update([
                'approval_status' => 'PENDING',
                'submitted_at'    => now(),
                'submitted_by'    => auth()->id(),
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => null,
                'rejected_by'     => null,
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($tagihan, 'RESUBMITTED', $note);

            return $this->findOrFail($tagihan->id);
        });
    }

    public function approveOpeningBalance(TagihanAp $tagihan, ?string $note = null): TagihanAp
    {
        $this->ensurePendingOpeningBalance($tagihan);

        return DB::transaction(function () use ($tagihan, $note) {
            $tagihan->update([
                'status'          => 'DITERIMA',
                'approval_status' => 'APPROVED',
                'approved_at'     => now(),
                'approved_by'     => auth()->id(),
                'rejected_at'     => null,
                'rejected_by'     => null,
                'approved_token'  => Str::uuid()->toString(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($tagihan, 'APPROVED', $note);

            return $this->findOrFail($tagihan->id);
        });
    }

    public function rejectOpeningBalance(TagihanAp $tagihan, string $note): TagihanAp
    {
        $this->ensurePendingOpeningBalance($tagihan);

        return DB::transaction(function () use ($tagihan, $note) {
            $tagihan->update([
                'status'          => 'DRAFT',
                'approval_status' => 'REJECTED',
                'approved_at'     => null,
                'approved_by'     => null,
                'rejected_at'     => now(),
                'rejected_by'     => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($tagihan, 'REJECTED', $note);

            return $this->findOrFail($tagihan->id);
        });
    }

    private function ensureOpeningBalance(TagihanAp $tagihan): void
    {
        abort_if(!$tagihan->is_opening_balance, 422, 'Data yang dipilih bukan opening balance');
    }

    private function ensurePendingOpeningBalance(TagihanAp $tagihan): void
    {
        $this->ensureOpeningBalance($tagihan);

        abort_if(
            !($tagihan->status === 'DRAFT' && $tagihan->approval_status === 'PENDING'),
            422,
            'Opening balance tidak berada pada status menunggu persetujuan'
        );
    }

    public function create(TagihanApDTO $dto): TagihanAp
    {
        $user = auth()->user()->loadMissing('karyawan.perusahaan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');
        abort_if(!$user->karyawan->perusahaan_id, 422, 'Karyawan tidak terhubung dengan entitas perusahaan');

        $vendor = VendorAp::findOrFail($dto->vendor_ap_id);

        return DB::transaction(function () use ($dto, $vendor, $user) {
            $subtotal = collect($dto->items)->sum(
                fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
            );
            $totalTagihan = $subtotal + $dto->ppn_masukan - $dto->pph23;

            $tagihan = $this->repository->create([
                'no_tagihan'          => $dto->no_tagihan ?? $this->generateNoTagihan($dto->tanggal_tagihan),
                'no_invoice_vendor'   => $dto->no_invoice_vendor,
                'tanggal_tagihan'     => $dto->tanggal_tagihan,
                'tanggal_jatuh_tempo' => $dto->tanggal_jatuh_tempo,
                'vendor_ap_id'        => $vendor->id,
                'perusahaan_id'       => $user->karyawan->perusahaan_id,
                'karyawan_id'         => $user->karyawan->id,
                'no_po'               => $dto->no_po,
                'no_terima_barang'    => $dto->no_terima_barang,
                'subtotal'            => $subtotal,
                'ppn_masukan'         => $dto->ppn_masukan,
                'pph23'               => $dto->pph23,
                'total_tagihan'       => $totalTagihan,
                'total_pembayaran'    => 0,
                'sisa_tagihan'        => $totalTagihan,
                'status'              => 'DITERIMA',
                'approval_status'     => 'APPROVED',
                'submitted_at'        => now(),
                'submitted_by'        => auth()->id(),
                'approved_at'         => now(),
                'approved_by'         => auth()->id(),
                'keterangan'          => $dto->keterangan,
                'prepared_token'      => Str::uuid()->toString(),
                'approved_token'      => Str::uuid()->toString(),
                'created_by'          => auth()->id(),
            ]);

            foreach ($dto->items as $item) {
                $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
                $tagihan->items()->create([
                    'barang_id'    => $item['barang_id'] ?? null,
                    'kode_barang'  => $item['kode_barang'] ?? null,
                    'nama_barang'  => $item['nama_barang'],
                    'qty'          => $item['qty'],
                    'qty_po'       => $item['qty_po'] ?? null,
                    'satuan'       => $item['satuan'] ?? null,
                    'harga_satuan' => $item['harga_satuan'],
                    'ppn'          => $item['ppn'] ?? null,
                    'status_detail_terima_po' => $item['status_detail_terima_po'] ?? null,
                    'qty_tolak'    => $item['qty_tolak'] ?? null,
                    'keterangan_tolak' => $item['keterangan_tolak'] ?? null,
                    'subtotal'     => $itemSubtotal,
                    'keterangan'   => $item['keterangan'] ?? null,
                ]);
            }

            $this->createApprovalLog($tagihan, 'APPROVED', 'Otomatis disetujui — approval dihilangkan');

            return $this->findOrFail($tagihan->id);
        });
    }

    public function update(TagihanAp $tagihan, TagihanApDTO $dto): TagihanAp
    {
        abort_if(
            (float) $tagihan->total_pembayaran !== 0.0,
            422,
            'Tagihan yang sudah ada pembayaran tidak dapat diedit'
        );

        $vendor = VendorAp::findOrFail($dto->vendor_ap_id);

        $subtotal     = collect($dto->items)->sum(fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0));
        $totalTagihan = $subtotal + $dto->ppn_masukan - $dto->pph23;

        $tagihan->update([
            'no_tagihan'          => $dto->no_tagihan ?? $tagihan->no_tagihan,
            'no_invoice_vendor'   => $dto->no_invoice_vendor,
            'tanggal_tagihan'     => $dto->tanggal_tagihan,
            'tanggal_jatuh_tempo' => $dto->tanggal_jatuh_tempo,
            'vendor_ap_id'        => $vendor->id,
            'no_po'               => $dto->no_po,
            'no_terima_barang'    => $dto->no_terima_barang,
            'subtotal'            => $subtotal,
            'ppn_masukan'         => $dto->ppn_masukan,
            'pph23'               => $dto->pph23,
            'total_tagihan'       => $totalTagihan,
            'sisa_tagihan'        => $totalTagihan - $tagihan->total_pembayaran,
            'keterangan'          => $dto->keterangan,
            'updated_by'          => auth()->id(),
        ]);

        $tagihan->items()->delete();
        foreach ($dto->items as $item) {
            $itemSubtotal = ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0);
            $tagihan->items()->create([
                'barang_id'    => $item['barang_id'] ?? null,
                'kode_barang'  => $item['kode_barang'] ?? null,
                'nama_barang'  => $item['nama_barang'],
                'qty'          => $item['qty'],
                'qty_po'       => $item['qty_po'] ?? null,
                'satuan'       => $item['satuan'] ?? null,
                'harga_satuan' => $item['harga_satuan'],
                'ppn'          => $item['ppn'] ?? null,
                'status_detail_terima_po' => $item['status_detail_terima_po'] ?? null,
                'qty_tolak'    => $item['qty_tolak'] ?? null,
                'keterangan_tolak' => $item['keterangan_tolak'] ?? null,
                'subtotal'     => $itemSubtotal,
                'keterangan'   => $item['keterangan'] ?? null,
            ]);
        }

        return $this->findOrFail($tagihan->id);
    }

    public function recalculate(TagihanAp $tagihan): void
    {
        $totalPembayaran  = $tagihan->pembayaranApItems()->sum('jumlah_dialokasikan');
        $totalPenyesuaian = (float) $tagihan->total_penyesuaian;
        $totalTagihan     = (float) $tagihan->total_tagihan;

        $terbayarEfektif = $totalPembayaran + $totalPenyesuaian;
        $sisaTagihan     = max(0, $totalTagihan - $terbayarEfektif);
        $isLunas         = $sisaTagihan <= 0;

        if ($terbayarEfektif <= 0) {
            $status = $tagihan->approval_status === 'APPROVED' ? 'DITERIMA' : $tagihan->status;
        } elseif ($isLunas) {
            $status = 'LUNAS';
        } else {
            $status = 'SEBAGIAN';
        }

        $tagihan->update([
            'total_pembayaran' => $totalPembayaran,
            'sisa_tagihan'     => $sisaTagihan,
            'status'           => $status,
            'updated_by'       => auth()->id(),
        ]);
    }

    /**
     * tb_tagihan_ap_item dan tb_tagihan_ap_approval_logs CASCADE ke tagihan ini;
     * tb_pembayaran_ap_items sengaja RESTRICT (lihat migration
     * create_tb_pembayaran_ap_items_table) — guard di bawah ini yang jadi lapis
     * pertama supaya pesan errornya jelas, sebelum DB menolak lewat FK.
     *
     * tb_ap_import_tagihan_links juga CASCADE, tapi SENGAJA tidak diblokir di
     * sini: TagihanApObserver::deleted() akan memanggil
     * ApShz360ImportService::revertReceiptAfterTagihanDeleted() setelah baris
     * ini benar-benar terhapus, supaya staging SHZ360 terkait kembali ke
     * status READY_FOR_AP ("siap dijadikan tagihan") — sama seperti
     * VendorApService::delete() yang membiarkan revertMappingAfterVendorDeleted()
     * berjalan.
     */
    public function delete(TagihanAp $tagihan): void
    {
        abort_if(
            $tagihan->pembayaranApItems()->exists(),
            422,
            'Tagihan tidak dapat dihapus karena memiliki riwayat pembayaran'
        );

        DB::transaction(fn () => $this->repository->delete($tagihan));
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $tagihan = $this->repository->findById((int) $id);
                if (!$tagihan) {
                    continue;
                }
                $this->delete($tagihan);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (mis. sudah memiliki riwayat pembayaran)
            }
        }
        return $deleted;
    }

    private function createApprovalLog(TagihanAp $tagihan, string $action, ?string $note = null): void
    {
        TagihanApApprovalLog::create([
            'tagihan_ap_id' => $tagihan->id,
            'action'        => $action,
            'actor_id'      => auth()->id(),
            'note'          => $note,
        ]);
    }
}
