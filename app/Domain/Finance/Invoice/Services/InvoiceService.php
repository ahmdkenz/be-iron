<?php

namespace App\Domain\Finance\Invoice\Services;

use App\Domain\Finance\Invoice\DTO\InvoiceDTO;
use App\Domain\Finance\Invoice\Jobs\UploadInvoiceToGDriveJob;
use App\Domain\Finance\Invoice\Repositories\InvoiceRepository;
use App\Models\Invoice;
use App\Models\InvoiceApprovalLog;
use App\Models\KlienAr;
use App\Models\OpeningBalanceDetail;
use App\Models\User;
use App\Support\Helpers\RoleHelper;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(private readonly InvoiceRepository $repository) {}

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($filters);
    }

    public function getAllForExport(array $filters = []): Collection
    {
        return $this->repository->getAll($filters);
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

    public function getRekapKlien(array $filters = []): array
    {
        return $this->repository->getRekapKlien($filters);
    }

    public function getCarryover(int $klienArId, bool $excludeOpeningBalance = false): float
    {
        $query = Invoice::where('klien_ar_id', $klienArId)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN']);

        if ($excludeOpeningBalance) {
            $query->where('is_opening_balance', false);
        } else {
            $query->where(function ($q) {
                $q->where('is_opening_balance', false)
                    ->orWhere(function ($q2) {
                        $q2->where('is_opening_balance', true)
                            ->where('approval_status', 'APPROVED');
                    });
            });
        }

        return (float) $query
            ->selectRaw('COALESCE(SUM(GREATEST(0, CASE WHEN subtotal = 0 THEN sisa_tagihan ELSE subtotal - total_pembayaran - total_penyesuaian END)), 0) as total')
            ->value('total') ?? 0.0;
    }

    public function getMonthlyCarryover(int $klienArId, string $tanggalInvoice): float
    {
        $monthStart = Carbon::parse($tanggalInvoice)->startOfMonth()->toDateString();

        // Hanya invoice reguler — OB adalah dokumen terpisah dan tidak masuk tagihan_periode_sebelumnya
        return (float) Invoice::where('klien_ar_id', $klienArId)
            ->where('is_opening_balance', false)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('tanggal_invoice', '>=', $monthStart)
            ->where('tanggal_invoice', '<=', $tanggalInvoice)
            ->selectRaw('COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total')
            ->value('total') ?? 0.0;
    }

    public function generateNoInvoice(KlienAr $klien, string $tanggal): string
    {
        return $this->generateConsolidatedInvoiceNo($klien, $tanggal);
    }

    public function generateOpeningBalanceNoInvoice(KlienAr $klien, string $tanggal): string
    {
        $klien->loadMissing('perusahaan');
        $raw       = $klien->perusahaan?->nama_singkatan_perusahaan ?? strtoupper($klien->kode_klien);
        $singkatan = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        $date      = Carbon::parse($tanggal);
        $now       = Carbon::now();
        $xxx       = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);

        return 'OB-' . $singkatan . '-' . $date->format('dmY') . $now->format('His') . '-' . $xxx;
    }

    public function generateConsolidatedInvoiceNo(KlienAr $klien, ?string $tanggal = null): string
    {
        $klien->loadMissing('perusahaan');
        $raw         = $klien->perusahaan?->nama_singkatan_perusahaan ?? 'ABB';
        $singkatan   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        $invoiceDate = $tanggal ? Carbon::parse($tanggal) : Carbon::now();
        $now         = Carbon::now();
        $xxx         = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);

        return 'SI-' . $singkatan . '-' . $invoiceDate->format('dmY') . $now->format('His') . '-' . $xxx;
    }

    public function create(InvoiceDTO $dto): Invoice
    {
        return DB::transaction(function () use ($dto) {
            $klien     = KlienAr::with('perusahaan')->findOrFail($dto->klien_ar_id);
            $carryover = $this->getMonthlyCarryover($dto->klien_ar_id, $dto->tanggal_invoice);
            $noInvoice = $this->generateConsolidatedInvoiceNo($klien, $dto->tanggal_invoice);

            $subtotal = collect($dto->items)->sum(
                fn($item) => ($item['qty'] ?? 0) * ($item['harga_satuan'] ?? 0)
            );
            $totalTagihan = $subtotal + $carryover;

            $invoice = $this->repository->create([
                'no_invoice'                 => $noInvoice,
                'tanggal_invoice'            => $dto->tanggal_invoice,
                'tanggal_kirim_barang'       => $dto->tanggal_kirim_barang,
                'tanggal_jatuh_tempo'        => $dto->tanggal_jatuh_tempo,
                'periode_awal'               => $dto->periode_awal,
                'periode_akhir'              => $dto->periode_akhir,
                'klien_ar_id'                => $dto->klien_ar_id,
                'resto_id'                   => $dto->resto_id,
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $this->resolveInvoiceKaryawanId(auth()->user(), $klien),
                'no_surat_jalan'             => $dto->no_surat_jalan,
                'subtotal'                   => $subtotal,
                'tagihan_periode_sebelumnya' => $carryover,
                'total_tagihan'              => $totalTagihan,
                'total_pembayaran'           => 0,
                'sisa_tagihan'               => $totalTagihan,
                'status'                     => $dto->status,
                'keterangan'                 => $dto->keterangan,
                'prepared_token'             => Str::uuid()->toString(),
                'approved_token'             => Str::uuid()->toString(),
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
                'klienAr.karyawanAr',
                'klienAr.resto.investor',
                'perusahaan',
                'karyawan',
                'items.barang',
                'pembayarans',
            ]);
        });
    }

    public function createOpeningBalance(array $data): Invoice
    {
        $user = auth()->user()->loadMissing('karyawan');
        abort_if(!$user?->karyawan?->id, 422, 'User tidak terhubung dengan data karyawan');

        $klien = KlienAr::findOrFail($data['klien_ar_id']);

        return DB::transaction(function () use ($data, $klien, $user) {
            $saldoAwal = !empty($data['details'])
                ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
                : (float) ($data['saldo_awal'] ?? 0);

            $invoice = $this->repository->create([
                'no_invoice'                 => $data['no_invoice'],
                'tanggal_invoice'            => $data['tanggal'],
                'periode_awal'               => $data['periode_awal'],
                'periode_akhir'              => $data['periode_akhir'],
                'klien_ar_id'                => $data['klien_ar_id'],
                'perusahaan_id'              => $klien->perusahaan_id,
                'karyawan_id'                => $this->resolveInvoiceKaryawanId($user, $klien),
                'subtotal'                   => $saldoAwal,
                'tagihan_periode_sebelumnya' => 0,
                'total_tagihan'              => $saldoAwal,
                'total_pembayaran'           => 0,
                'sisa_tagihan'               => $saldoAwal,
                'status'                     => 'DRAFT',
                'approval_status'            => 'PENDING',
                'submitted_at'               => now(),
                'submitted_by'               => auth()->id(),
                'is_opening_balance'         => true,
                'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
                'prepared_token'             => Str::uuid()->toString(),
                'created_by'                 => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'SUBMITTED');

            if (!empty($data['details'])) {
                $this->syncOpeningBalanceDetails($invoice, $data['details']);
            }

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

        $saldoAwal = !empty($data['details'])
            ? collect($data['details'])->sum(fn($d) => (float) ($d['sisa_tagihan_asal'] ?? 0))
            : (float) ($data['saldo_awal'] ?? 0);

        $invoice->update([
            'no_invoice'                 => $data['no_invoice'],
            'tanggal_invoice'            => $data['tanggal'],
            'periode_awal'               => $data['periode_awal'],
            'periode_akhir'              => $data['periode_akhir'],
            'klien_ar_id'                => $data['klien_ar_id'],
            'perusahaan_id'              => $klien->perusahaan_id,
            'subtotal'                   => $saldoAwal,
            'total_tagihan'              => $saldoAwal,
            'sisa_tagihan'               => $saldoAwal - $invoice->total_pembayaran,
            'keterangan'                 => $data['keterangan'] ?? 'Opening Balance',
            'updated_by'                 => auth()->id(),
        ]);

        $invoice->openingBalanceDetails()->delete();
        if (!empty($data['details'])) {
            $this->syncOpeningBalanceDetails($invoice, $data['details']);
        }

        return $this->findOrFail($invoice->id);
    }

    private function syncOpeningBalanceDetails(Invoice $invoice, array $details): void
    {
        foreach ($details as $detail) {
            $items = $detail['items'] ?? [];

            $jumlahTagihan = !empty($items)
                ? collect($items)->sum('subtotal')
                : ($detail['jumlah_tagihan_asal'] ?? 0);

            $obDetail = $invoice->openingBalanceDetails()->create([
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
                'approved_token'  => Str::uuid()->toString(),
                'updated_by'      => auth()->id(),
            ]);

            $this->createApprovalLog($invoice, 'APPROVED', $note);

            $approved = $this->findOrFail($invoice->id);

            $this->propagateCarryover($approved);

            // Dispatch di dalam transaksi: job hanya masuk queue jika transaksi commit
            UploadInvoiceToGDriveJob::dispatch($approved->id);

            return $approved;
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
            'no_invoice'                 => $dto->no_invoice,
            'tanggal_invoice'            => $dto->tanggal_invoice,
            'tanggal_kirim_barang'       => $dto->tanggal_kirim_barang,
            'tanggal_jatuh_tempo'        => $dto->tanggal_jatuh_tempo,
            'periode_awal'               => $dto->periode_awal,
            'periode_akhir'              => $dto->periode_akhir,
            'klien_ar_id'                => $dto->klien_ar_id,
            'resto_id'                   => $dto->resto_id,
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

        return $invoice->fresh(['klienAr.karyawanAr', 'klienAr.resto.investor', 'perusahaan', 'karyawan', 'items.barang', 'pembayarans']);
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

        $updateData = ['status' => $status, 'updated_by' => auth()->id()];

        if ($status === 'LUNAS') {
            $updateData['sisa_tagihan'] = 0;
        }

        $invoice->update($updateData);

        $this->cascadeCarryoverToNext($invoice->fresh());

        return $invoice->fresh();
    }

    public function recalculate(Invoice $invoice): void
    {
        $totalPembayaran  = $invoice->pembayarans()->sum('jumlah_pembayaran');
        $totalPenyesuaian = (float) $invoice->total_penyesuaian;
        $subtotal         = (float) $invoice->subtotal;

        // Untuk invoice reguler, hitung ulang tagihan_periode_sebelumnya dari riwayat aktual
        // agar data yang salah (misal OB-carryover terbalik) bisa dikoreksi lewat recalculate.
        $newCarryover    = $invoice->is_opening_balance ? 0.0 : $this->sumOwnSisaBeforeInvoice($invoice);
        $newTotalTagihan = $invoice->is_opening_balance
            ? (float) $invoice->total_tagihan   // OB: total_tagihan akan dikoreksi oleh cascadeCarryoverToNext
            : $subtotal + $newCarryover;

        // Penyesuaian manual (write-off) diperlakukan setara pembayaran saat
        // menghitung sisa & status, tapi disimpan terpisah (tidak menambah total_pembayaran).
        $terbayarEfektif = (float) $totalPembayaran + $totalPenyesuaian;

        $rawSisa = max(0, $newTotalTagihan - $terbayarEfektif);
        // Regular invoice (subtotal > 0): LUNAS saat subtotal terbayar lunas.
        // OB invoice (subtotal = 0): LUNAS saat total_tagihan terbayar (perilaku lama).
        $isLunas = $subtotal > 0
            ? $terbayarEfektif >= $subtotal
            : $rawSisa <= 0;

        if ($terbayarEfektif <= 0) {
            $status      = 'TERKIRIM';
            $sisaTagihan = $rawSisa;
        } elseif ($isLunas) {
            $status      = 'LUNAS';
            $sisaTagihan = 0;
        } else {
            $status      = 'SEBAGIAN';
            $sisaTagihan = $rawSisa;
        }

        $updateData = [
            'total_pembayaran' => $totalPembayaran,
            'sisa_tagihan'     => $sisaTagihan,
            'status'           => $status,
            'updated_by'       => auth()->id(),
        ];

        if (!$invoice->is_opening_balance) {
            $updateData['tagihan_periode_sebelumnya'] = $newCarryover;
            $updateData['total_tagihan']              = $newTotalTagihan;
        }

        $invoice->update($updateData);

        $this->cascadeCarryoverToNext($invoice->fresh());
    }

    public function propagateCarryover(Invoice $invoice): void
    {
        $this->cascadeCarryoverToNext($invoice);
    }

    private function sumOwnSisaBeforeInvoice(Invoice $invoice): float
    {
        $monthStart = Carbon::parse($invoice->tanggal_invoice)->startOfMonth()->toDateString();

        // Hanya invoice reguler — OB adalah dokumen terpisah dan tidak masuk tagihan_periode_sebelumnya
        return (float) Invoice::where('klien_ar_id', $invoice->klien_ar_id)
            ->where('is_opening_balance', false)
            ->whereIn('status', ['TERKIRIM', 'SEBAGIAN'])
            ->where('tanggal_invoice', '>=', $monthStart)
            ->where(function ($q) use ($invoice) {
                $q->where('tanggal_invoice', '<', $invoice->tanggal_invoice)
                    ->orWhere(function ($q2) use ($invoice) {
                        $q2->where('tanggal_invoice', $invoice->tanggal_invoice)
                            ->where('id', '<', $invoice->id);
                    });
            })
            ->selectRaw('COALESCE(SUM(GREATEST(0, subtotal - total_pembayaran - total_penyesuaian)), 0) as total')
            ->value('total') ?? 0.0;
    }

    private function cascadeCarryoverToNext(Invoice $invoice): void
    {
        $nextInvoice = Invoice::where('klien_ar_id', $invoice->klien_ar_id)
            ->where(function ($q) use ($invoice) {
                $q->where('tanggal_invoice', '>', $invoice->tanggal_invoice)
                    ->orWhere(function ($q2) use ($invoice) {
                        $q2->where('tanggal_invoice', $invoice->tanggal_invoice)
                            ->where('id', '>', $invoice->id);
                    });
            })
            ->orderBy('tanggal_invoice')
            ->orderBy('id')
            ->first();

        if (!$nextInvoice) {
            return;
        }

        // OB tidak boleh mewarisi carryover dari invoice reguler.
        // Jika OB sudah terlanjur memiliki tagihan_periode_sebelumnya yang salah, koreksi.
        // Setelah koreksi (atau jika sudah benar), selalu teruskan cascade ke invoice reguler sesudahnya.
        if ($nextInvoice->is_opening_balance) {
            if ((float) $nextInvoice->tagihan_periode_sebelumnya > 0.01) {
                $subtotalOb   = (float) $nextInvoice->subtotal;
                $terbayarEfOb = (float) $nextInvoice->total_pembayaran + (float) $nextInvoice->total_penyesuaian;
                $rawSisaOb    = max(0, $subtotalOb - $terbayarEfOb);
                $isLunasOb    = $terbayarEfOb >= $subtotalOb;

                $nextInvoice->update([
                    'tagihan_periode_sebelumnya' => 0,
                    'total_tagihan'              => $subtotalOb,
                    'sisa_tagihan'               => $isLunasOb ? 0 : $rawSisaOb,
                    'status'                     => $terbayarEfOb <= 0 ? 'TERKIRIM' : ($isLunasOb ? 'LUNAS' : 'SEBAGIAN'),
                    'updated_by'                 => auth()->id(),
                ]);
                UploadInvoiceToGDriveJob::dispatch($nextInvoice->id);
            }
            // Lanjutkan cascade melewati OB agar invoice reguler sesudahnya ikut diperbarui
            $this->cascadeCarryoverToNext($nextInvoice->fresh());
            return;
        }

        $oldCarryover = (float) $nextInvoice->tagihan_periode_sebelumnya;
        $newCarryover = $this->sumOwnSisaBeforeInvoice($nextInvoice);

        if (abs($oldCarryover - $newCarryover) < 0.01) {
            return;
        }

        $newTotalTagihan      = (float) $nextInvoice->subtotal + $newCarryover;
        $subtotalNext         = (float) $nextInvoice->subtotal;
        $totalPembayaranNext  = (float) $nextInvoice->total_pembayaran;
        $totalPenyesuaianNext = (float) $nextInvoice->total_penyesuaian;
        $terbayarEfektifNext  = $totalPembayaranNext + $totalPenyesuaianNext;

        $rawSisaNext  = max(0, $newTotalTagihan - $terbayarEfektifNext);
        $isLunasNext  = $subtotalNext > 0
            ? $terbayarEfektifNext >= $subtotalNext
            : $rawSisaNext <= 0;
        $newSisaTagihan = $isLunasNext ? 0.0 : $rawSisaNext;

        $newStatus = match (true) {
            $terbayarEfektifNext <= 0 => 'TERKIRIM',
            $isLunasNext              => 'LUNAS',
            default                   => 'SEBAGIAN',
        };

        $nextInvoice->update([
            'tagihan_periode_sebelumnya' => $newCarryover,
            'total_tagihan'              => $newTotalTagihan,
            'sisa_tagihan'               => $newSisaTagihan,
            'status'                     => $newStatus,
            'updated_by'                 => auth()->id(),
        ]);

        UploadInvoiceToGDriveJob::dispatch($nextInvoice->id);

        $this->cascadeCarryoverToNext($nextInvoice->fresh());
    }

    public function delete(Invoice $invoice): void
    {
        abort_if(
            $invoice->status !== 'DRAFT',
            422,
            'Hanya invoice berstatus DRAFT yang dapat dihapus'
        );

        $prevInvoice = Invoice::where('klien_ar_id', $invoice->klien_ar_id)
            ->where(function ($q) use ($invoice) {
                $q->where('tanggal_invoice', '<', $invoice->tanggal_invoice)
                    ->orWhere(function ($q2) use ($invoice) {
                        $q2->where('tanggal_invoice', $invoice->tanggal_invoice)
                            ->where('id', '<', $invoice->id);
                    });
            })
            ->orderByDesc('tanggal_invoice')
            ->orderByDesc('id')
            ->first();

        $invoice->items()->delete();
        $this->repository->delete($invoice);

        if ($prevInvoice) {
            $this->cascadeCarryoverToNext($prevInvoice->fresh());
        }
    }

    public function bulkDelete(array $ids): int
    {
        $deleted = 0;
        foreach ($ids as $id) {
            $invoice = $this->repository->findById((int) $id);
            if (!$invoice || $invoice->status !== 'DRAFT') {
                continue;
            }
            try {
                $this->delete($invoice);
                $deleted++;
            } catch (\Throwable) {
                // skip jika gagal (misalnya cascade constraint)
            }
        }
        return $deleted;
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

    public function resolveInvoiceKaryawanId(User $user, KlienAr $klien): int
    {
        $user->loadMissing('karyawan');

        // Jika Manager/Supervisor yang membuat dan klien punya PIC AR, gunakan karyawan AR klien
        if (RoleHelper::hasAnyRole($user, ['MANAGER', 'SUPERVISOR']) && $klien->karyawan_ar_id) {
            return $klien->karyawan_ar_id;
        }

        return $user->karyawan->id;
    }

    private function resolveInvoiceSegment(KlienAr $klien): string
    {
        return strtoupper($klien->tipe_klien ?? '') === 'RESTO' ? 'B2C' : 'B2B';
    }
}
