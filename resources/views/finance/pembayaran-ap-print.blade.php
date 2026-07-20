<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Payment Voucher{{ $noVoucher }}</title>
  @php
    $htmlMode = request()->has('html');

    $fsBody        = $htmlMode ? '16px' : '11px';
    $fsCompanyName = $htmlMode ? '28px' : '20px';
    $fsCompanyAddr = $htmlMode ? '16px' : '11px';
    $fsDocTitle    = $htmlMode ? '26px' : '18px';
    $fsInfoHeader  = $htmlMode ? '16px' : '11px';
    $fsDlTable     = $htmlMode ? '16px' : '11px';

    $fsVendorTitle = $htmlMode ? '15px' : '10.5px';
    $fsItemsTh     = $htmlMode ? '14px' : '10px';
    $fsItemsTd     = $htmlMode ? '15px' : '10px';
    $padItemsTh    = $htmlMode ? '10px 8px' : '5px';
    $padItemsTd    = $htmlMode ? '10px 8px' : '5px';

    $fsFooter  = $htmlMode ? '15px' : '10px';

    $fsTerbilangLbl = $htmlMode ? '14px' : '10px';
    $fsTerbilangVal = $htmlMode ? '16px' : '11px';

    $fsTotals      = $htmlMode ? '16px' : '11px';
    $padTotals     = $htmlMode ? '10px' : '6px';
    $fsTotalsGrand = $htmlMode ? '18px' : '13px';

    $fsSigTitle = $htmlMode ? '15px' : '10px';
    $fsSigName  = $htmlMode ? '16px' : '11px';
    $fsSigRole  = $htmlMode ? '14px' : '10px';
  @endphp
  <style>
    body { font-family: Arial, Helvetica, sans-serif; font-size: {{ $fsBody }}; color: #333; margin: 0; padding: 0; }
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

    .company-name { font-size: {{ $fsCompanyName }}; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #111; }
    .company-address { font-size: {{ $fsCompanyAddr }}; color: #555; line-height: 1.4; }
    .doc-title { text-align: center; font-size: {{ $fsDocTitle }}; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 25px; margin-top: 5px;}

    .divider-thick { border-top: 3px solid #b71c1c; margin-bottom: 3px; }
    .divider-thin { border-top: 1px solid #ccc; margin-bottom: 20px; }

    .info-container { border: 1px solid #ccc; margin-bottom: 25px; }
    .info-header { background: #faa18fa8; border-bottom: 1px solid #ccc; padding: 10px 14px; font-weight: bold; font-size: {{ $fsInfoHeader }}; text-transform: uppercase; color: #111; }
    .info-col { width: 50%; padding: 12px 14px; }
    .info-col-left { border-right: 1px solid #ccc; }

    .dl-table td { padding: 4px 0; font-size: {{ $fsDlTable }}; }
    .dl-lbl { width: 34%; font-weight: bold; color: #555; }
    .dl-colon { width: 5%; text-align: center; }
    .dl-val { width: 61%; color: #111; }

    .vendor-block { margin-bottom: 22px; border: 1px solid #ddd; border-radius: 4px; }
    .vendor-header { background: #f5f5f5; border-bottom: 1px solid #ddd; padding: 10px 14px; }
    .vendor-name { font-size: {{ $fsVendorTitle }}; font-weight: bold; color: #b71c1c; text-transform: uppercase; }
    .vendor-meta { font-size: {{ $fsDlTable }}; color: #555; margin-top: 2px; }

    .items-table { margin: 0; }
    .items-table th { background: #faf5f4; padding: {{ $padItemsTh }}; border-top: 1px solid #eee; border-bottom: 1px solid #ccc; font-size: {{ $fsItemsTh }}; font-weight: bold; text-transform: uppercase; color: #111; }
    .items-table td { padding: {{ $padItemsTd }}; border-bottom: 1px solid #eee; font-size: {{ $fsItemsTd }}; }
    .vendor-subtotal td { background: #fdfdfd; border-top: 1px solid #ddd; font-weight: bold; }

    .col-no { width: 4%; }
    .col-notagihan { width: 16%; }
    .col-noinvoice { width: 16%; }
    .col-tanggal { width: 12%; }
    .col-total { width: 16%; }
    .col-sisasblm { width: 14%; }
    .col-dibayar { width: 14%; }
    .col-sisastlh { width: 14%; }

    .summary-left { width: 55%; padding-right: 30px; }
    .summary-right { width: 45%; }

    .terbilang-box { border: 1px solid #FCC6BB; border-left: 4px solid #b71c1c; padding: 14px; background: #fdfdfd; margin-bottom: 15px; }
    .terbilang-lbl { font-size: {{ $fsTerbilangLbl }}; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 5px; }
    .terbilang-val { font-size: {{ $fsTerbilangVal }}; font-style: italic; font-weight: bold; color: #b71c1c; }

    .note-box { border: 1px solid #fef08a; border-left: 4px solid #facc15; padding: 14px; background: #fffdf0; margin-top: 10px; }
    .note-lbl { font-size: {{ $fsTerbilangLbl }}; font-weight: bold; color: #ca8a04; text-transform: uppercase; margin-bottom: 5px; }
    .note-val { font-size: {{ $fsTerbilangVal }}; color: #854d0e; }

    .totals-table td { padding: {{ $padTotals }}; font-size: {{ $fsTotals }}; border-bottom: 1px solid #eee; }
    .totals-lbl { font-weight: bold; color: #555; width: 45%; }
    .totals-val { font-weight: bold; text-align: right; width: 55%; color: #111; }
    .totals-grand td { border-bottom: 2px solid #b71c1c; background: #fef2f2; padding-top: 12px; padding-bottom: 12px; font-size: {{ $fsTotalsGrand }}; color: #b71c1c; }

    .signatures { margin-top: 40px; text-align: center; }
    .sig-col { width: 50%; padding: 0 10px; }
    .sig-title { font-weight: bold; font-size: {{ $fsSigTitle }}; color: #555; text-transform: uppercase; margin-bottom: 12px; }
    .sig-name { font-weight: bold; font-size: {{ $fsSigName }}; text-decoration: underline; margin-bottom: 4px; }
    .sig-role { font-size: {{ $fsSigRole }}; color: #666; }
    .sig-placeholder { height: 110px; }

    .footer { text-align: center; margin-top: 40px; padding-top: 15px; border-top: 1px solid #ddd; font-size: {{ $fsFooter }}; color: #888; }
  </style>
</head>
<body>

@if($htmlMode)
<div class="toolbar">
  <div class="toolbar-left">
    <div class="toolbar-icon">AP</div>
    <div>
      <div class="toolbar-title">Payment Voucher {{ $noVoucher }}</div>
      <div class="toolbar-sub">{{ \Carbon\Carbon::parse($pembayaran->tanggal_pembayaran)->isoFormat('D MMM YYYY') }} &bull; {{ $vendorGroups->count() }} Vendor</div>
    </div>
  </div>
</div>
@endif

@php
  $firstTagihan = $pembayaran->items->first()?->tagihanAp;
  $perusahaan   = $firstTagihan?->perusahaan;

  $logoPath = public_path('images/sma/logo_sma.jpeg');
  if ($htmlMode) {
      $logoUrl = asset('images/sma/logo_sma.jpeg');
  } else {
      $logoUrl = file_exists($logoPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($logoPath)) : '';
  }
@endphp

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
        <div class="company-name">{{ $perusahaan->nama_perusahaan ?? '-' }}</div>
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

  <div class="doc-title">Payment Voucher</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Payment Voucher</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. Payment Voucher</td><td class="dl-colon">:</td>
              <td class="dl-val font-bold">{{ $noVoucher }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tanggal</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($pembayaran->tanggal_pembayaran)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Metode</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $pembayaran->metode_pembayaran }}</td>
            </tr>
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Dibuat Oleh</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $pembayaran->createdBy?->karyawan?->nama_karyawan ?? $pembayaran->createdBy?->username ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Jml. Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $pembayaran->items->count() }} tagihan &bull; {{ $vendorGroups->count() }} vendor</td>
            </tr>
            <tr>
              <td class="dl-lbl">Total Bayar</td><td class="dl-colon">:</td>
              <td class="dl-val font-bold" style="color:#b71c1c;">Rp {{ number_format((float)$pembayaran->jumlah_pembayaran, 0, ',', '.') }}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  {{-- ======== Rincian per Vendor ======== --}}
  @foreach($vendorGroups as $vendorId => $vendorItems)
    @php $vendor = $vendorItems->first()->tagihanAp?->vendorAp; @endphp
    <div class="vendor-block">
      <div class="vendor-header">
        <span class="vendor-name">{{ $vendor->nama_vendor ?? '-' }}</span>
        <div class="vendor-meta">
          Kode: {{ $vendor->kode_vendor ?? '-' }}
          &bull; NPWP: {{ $vendor->no_npwp ?: '-' }}
          @if($vendor?->bank_no_rekening)
            &bull; {{ $vendor->bank_nama }} {{ $vendor->bank_no_rekening }} a.n {{ $vendor->bank_atas_nama }}
          @endif
        </div>
      </div>
      <table class="items-table">
        <thead>
          <tr>
            <th class="col-no text-center">No</th>
            <th class="col-notagihan text-left">No. Tagihan</th>
            <th class="col-noinvoice text-left">No. Invoice Vendor</th>
            <th class="col-tanggal text-center">Tanggal</th>
            <th class="col-total text-right">Total Tagihan</th>
            <th class="col-sisasblm text-right">Sisa Sebelum</th>
            <th class="col-dibayar text-right">Dibayar</th>
            <th class="col-sisastlh text-right">Sisa Setelah</th>
          </tr>
        </thead>
        <tbody>
          @foreach($vendorItems as $vi => $item)
          <tr>
            <td class="col-no text-center" style="color:#777;">{{ $vi + 1 }}</td>
            <td class="font-bold" style="color:#b71c1c;">{{ $item->tagihanAp?->no_tagihan }}</td>
            <td style="color:#555;">{{ $item->tagihanAp?->no_invoice_vendor ?: '-' }}</td>
            <td class="text-center" style="color:#555;">{{ $item->tagihanAp?->tanggal_tagihan ? \Carbon\Carbon::parse($item->tagihanAp->tanggal_tagihan)->isoFormat('D MMM YYYY') : '-' }}</td>
            <td class="text-right" style="color:#555;">Rp {{ number_format((float)$item->tagihanAp?->total_tagihan, 0, ',', '.') }}</td>
            <td class="text-right" style="color:#555;">Rp {{ number_format((float)$item->sisa_sebelum, 0, ',', '.') }}</td>
            <td class="text-right font-bold">Rp {{ number_format((float)$item->jumlah_dialokasikan, 0, ',', '.') }}</td>
            <td class="text-right">Rp {{ number_format((float)$item->sisa_sesudah, 0, ',', '.') }}</td>
          </tr>
          @endforeach
          <tr class="vendor-subtotal">
            <td colspan="6" class="text-right">Subtotal {{ $vendor->nama_vendor ?? '-' }}</td>
            <td class="text-right">Rp {{ number_format((float)$vendorItems->sum('jumlah_dialokasikan'), 0, ',', '.') }}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>
  @endforeach

  <!-- Summary -->
  <table>
    <tr>
      <td class="summary-left">
        <div class="terbilang-box">
          <div class="terbilang-lbl">Terbilang</div>
          <div class="terbilang-val">"{{ $terbilang }} Rupiah"</div>
        </div>

        @if($pembayaran->keterangan)
        <div class="note-box">
          <div class="note-lbl">Catatan</div>
          <div class="note-val">{{ $pembayaran->keterangan }}</div>
        </div>
        @endif
      </td>
      <td class="summary-right">
        <table class="totals-table">
          <tr class="totals-grand">
            <td class="totals-lbl">TOTAL PEMBAYARAN</td>
            <td class="totals-val">Rp {{ number_format((float)$pembayaran->jumlah_pembayaran, 0, ',', '.') }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Signatures -->
  <table class="signatures">
    <tr>
      <td class="sig-col">
        <div class="sig-title">Dibuat Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">{{ $pembayaran->createdBy?->karyawan?->nama_karyawan ?? $pembayaran->createdBy?->username ?? '___________________' }}</div>
        <div class="sig-role">Staff AP</div>
      </td>
      <td class="sig-col">
        <div class="sig-title">Diketahui / Disetujui</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
        <div class="sig-role">Manager/Supervisor</div>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $perusahaan->nama_perusahaan ?? '-' }}<br>by I.R.O.N System
  </div>

</div>

</body>
</html>
