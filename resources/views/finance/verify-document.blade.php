<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verifikasi Dokumen &mdash; {{ $invoice->no_invoice }}</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
      background: #f3f4f6;
      color: #111827;
      min-height: 100vh;
      padding: 24px 16px;
    }

    .card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 6px rgba(0,0,0,.1);
      max-width: 480px;
      margin: 0 auto;
      overflow: hidden;
    }

    .banner {
      padding: 14px 20px;
      font-weight: 700;
      font-size: 12px;
      letter-spacing: .06em;
      text-transform: uppercase;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .banner-invoice  { background: #dbeafe; color: #1e40af; }
    .banner-ob       { background: #ede9fe; color: #5b21b6; }
    .banner-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

    .card-body { padding: 20px; }

    .doc-title { font-size: 20px; font-weight: 700; margin-bottom: 2px; }
    .doc-sub   { font-size: 13px; color: #6b7280; margin-bottom: 20px; }

    .context-label {
      font-size: 12px;
      font-weight: 600;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 4px;
    }
    .context-value {
      font-size: 16px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 20px;
    }

    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 16px 0; }

    .field { margin-bottom: 14px; }
    .field-label {
      font-size: 11px;
      font-weight: 600;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: .05em;
      margin-bottom: 2px;
    }
    .field-value { font-size: 14px; color: #111827; }

    .pill {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }
    .pill-TERKIRIM { background: #eff6ff; color: #1d4ed8; }
    .pill-SEBAGIAN { background: #fff7ed; color: #c2410c; }
    .pill-LUNAS    { background: #f0fdf4; color: #15803d; }
    .pill-DRAFT    { background: #f9fafb; color: #6b7280; border: 1px solid #e5e7eb; }
    .pill-APPROVED { background: #f0fdf4; color: #15803d; }
    .pill-PENDING  { background: #fef3c7; color: #92400e; }
    .pill-REJECTED { background: #fef2f2; color: #b91c1c; }

    .verified-stamp {
      display: flex;
      align-items: center;
      gap: 6px;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 8px;
      padding: 10px 14px;
      margin-bottom: 16px;
      font-size: 13px;
      font-weight: 600;
      color: #15803d;
    }
    .verified-stamp svg { flex-shrink: 0; }

    .card-footer {
      padding: 12px 20px;
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      font-size: 11px;
      color: #9ca3af;
      text-align: center;
    }
  </style>
</head>
<body>
<div class="card">

  {{-- Banner --}}
  @if($invoice->is_opening_balance)
  <div class="banner banner-ob">
    <span class="banner-dot"></span>
    Opening Balance
  </div>
  @else
  <div class="banner banner-invoice">
    <span class="banner-dot"></span>
    Invoice
  </div>
  @endif

  <div class="card-body">

    {{-- Stamp --}}
    <div class="verified-stamp">
      <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
      </svg>
      Dokumen Terverifikasi
    </div>

    {{-- Context: siapa yang disiapkan / disetujui --}}
    @if($context === 'prepared')
      <div class="context-label">Disiapkan Oleh</div>
      <div class="context-value">
        @if($invoice->is_opening_balance)
          {{ ($invoice->submittedBy ?? $invoice->createdBy)?->karyawan?->nama_karyawan
              ?? ($invoice->submittedBy ?? $invoice->createdBy)?->username
              ?? '&mdash;' }}
        @else
          {{ $invoice->klienAr?->karyawanAr?->nama_karyawan ?? '&mdash;' }}
        @endif
      </div>
    @else
      <div class="context-label">Disetujui Oleh</div>
      <div class="context-value">
        @if($invoice->is_opening_balance)
          {{ $invoice->approvedBy?->karyawan?->nama_karyawan
              ?? $invoice->approvedBy?->username
              ?? '&mdash;' }}
        @else
          Direktur &mdash; {{ $invoice->perusahaan?->nama_perusahaan }}
        @endif
      </div>
    @endif

    <hr class="divider">

    {{-- Info dokumen --}}
    <div class="doc-title">{{ $invoice->no_invoice }}</div>
    <div class="doc-sub">{{ $invoice->perusahaan?->nama_perusahaan }}</div>

    <div class="field">
      <div class="field-label">Kepada (Klien)</div>
      <div class="field-value">{{ $invoice->klienAr?->nama_klien }}</div>
    </div>

    <div class="field">
      <div class="field-label">Tanggal Invoice</div>
      <div class="field-value">
        {{ \Carbon\Carbon::parse($invoice->tanggal_invoice)->isoFormat('D MMMM YYYY') }}
      </div>
    </div>

    <div class="field">
      <div class="field-label">Periode</div>
      <div class="field-value">
        {{ \Carbon\Carbon::parse($invoice->periode_awal)->isoFormat('D MMM YYYY') }}
        &ndash;
        {{ \Carbon\Carbon::parse($invoice->periode_akhir)->isoFormat('D MMM YYYY') }}
      </div>
    </div>

    <div class="field">
      <div class="field-label">Status</div>
      <div class="field-value">
        <span class="pill pill-{{ $invoice->status }}">{{ $invoice->status }}</span>
        @if($invoice->is_opening_balance)
          &nbsp;<span class="pill pill-{{ $invoice->approval_status }}">{{ $invoice->approval_status }}</span>
        @endif
      </div>
    </div>

    @if($context === 'approved' && $invoice->is_opening_balance && $invoice->approved_at)
    <div class="field">
      <div class="field-label">Tanggal Persetujuan</div>
      <div class="field-value">
        {{ \Carbon\Carbon::parse($invoice->approved_at)->isoFormat('D MMMM YYYY, HH:mm') }} WIB
      </div>
    </div>
    @endif

    @if($context === 'prepared' && $invoice->is_opening_balance && $invoice->submitted_at)
    <div class="field">
      <div class="field-label">Tanggal Pengajuan</div>
      <div class="field-value">
        {{ \Carbon\Carbon::parse($invoice->submitted_at)->isoFormat('D MMMM YYYY, HH:mm') }} WIB
      </div>
    </div>
    @endif

  </div>

  <div class="card-footer">
    {{ config('app.name') }} &bull; Diverifikasi {{ now()->isoFormat('D MMM YYYY, HH:mm') }}
  </div>

</div>
</body>
</html>
