<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Tagihan AP {{ $tagihan->no_tagihan }}</title>
  @php
    $htmlMode = request()->has('html');

    $fsBody        = $htmlMode ? '16px' : '11px';
    $fsCompanyName = $htmlMode ? '28px' : '20px';
    $fsCompanyAddr = $htmlMode ? '16px' : '11px';
    $fsDocTitle    = $htmlMode ? '26px' : '18px';
    $fsInfoHeader  = $htmlMode ? '16px' : '11px';
    $fsDlTable     = $htmlMode ? '16px' : '11px';

    $fsItemsTh  = $htmlMode ? '15px' : '10.5px';
    $fsItemsTd  = $htmlMode ? '16px' : '10.5px';
    $padItemsTh = $htmlMode ? '12px 8px' : '6px 5px';
    $padItemsTd = $htmlMode ? '12px 8px' : '5px';
    $fsItemDesc = $htmlMode ? '14px' : '9px';

    $fsFooter  = $htmlMode ? '15px' : '10px';
    $fsBadge   = $htmlMode ? '13px' : '9px';
    $padBadge  = $htmlMode ? '4px 8px' : '2px 6px';

    $fsObTitle = $htmlMode ? '14px' : '10px';
    $fsObTh    = $htmlMode ? '14px' : '10px';
    $fsObTd    = $htmlMode ? '15px' : '10px';
    $padObCell = $htmlMode ? '10px 8px' : '5px';
    $fsObGrand = $htmlMode ? '16px' : '11px';

    $fsTerbilangLbl = $htmlMode ? '14px' : '10px';
    $fsTerbilangVal = $htmlMode ? '16px' : '11px';
    $fsNoteLbl      = $htmlMode ? '14px' : '10px';
    $fsNoteVal      = $htmlMode ? '16px' : '11px';

    $fsTotals      = $htmlMode ? '16px' : '11px';
    $padTotals     = $htmlMode ? '10px' : '6px';
    $fsTotalsGrand = $htmlMode ? '18px' : '13px';
    $fsTotalsSisa  = $htmlMode ? '18px' : '13px';

    $fsSigTitle = $htmlMode ? '15px' : '10px';
    $fsSigName  = $htmlMode ? '16px' : '11px';
    $fsSigRole  = $htmlMode ? '14px' : '10px';
  @endphp
  <style>
    /* CSS Dasar (Aman untuk DomPDF) */
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: {{ $fsBody }};
      color: #333;
      margin: 0;
      padding: 0;
    }
    @page { margin: 30px 40px; }

    @if($htmlMode)
    body { background: #e0e4e8; padding: 40px; }
    .print-container { background: #fff; width: 210mm; min-height: 297mm; padding: 15mm 18mm; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin: 0 auto; margin-top: 30px;}
    .toolbar { position: fixed; top: 0; left: 0; right: 0; height: 56px; background: #fff; border-bottom: 1px solid #ccc; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; box-shadow: 0 2px 5px rgba(0,0,0,.08); z-index: 100;}
    .toolbar-left { display: flex; align-items: center; gap: 12px; }
    .toolbar-icon { width: 32px; height: 32px; background: #b71c1c; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; font-weight: bold; }
    .toolbar-title { font-size: 14px; font-weight: bold; }
    .toolbar-sub { font-size: 12px; color: #666; }
    @else
    .toolbar { display: none; }
    .print-container { width: 100%; }
    @endif

    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }

    /* Header */
    .company-name { font-size: {{ $fsCompanyName }}; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #111; }
    .company-address { font-size: {{ $fsCompanyAddr }}; color: #555; line-height: 1.4; }
    .doc-title { text-align: center; font-size: {{ $fsDocTitle }}; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 25px; margin-top: 5px;}

    .divider-thick { border-top: 3px solid #b71c1c; margin-bottom: 3px; }
    .divider-thin { border-top: 1px solid #ccc; margin-bottom: 20px; }

    /* Info Table */
    .info-container { border: 1px solid #ccc; margin-bottom: 25px; }
    .info-header { background: #faa18fa8; border-bottom: 1px solid #ccc; padding: 10px 14px; font-weight: bold; font-size: {{ $fsInfoHeader }}; text-transform: uppercase; color: #111; }
    .info-col { width: 50%; padding: 12px 14px; }
    .info-col-left { border-right: 1px solid #ccc; }

    .dl-table td { padding: 4px 0; font-size: {{ $fsDlTable }}; }
    .dl-lbl { width: 34%; font-weight: bold; color: #555; }
    .dl-colon { width: 5%; text-align: center; }
    .dl-val { width: 61%; color: #111; }

    .dl-row-divider td { border-top: 1px solid #ddd; padding-top: 10px; }
    .dl-row-divider .dl-lbl { color: #888; }

    .badge { padding: {{ $padBadge }}; font-weight: bold; font-size: {{ $fsBadge }}; border-radius: 4px; border: 1px solid #ccc; text-transform: uppercase; background: #eee; }
    .badge-DRAFT { color: #555; border-color: #ddd; background: #f5f5f5; }
    .badge-DITERIMA { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
    .badge-SEBAGIAN { color: #c2410c; border-color: #fed7aa; background: #fff7ed; }
    .badge-LUNAS { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }
    .badge-OB { color: #b71c1c; border-color: #fecaca; background: #fef2f2; }

    /* Items Table */
    .items-table { margin-bottom: 30px; }
    .items-table th { background: #faa18fa8; padding: {{ $padItemsTh }}; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: {{ $fsItemsTh }}; font-weight: bold; text-transform: uppercase; color: #111; }
    .items-table td { padding: {{ $padItemsTd }}; border-bottom: 1px solid #eee; font-size: {{ $fsItemsTd }}; }
    .item-desc { font-size: {{ $fsItemDesc }}; color: #666; font-style: italic; margin-top: 6px; display: block; }

    .col-no { width: 4%; }
    .col-kode { width: 12%; }
    .col-desc { width: 26%; }
    .col-qty { width: 8%; }
    .col-sat { width: 8%; }
    .col-harga { width: 20%; }
    .col-sub { width: 22%; }

    /* Opening Balance Detail Table */
    .ob-section-title { font-size: {{ $fsObTitle }}; font-weight: bold; text-transform: uppercase; color: #b71c1c; letter-spacing: 1px; margin-bottom: 8px; margin-top: 4px; }
    .ob-detail-table { margin-bottom: 30px; width: 100%; }
    .ob-detail-table th { background: #faa18fa8; padding: {{ $padObCell }}; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: {{ $fsObTh }}; font-weight: bold; text-transform: uppercase; color: #111; }
    .ob-detail-table td { padding: {{ $padObCell }}; border-bottom: 1px solid #eee; font-size: {{ $fsObTd }}; vertical-align: top; }
    .ob-detail-no { width: 5%; }
    .ob-detail-invoice { width: 18%; }
    .ob-detail-date { width: 14%; }
    .ob-detail-desc { width: 28%; }
    .ob-detail-jumlah { width: 17%; }
    .ob-detail-sisa { width: 18%; }
    .ob-grand-row td { background: #fef2f2; border-top: 2px solid #b71c1c; border-bottom: 2px solid #b71c1c; font-weight: bold; color: #b71c1c; font-size: {{ $fsObGrand }}; padding: {{ $padObCell }}; }

    /* Summary Section */
    .summary-left { width: 55%; padding-right: 30px; }
    .summary-right { width: 45%; }

    .terbilang-box { border: 1px solid #FCC6BB; border-left: 4px solid #b71c1c; padding: 14px; background: #fdfdfd; margin-bottom: 15px; }
    .terbilang-lbl { font-size: {{ $fsTerbilangLbl }}; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 5px; }
    .terbilang-val { font-size: {{ $fsTerbilangVal }}; font-style: italic; font-weight: bold; color: #b71c1c; }

    .note-box { border: 1px solid #fef08a; border-left: 4px solid #facc15; padding: 14px; background: #fffdf0; }
    .note-lbl { font-size: {{ $fsNoteLbl }}; font-weight: bold; color: #ca8a04; text-transform: uppercase; margin-bottom: 5px; }
    .note-val { font-size: {{ $fsNoteVal }}; color: #854d0e; }

    .totals-table td { padding: {{ $padTotals }}; font-size: {{ $fsTotals }}; border-bottom: 1px solid #eee; }
    .totals-lbl { font-weight: bold; color: #555; width: 45%; }
    .totals-val { font-weight: bold; text-align: right; width: 55%; color: #111; }

    .totals-grand td { border-bottom: none; border-top: 2px solid #ccc; padding-top: 14px; font-size: {{ $fsTotalsGrand }}; color: #000; }
    .totals-sisa td { background: #fef2f2; border-bottom: 2px solid #b71c1c; border-top: 2px solid #b71c1c; padding-top: 12px; padding-bottom: 12px; color: #b71c1c; font-size: {{ $fsTotalsSisa }}; }
    .totals-sisa .totals-val { color: #b71c1c; }

    /* Signatures */
    .signatures { margin-top: 40px; text-align: center; }
    .sig-col { width: 50%; padding: 0 10px; }
    .sig-title { font-weight: bold; font-size: {{ $fsSigTitle }}; color: #555; text-transform: uppercase; margin-bottom: 12px; }
    .sig-name { font-weight: bold; font-size: {{ $fsSigName }}; text-decoration: underline; margin-bottom: 4px; }
    .sig-role { font-size: {{ $fsSigRole }}; color: #666; }
    .sig-placeholder { height: 110px; }
    .sig-barcode-wrap { min-height: 160px; margin-bottom: 10px; }
    .sig-barcode { display: inline-block; max-width: 100%; }
    .sig-barcode img { width: 150px; height: 150px; }

    /* Footer */
    .footer { text-align: center; margin-top: 40px; padding-top: 15px; border-top: 1px solid #ddd; font-size: {{ $fsFooter }}; color: #888; }
  </style>
</head>
<body>

@if($htmlMode)
<div class="toolbar">
  <div class="toolbar-left">
    <div class="toolbar-icon">AP</div>
    <div>
      <div class="toolbar-title">{{ $tagihan->is_opening_balance ? 'Opening Balance AP ' : 'Tagihan AP ' }}{{ $tagihan->no_tagihan }}</div>
      <div class="toolbar-sub">{{ $tagihan->vendorAp->nama_vendor ?? '-' }} &bull; {{ \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->isoFormat('D MMM YYYY') }}</div>
    </div>
  </div>
</div>
@endif

@php
  $logoPath = public_path('images/sma/logo_sma.jpeg');
  if ($htmlMode) {
      $logoUrl = asset('images/sma/logo_sma.jpeg');
  } else {
      $logoUrl = file_exists($logoPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($logoPath)) : '';
  }
@endphp

@if($tagihan->is_opening_balance)

<div class="print-container">

  <!-- Header -->
  <table>
    <tr>
      <td style="width: 20%; vertical-align: middle;">
        @if($logoUrl)
        <img src="{{ $logoUrl }}" style="max-width:80px; max-height:80px;" alt="Logo SMA">
        @endif
      </td>
      <td style="width: 60%; vertical-align: middle;" class="text-center">
        <div class="company-name">{{ $tagihan->perusahaan->nama_perusahaan ?? '-' }}</div>
        <div class="company-address">
          Jl. Moh. Kahfi 1, RT.6/RW.1, Cipedak, Kec. Jagakarsa<br>
          Kota Jakarta Selatan, Daerah Khusus Ibukota Jakarta 12630
        </div>
      </td>
      <td style="width: 20%;"></td>
    </tr>
  </table>

  <div class="divider-thick"></div>
  <div class="divider-thin"></div>

  <div class="doc-title">Saldo Awal AP (Opening Balance)</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Opening Balance</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. OB</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->no_tagihan }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tgl. OB</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @if($tagihan->tanggal_jatuh_tempo)
            <tr>
              <td class="dl-lbl">Jatuh Tempo</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($tagihan->tanggal_jatuh_tempo)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
              <td class="dl-val">
                <span class="badge badge-{{ $tagihan->status }}">{{ $tagihan->status }}</span>
                <span class="badge badge-OB" style="margin-left:4px;">OB</span>
              </td>
            </tr>
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Vendor</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">{{ $tagihan->vendorAp->nama_vendor ?? '-' }}</strong></td>
            </tr>
            <tr>
              <td class="dl-lbl">Kode Vendor</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->vendorAp->kode_vendor ?? '-' }}</td>
            </tr>
            <tr class="dl-row-divider">
              <td class="dl-lbl">No. NPWP</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->vendorAp->no_npwp ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">PIC AP</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->karyawan->nama_karyawan ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Entitas</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->perusahaan->nama_perusahaan ?? '-' }}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  {{-- ======== Rincian Invoice/Tagihan Asal ======== --}}
  <div class="ob-section-title">Rincian Invoice/Tagihan Asal</div>

  @php
    $obDetails  = $tagihan->openingBalanceApDetails ?? collect();
    $obSubtotal = $obDetails->isNotEmpty()
        ? (float) $obDetails->sum('sisa_tagihan_asal')
        : (float) $tagihan->subtotal;
  @endphp

  @if($obDetails->isEmpty())
  {{-- Lump-sum tanpa detail --}}
  <table class="ob-detail-table">
    <thead>
      <tr>
        <th class="ob-detail-no text-center">No</th>
        <th class="ob-detail-desc text-left" colspan="3">Keterangan</th>
        <th class="ob-detail-sisa text-right">Saldo Awal</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="ob-detail-no text-center" style="color:#777;">1</td>
        <td colspan="3">
          <span class="font-bold">{{ $tagihan->keterangan ?: 'Opening Balance' }}</span>
        </td>
        <td class="text-right font-bold">Rp {{ number_format((float)$tagihan->subtotal, 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

  @else
  {{-- Detail per invoice/tagihan asal --}}
  <table class="ob-detail-table">
    <thead>
      <tr>
        <th class="ob-detail-no text-center">No</th>
        <th class="ob-detail-invoice text-left">No. Invoice/Tagihan Asal</th>
        <th class="ob-detail-date text-center">Tanggal</th>
        <th class="ob-detail-desc text-left">Deskripsi</th>
        <th class="ob-detail-jumlah text-right">Jumlah Tagihan</th>
        <th class="ob-detail-sisa text-right">Sisa Tagihan</th>
      </tr>
    </thead>
    <tbody>
      @foreach($obDetails as $di => $detail)
      <tr>
        <td class="ob-detail-no text-center" style="color:#777;">{{ $di + 1 }}</td>
        <td class="ob-detail-invoice font-bold" style="color:#b71c1c;">{{ $detail->no_invoice_asal }}</td>
        <td class="ob-detail-date text-center" style="color:#555;">{{ \Carbon\Carbon::parse($detail->tanggal_invoice_asal)->isoFormat('D MMM YYYY') }}</td>
        <td class="ob-detail-desc">
          <span>{{ $detail->deskripsi }}</span>
          @if($detail->keterangan)<span class="item-desc">{{ $detail->keterangan }}</span>@endif
        </td>
        <td class="ob-detail-jumlah text-right" style="color:#555;">Rp {{ number_format((float)$detail->jumlah_tagihan_asal, 0, ',', '.') }}</td>
        <td class="ob-detail-sisa text-right font-bold">Rp {{ number_format((float)$detail->sisa_tagihan_asal, 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr class="ob-grand-row">
        <td colspan="5" class="text-right">Saldo Awal</td>
        <td class="text-right">Rp {{ number_format($obSubtotal, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>
  @endif

  {{-- ======== Tagihan Bulan Berjalan ======== --}}
  @php
    $totalTagihanPeriode = (float) $tagihanBerjalanInPeriod->sum('total_tagihan');
    $totalSisaPeriode    = (float) $tagihanBerjalanInPeriod->sum('sisa_tagihan');
  @endphp

  <div class="ob-section-title" style="margin-top: 18px;">Tagihan Bulan Berjalan</div>

  <table class="ob-detail-table">
    <thead>
      <tr>
        <th class="ob-detail-no text-center">No</th>
        <th class="ob-detail-invoice text-left">No. Tagihan</th>
        <th class="ob-detail-invoice text-left">No. Invoice Vendor</th>
        <th class="ob-detail-date text-center">Tanggal</th>
        <th class="ob-detail-jumlah text-right">Total Tagihan</th>
        <th class="ob-detail-sisa text-right">Sisa Tagihan</th>
      </tr>
    </thead>
    <tbody>
      @forelse($tagihanBerjalanInPeriod as $ti => $tb)
      <tr>
        <td class="ob-detail-no text-center" style="color:#777;">{{ $ti + 1 }}</td>
        <td class="ob-detail-invoice font-bold" style="color:#b71c1c;">{{ $tb->no_tagihan }}</td>
        <td class="ob-detail-invoice" style="color:#555;">{{ $tb->no_invoice_vendor ?: '-' }}</td>
        <td class="ob-detail-date text-center" style="color:#555;">{{ \Carbon\Carbon::parse($tb->tanggal_tagihan)->isoFormat('D MMM YYYY') }}</td>
        <td class="ob-detail-jumlah text-right" style="color:#555;">Rp {{ number_format((float)$tb->total_tagihan, 0, ',', '.') }}</td>
        <td class="ob-detail-sisa text-right font-bold">Rp {{ number_format((float)$tb->sisa_tagihan, 0, ',', '.') }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="6" class="text-center" style="padding:20px; color:#777; font-style:italic;">
          Tidak ada tagihan reguler dalam periode ini.
        </td>
      </tr>
      @endforelse
    </tbody>
    @if($tagihanBerjalanInPeriod->isNotEmpty())
    <tfoot>
      <tr class="ob-grand-row">
        <td colspan="4" class="text-right">TOTAL TAGIHAN BULAN BERJALAN</td>
        <td class="text-right">Rp {{ number_format($totalTagihanPeriode, 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($totalSisaPeriode, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
    @endif
  </table>

  <!-- Summary -->
  @php
    $obGrandTotal = $obSubtotal + $totalTagihanPeriode;
    $obSisaBayar  = max(0, $obGrandTotal - (float)$tagihan->total_pembayaran - (float)$tagihan->total_penyesuaian);
  @endphp
  <table>
    <tr>
      <td class="summary-left">
        <div class="terbilang-box">
          <div class="terbilang-lbl">Terbilang</div>
          <div class="terbilang-val">"{{ \App\Support\Helpers\Terbilang::convert((int) $obGrandTotal) }} Rupiah"</div>
        </div>

        @if($tagihan->keterangan)
        <div class="note-box">
          <div class="note-lbl">Catatan</div>
          <div class="note-val">{{ $tagihan->keterangan }}</div>
        </div>
        @endif
      </td>
      <td class="summary-right">
        <table class="totals-table">
          <tr>
            <td class="totals-lbl">Saldo Awal</td>
            <td class="totals-val">Rp {{ number_format($obSubtotal, 0, ',', '.') }}</td>
          </tr>
          <tr>
            <td class="totals-lbl">Tagihan Bulan Berjalan</td>
            <td class="totals-val">Rp {{ number_format($totalTagihanPeriode, 0, ',', '.') }}</td>
          </tr>

          <tr class="totals-grand">
            <td class="totals-lbl">GRAND TOTAL</td>
            <td class="totals-val">Rp {{ number_format($obGrandTotal, 0, ',', '.') }}</td>
          </tr>

          @if((float)$tagihan->total_pembayaran > 0)
          <tr>
            <td class="totals-lbl" style="color:#166534;">Sudah Dibayar</td>
            <td class="totals-val" style="color:#166534;">- Rp {{ number_format((float)$tagihan->total_pembayaran, 0, ',', '.') }}</td>
          </tr>
          @endif

          @if((float)$tagihan->total_penyesuaian != 0)
          <tr>
            <td class="totals-lbl">Penyesuaian</td>
            <td class="totals-val">Rp {{ number_format((float)$tagihan->total_penyesuaian, 0, ',', '.') }}</td>
          </tr>
          @endif

          <tr class="totals-sisa">
            <td class="totals-lbl">SISA HUTANG</td>
            <td class="totals-val">Rp {{ number_format($obSisaBayar, 0, ',', '.') }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Signatures -->
  <table class="signatures">
    <tr>
      <td class="sig-col" style="width: 33.33%;">
        <div class="sig-title">Disiapkan Oleh</div>
        @if(!empty($signatureData['prepared_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode"><img src="{{ $signatureData['prepared_qr_src'] }}" alt="QR verifikasi penyiap dokumen"></div>
        </div>
        @else
        <div class="sig-placeholder"></div>
        @endif
        <div class="sig-name">{{ $signatureData['prepared_by_name'] ?? '___________________' }}</div>
        <div class="sig-role">Staff AP</div>
      </td>
      <td class="sig-col" style="width: 33.33%;">
        <div class="sig-title">Disetujui Oleh</div>
        @if(!empty($signatureData['approved_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode"><img src="{{ $signatureData['approved_qr_src'] }}" alt="QR verifikasi penyetuju dokumen"></div>
        </div>
        <div class="sig-name">{{ $signatureData['approved_by_name'] ?? '___________________' }}</div>
        @else
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
        @endif
        <div class="sig-role">Manager/Supervisor</div>
      </td>
      <td class="sig-col" style="width: 33.33%;">
        <div class="sig-title">Diterima Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
        <div class="sig-role">Vendor</div>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $tagihan->perusahaan->nama_perusahaan ?? '-' }}<br>by I.R.O.N System
  </div>

</div>

{{-- ======== HALAMAN 2+: Tagihan AP Bulan Berjalan (Voucher AP lengkap) ======== --}}
@foreach($tagihanBerjalanInPeriod as $tb)
  <div style="page-break-before: always;"></div>
  @include('finance.partials.tagihan-ap-document', [
    'tagihan' => $tb,
    'signatureData' => $tagihanBerjalanSignatureData[$tb->id] ?? [],
  ])
@endforeach

@else

@include('finance.partials.tagihan-ap-document', ['tagihan' => $tagihan, 'signatureData' => $signatureData])

@endif

</body>
</html>
