<?php

namespace App\Models;

use App\Support\Traits\BlameableTrait;
use App\Support\Traits\SoftDeletesTrait;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use SoftDeletesTrait, BlameableTrait;

    protected $table = 'tb_invoice';

    protected $fillable = [
        'no_invoice',
        'tanggal_invoice',
        'periode_awal',
        'periode_akhir',
        'klien_ar_id',
        'perusahaan_id',
        'karyawan_id',
        'tanggal_jatuh_tempo',
        'no_surat_jalan',
        'subtotal',
        'tagihan_periode_sebelumnya',
        'total_tagihan',
        'total_pembayaran',
        'sisa_tagihan',
        'status',
        'approval_status',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'is_opening_balance',
        'keterangan',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal_invoice'            => 'date',
        'tanggal_jatuh_tempo'        => 'date',
        'periode_awal'               => 'date',
        'periode_akhir'              => 'date',
        'submitted_at'               => 'datetime',
        'approved_at'                => 'datetime',
        'rejected_at'                => 'datetime',
        'subtotal'                   => 'decimal:2',
        'tagihan_periode_sebelumnya' => 'decimal:2',
        'total_tagihan'              => 'decimal:2',
        'total_pembayaran'           => 'decimal:2',
        'sisa_tagihan'               => 'decimal:2',
        'is_opening_balance'         => 'boolean',
    ];

    public function klienAr()
    {
        return $this->belongsTo(KlienAr::class, 'klien_ar_id');
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
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function pembayarans()
    {
        return $this->hasMany(PembayaranAr::class, 'invoice_id');
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

    public function approvalLogs()
    {
        return $this->hasMany(InvoiceApprovalLog::class, 'invoice_id')->latest('id');
    }

    public function requiresApproval(): bool
    {
        return $this->is_opening_balance === true;
    }

    public function isApprovedForFinanceFlow(): bool
    {
        return !$this->requiresApproval() || $this->approval_status === 'APPROVED';
    }

    public function canBeResubmitted(): bool
    {
        return $this->is_opening_balance === true
            && $this->status === 'DRAFT'
            && $this->approval_status === 'REJECTED';
    }
}
