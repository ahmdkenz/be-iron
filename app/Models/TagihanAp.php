<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use Illuminate\Database\Eloquent\Model;

class TagihanAp extends Model
{
    use BlameableTrait;

    protected $table = 'tb_tagihan_ap';

    protected $fillable = [
        'no_tagihan',
        'no_invoice_vendor',
        'tanggal_tagihan',
        'tanggal_jatuh_tempo',
        'vendor_ap_id',
        'source_system',
        'ap_shz360_po_import_id',
        'ap_shz360_receipt_import_id',
        'perusahaan_id',
        'karyawan_id',
        'no_po',
        'no_terima_barang',
        'subtotal',
        'ppn_masukan',
        'pph23',
        'total_tagihan',
        'total_pembayaran',
        'total_penyesuaian',
        'sisa_tagihan',
        'status',
        'approval_status',
        'is_opening_balance',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'keterangan',
        'prepared_token',
        'approved_token',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_tagihan'     => 'date',
        'tanggal_jatuh_tempo' => 'date',
        'submitted_at'        => 'datetime',
        'approved_at'         => 'datetime',
        'rejected_at'         => 'datetime',
        'subtotal'            => 'decimal:2',
        'ppn_masukan'         => 'decimal:2',
        'pph23'               => 'decimal:2',
        'total_tagihan'       => 'decimal:2',
        'total_pembayaran'    => 'decimal:2',
        'total_penyesuaian'   => 'decimal:2',
        'sisa_tagihan'        => 'decimal:2',
        'is_opening_balance'  => 'boolean',
    ];

    public function vendorAp()
    {
        return $this->belongsTo(VendorAp::class, 'vendor_ap_id');
    }

    public function apShz360PoImport()
    {
        return $this->belongsTo(ApShz360PoImport::class, 'ap_shz360_po_import_id');
    }

    public function apShz360ReceiptImport()
    {
        return $this->belongsTo(ApShz360ReceiptImport::class, 'ap_shz360_receipt_import_id');
    }

    public function perusahaan()
    {
        return $this->belongsTo(Perusahaan::class, 'perusahaan_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }

    public function items()
    {
        return $this->hasMany(TagihanApItem::class, 'tagihan_ap_id');
    }

    public function openingBalanceApDetails()
    {
        return $this->hasMany(OpeningBalanceApDetail::class, 'tagihan_ap_id')
                    ->orderBy('tanggal_invoice_asal');
    }

    /**
     * Deprecated: hanya mencakup pembayaran lama (single-tagihan) yang masih
     * mengisi header.tagihan_ap_id. Voucher AP baru (multi-vendor) TIDAK
     * mengisi kolom ini lagi — gunakan pembayaranApItems() sebagai sumber
     * kebenaran untuk hitung saldo/riwayat.
     */
    public function pembayarans()
    {
        return $this->hasMany(PembayaranAp::class, 'tagihan_ap_id');
    }

    public function pembayaranApItems()
    {
        return $this->hasMany(PembayaranApItem::class, 'tagihan_ap_id');
    }

    public function approvalLogs()
    {
        return $this->hasMany(TagihanApApprovalLog::class, 'tagihan_ap_id')->latest('id');
    }

    public function lastDecisionLog()
    {
        return $this->hasOne(TagihanApApprovalLog::class, 'tagihan_ap_id')
            ->whereIn('action', ['APPROVED', 'REJECTED'])
            ->latestOfMany('id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
