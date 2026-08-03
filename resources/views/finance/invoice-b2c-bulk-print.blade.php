<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Rekap Tagihan Investor - {{ $payload['investor_nama'] ?? '-' }}</title>
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

    $sigQrSize            = '150px';
    $sigBarcodeMinHeight  = '160px';
    $sigPlaceholderHeight = '160px';

    $fsRekapTh  = $htmlMode ? '14px' : '10px';
    $fsRekapTd  = $htmlMode ? '15px' : '10px';
    $padRekapCell = $htmlMode ? '10px 8px' : '5px';
    $fsRekapGrand = $htmlMode ? '16px' : '11px';

    $fsTerbilangLbl = $htmlMode ? '14px' : '10px';
    $fsTerbilangVal = $htmlMode ? '16px' : '11px';

    $fsTotals      = $htmlMode ? '16px' : '11px';
    $padTotals     = $htmlMode ? '10px' : '6px';
    $fsTotalsGrand = $htmlMode ? '18px' : '13px';
    $fsTotalsSisa  = $htmlMode ? '18px' : '13px';

    $fsSigTitle = $htmlMode ? '15px' : '10px';
    $fsSigName  = $htmlMode ? '16px' : '11px';
    $fsSigRole  = $htmlMode ? '14px' : '10px';
    $fsFooter   = $htmlMode ? '15px' : '10px';

    $logoPath = public_path('images/sma/logo_sma.jpeg');
    if ($htmlMode) {
        $logoUrl = asset('images/sma/logo_sma.jpeg');
    } else {
        $logoUrl = file_exists($logoPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath)) : '';
    }

    $firstInvoice = $invoices->first();
    $entitasPenagih = $firstInvoice?->resolveEntitasPenagih();
    $entitasPenagihName = $entitasPenagih?->nama_perusahaan ?? '-';
  @endphp
  <style>
    body { font-family: Arial, Helvetica, sans-serif; font-size: {{ $fsBody }}; color: #333; margin: 0; padding: 0; }
    @page { margin: 30px 40px; }

    @if($htmlMode)
    body { background: #e0e4e8; padding: 40px; }
    .print-container { background: #fff; width: 210mm; min-height: 297mm; padding: 15mm 18mm; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin: 0 auto; margin-top: 30px; }
    @else
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
    .doc-title { text-align: center; font-size: {{ $fsDocTitle }}; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 25px; margin-top: 5px; }

    .divider-thick { border-top: 3px solid #b71c1c; margin-bottom: 3px; }
    .divider-thin { border-top: 1px solid #ccc; margin-bottom: 20px; }

    .info-container { border: 1px solid #ccc; margin-bottom: 25px; }
    .info-header { background: #faa18fa8; border-bottom: 1px solid #ccc; padding: 10px 14px; font-weight: bold; font-size: {{ $fsInfoHeader }}; text-transform: uppercase; color: #111; }
    .info-col { width: 50%; padding: 12px 14px; }
    .info-col-left { border-right: 1px solid #ccc; }

    .dl-table td { padding: 4px 0; font-size: {{ $fsDlTable }}; }
    .dl-lbl { width: 40%; font-weight: bold; color: #555; }
    .dl-colon { width: 5%; text-align: center; }
    .dl-val { width: 55%; color: #111; }

    .rekap-title { font-size: {{ $fsRekapTh }}; font-weight: bold; text-transform: uppercase; color: #b71c1c; letter-spacing: 1px; margin-bottom: 8px; margin-top: 4px; }
    .rekap-table { margin-bottom: 30px; width: 100%; }
    .rekap-table th { background: #faa18fa8; padding: {{ $padRekapCell }}; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: {{ $fsRekapTh }}; font-weight: bold; text-transform: uppercase; color: #111; }
    .rekap-table td { padding: {{ $padRekapCell }}; border-bottom: 1px solid #eee; font-size: {{ $fsRekapTd }}; vertical-align: top; }
    .rekap-resto-row td { background: #fff7f5; font-weight: bold; color: #b71c1c; border-top: 1px solid #ddd; }
    .rekap-grand-row td { background: #fef2f2; border-top: 2px solid #b71c1c; border-bottom: 2px solid #b71c1c; font-weight: bold; color: #b71c1c; font-size: {{ $fsRekapGrand }}; padding: {{ $padRekapCell }}; }

    .col-no { width: 4%; }
    .col-kode { width: 12%; }
    .col-desc { width: 26%; }
    .col-qty { width: 8%; }
    .col-sat { width: 8%; }
    .col-harga { width: 20%; }
    .col-sub { width: 22%; }

    .items-table { margin-bottom: 30px; }
    .items-table th { background: #faa18fa8; padding: {{ $padItemsTh }}; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: {{ $fsItemsTh }}; font-weight: bold; text-transform: uppercase; color: #111; }
    .items-table td { padding: {{ $padItemsTd }}; border-bottom: 1px solid #eee; font-size: {{ $fsItemsTd }}; }
    .item-desc { font-size: {{ $fsItemDesc }}; color: #666; font-style: italic; margin-top: 6px; display: block; }

    .badge { padding: 2px 6px; font-weight: bold; font-size: 9px; border-radius: 4px; border: 1px solid #ccc; text-transform: uppercase; background: #eee; }
    .badge-TERKIRIM { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
    .badge-SEBAGIAN { color: #c2410c; border-color: #fed7aa; background: #fff7ed; }
    .badge-LUNAS { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }

    .summary-left { width: 55%; padding-right: 30px; }
    .summary-right { width: 45%; }
    .terbilang-box { border: 1px solid #FCC6BB; border-left: 4px solid #b71c1c; padding: 14px; background: #fdfdfd; margin-bottom: 15px; }
    .terbilang-lbl { font-size: {{ $fsTerbilangLbl }}; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 5px; }
    .terbilang-val { font-size: {{ $fsTerbilangVal }}; font-style: italic; font-weight: bold; color: #b71c1c; }

    .totals-table td { padding: {{ $padTotals }}; font-size: {{ $fsTotals }}; border-bottom: 1px solid #eee; }
    .totals-lbl { font-weight: bold; color: #555; width: 45%; }
    .totals-val { font-weight: bold; text-align: right; width: 55%; color: #111; }
    .totals-grand td { border-bottom: none; border-top: 2px solid #ccc; padding-top: 14px; font-size: {{ $fsTotalsGrand }}; color: #000; }
    .totals-sisa td { background: #fef2f2; border-bottom: 2px solid #b71c1c; border-top: 2px solid #b71c1c; padding-top: 12px; padding-bottom: 12px; color: #b71c1c; font-size: {{ $fsTotalsSisa }}; }
    .totals-sisa .totals-val { color: #b71c1c; }

    .signatures { margin-top: 40px; text-align: center; }
    .sig-col { width: 33.33%; padding: 0 10px; }
    .sig-title { font-weight: bold; font-size: {{ $fsSigTitle }}; color: #555; text-transform: uppercase; margin-bottom: 12px; }
    .sig-name { font-weight: bold; font-size: {{ $fsSigName }}; text-decoration: underline; margin-bottom: 4px; }
    .sig-role { font-size: {{ $fsSigRole }}; color: #666; }
    .sig-barcode-wrap { min-height: {{ $sigBarcodeMinHeight }}; margin-bottom: 10px; }
    .sig-barcode { display: inline-block; max-width: 100%; }
    .sig-barcode img { width: {{ $sigQrSize }}; height: {{ $sigQrSize }}; }
    .sig-placeholder { height: {{ $sigPlaceholderHeight }}; }

    .footer { text-align: center; margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; font-size: {{ $fsFooter }}; color: #888; }
  </style>
</head>
<body>

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
        <div class="company-name">{{ $entitasPenagihName }}</div>
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

  <div class="doc-title">Rekap Tagihan Investor</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Rekap</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Investor</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">{{ $payload['investor_nama'] ?? '-' }}</strong></td>
            </tr>
            <tr>
              <td class="dl-lbl">Ditagihkan Melalui</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $payload['klien_anchor_nama'] ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Staff AR</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $payload['pic_ar_nama'] ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Periode</td><td class="dl-colon">:</td>
              <td class="dl-val">
                {{ \Carbon\Carbon::parse($payload['tanggal_dari'])->isoFormat('D MMM YYYY') }}
                s/d
                {{ \Carbon\Carbon::parse($payload['tanggal_sampai'])->isoFormat('D MMM YYYY') }}
              </td>
            </tr>
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Total Invoice</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoices->count() }} invoice</td>
            </tr>
            <tr>
              <td class="dl-lbl">Total Resto/Outlet</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ count($restoGroups) }} outlet</td>
            </tr>
            @php
              $grandTagihan = collect($restoGroups)->sum('subtotal_tagihan');
              $grandBayar   = collect($restoGroups)->sum('subtotal_pembayaran');
              $grandSisa    = collect($restoGroups)->sum('subtotal_sisa');
            @endphp
            <tr>
              <td class="dl-lbl">Total Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">Rp {{ number_format($grandTagihan, 0, ',', '.') }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Total Dibayar</td><td class="dl-colon">:</td>
              <td class="dl-val">Rp {{ number_format($grandBayar, 0, ',', '.') }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Total Sisa Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">Rp {{ number_format($grandSisa, 0, ',', '.') }}</strong></td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  <!-- Rekap per Resto -->
  <div class="rekap-title">Rincian Tagihan per Resto/Outlet</div>
  <table class="rekap-table">
    <thead>
      <tr>
        <th class="text-center" style="width:4%;">No</th>
        <th class="text-left" style="width:16%;">Resto/Outlet</th>
        <th class="text-left" style="width:16%;">No. Invoice</th>
        <th class="text-center" style="width:12%;">Tanggal</th>
        <th class="text-center" style="width:10%;">Status</th>
        <th class="text-right" style="width:14%;">Total Tagihan</th>
        <th class="text-right" style="width:14%;">Dibayar</th>
        <th class="text-right" style="width:14%;">Sisa Tagihan</th>
      </tr>
    </thead>
    <tbody>
      @php $no = 1; @endphp
      @forelse($restoGroups as $group)
      @foreach($group['invoices'] as $gi => $inv)
      <tr>
        <td class="text-center" style="color:#777;">{{ $no++ }}</td>
        <td class="font-bold">{{ $group['nama_resto'] }}</td>
        <td class="font-bold" style="color:#b71c1c;">{{ $inv->no_invoice }}</td>
        <td class="text-center" style="color:#555;">{{ \Carbon\Carbon::parse($inv->tanggal_invoice)->isoFormat('D MMM YYYY') }}</td>
        <td class="text-center"><span class="badge badge-{{ $inv->status }}">{{ $inv->status }}</span></td>
        <td class="text-right" style="color:#555;">Rp {{ number_format((float)$inv->subtotal, 0, ',', '.') }}</td>
        <td class="text-right" style="color:#555;">Rp {{ number_format((float)$inv->total_pembayaran, 0, ',', '.') }}</td>
        <td class="text-right font-bold">Rp {{ number_format(max(0, (float)$inv->subtotal - (float)$inv->total_pembayaran - (float)$inv->total_penyesuaian), 0, ',', '.') }}</td>
      </tr>
      @endforeach
      <tr class="rekap-resto-row">
        <td colspan="5" class="text-right">Subtotal {{ $group['nama_resto'] }}</td>
        <td class="text-right">Rp {{ number_format($group['subtotal_tagihan'], 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($group['subtotal_pembayaran'], 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($group['subtotal_sisa'], 0, ',', '.') }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="8" class="text-center" style="padding:20px; color:#777; font-style:italic;">
          Tidak ada invoice pada periode ini.
        </td>
      </tr>
      @endforelse
    </tbody>
    @if(count($restoGroups) > 0)
    <tfoot>
      <tr class="rekap-grand-row">
        <td colspan="5" class="text-right">GRAND TOTAL</td>
        <td class="text-right">Rp {{ number_format($grandTagihan, 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($grandBayar, 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($grandSisa, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
    @endif
  </table>

  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $entitasPenagihName }}<br>by I.R.O.N System
  </div>

</div>

{{-- ======== HALAMAN 2+: Detail Invoice Lengkap per Invoice ======== --}}
@foreach($invoices as $inv)
@php
  $invSubtotal = (float) $inv->subtotal;
  $invEntitasPenagih = $inv->resolveEntitasPenagih();
  $invEntitasPenagihName = $invEntitasPenagih?->nama_perusahaan ?? '-';
  $invSigData = $signaturesById[$inv->id] ?? [];
@endphp

<div style="page-break-before: always;"></div>

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
        <div class="company-name">{{ $invEntitasPenagihName }}</div>
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

  <div class="doc-title">INVOICE</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Invoice</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. Invoice</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->no_invoice }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tgl. Invoice</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($inv->tanggal_invoice)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @if($inv->tanggal_jatuh_tempo)
            <tr>
              <td class="dl-lbl">Jatuh Tempo</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($inv->tanggal_jatuh_tempo)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">No. Surat Jalan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->no_surat_jalan ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
              <td class="dl-val"><span class="badge badge-{{ $inv->status }}">{{ $inv->status }}</span></td>
            </tr>
            <tr>
              <td class="dl-lbl">Staff AR</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->klienAr->karyawanAr->nama_karyawan ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Penagih</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invEntitasPenagihName }}</td>
            </tr>
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Kepada</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">{{ $inv->klienAr->nama_klien }}</strong></td>
            </tr>
            @if($inv->klienAr?->resto)
            <tr>
              <td class="dl-lbl">Outlet</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->klienAr->resto->nama_resto }} ({{ $inv->klienAr->resto->kode_resto }})</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">No. NPWP</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->klienAr->resto?->investor?->npwp ?: ($inv->klienAr->no_npwp ?: '-') }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Klasifikasi</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $inv->klienAr->tipe_klien ?: '-' }}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  <!-- Items Table -->
  <table class="items-table">
    <thead>
      <tr>
        <th class="col-no text-center">No</th>
        <th class="col-kode text-left">Kode Barang</th>
        <th class="col-desc text-left">Nama Barang</th>
        <th class="col-qty text-center">Qty</th>
        <th class="col-sat text-center">Satuan</th>
        <th class="col-harga text-right">Harga Satuan</th>
        <th class="col-sub text-right">Subtotal</th>
      </tr>
    </thead>
    <tbody>
      @forelse($inv->printItems as $i => $item)
      <tr>
        <td class="col-no text-center" style="color:#777;">{{ $i + 1 }}</td>
        <td class="col-kode" style="color:#555; font-size:14px;">{{ $item->kode_barang ?? $item->barang?->kode_barang ?? '-' }}</td>
        <td class="col-desc">
          <span class="font-bold" style="color:#111;">{{ $item->nama_barang }}</span>
          @if($item->keterangan)<span class="item-desc">{{ $item->keterangan }}</span>@endif
        </td>
        <td class="col-qty text-center">{{ rtrim(rtrim(number_format((float)$item->qty, 4, '.', ''), '0'), '.') }}</td>
        <td class="col-sat text-center" style="color:#555;">{{ $item->satuan }}</td>
        <td class="col-harga text-right">Rp {{ number_format((float)$item->harga_satuan, 0, ',', '.') }}</td>
        <td class="col-sub text-right font-bold">Rp {{ number_format((float)$item->subtotal, 0, ',', '.') }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="7" class="text-center" style="padding: 24px; color: #777; font-style: italic;">
          Tidak ada data barang untuk invoice ini.
        </td>
      </tr>
      @endforelse
    </tbody>
  </table>

  <!-- Summary -->
  <table>
    <tr>
      <td class="summary-left">
        <div class="terbilang-box">
          <div class="terbilang-lbl">Terbilang</div>
          <div class="terbilang-val">"{{ \App\Support\Helpers\Terbilang::convert((int) $invSubtotal) }} Rupiah"</div>
        </div>
        @if($inv->keterangan)
        <div class="note-box" style="border: 1px solid #fef08a; border-left: 4px solid #facc15; padding: 14px; background: #fffdf0;">
          <div style="font-size:10px; font-weight:bold; color:#ca8a04; text-transform:uppercase; margin-bottom:5px;">Catatan Invoice</div>
          <div style="font-size:11px; color:#854d0e;">{{ $inv->keterangan }}</div>
        </div>
        @endif
      </td>
      <td class="summary-right">
        <table class="totals-table">
          <tr>
            <td class="totals-lbl">Total Barang</td>
            <td class="totals-val">Rp {{ number_format($invSubtotal, 0, ',', '.') }}</td>
          </tr>
          <tr class="totals-grand">
            <td class="totals-lbl">GRAND TOTAL</td>
            <td class="totals-val">Rp {{ number_format($invSubtotal, 0, ',', '.') }}</td>
          </tr>
          @if((float)$inv->total_pembayaran > 0)
          <tr>
            <td class="totals-lbl" style="color:#166534;">Sudah Dibayar</td>
            <td class="totals-val" style="color:#166534;">- Rp {{ number_format((float)$inv->total_pembayaran, 0, ',', '.') }}</td>
          </tr>
          @endif
          <tr class="totals-sisa">
            <td class="totals-lbl">SISA BAYAR</td>
            <td class="totals-val">Rp {{ number_format(max(0, $invSubtotal - (float)$inv->total_pembayaran - (float)$inv->total_penyesuaian), 0, ',', '.') }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Signatures -->
  <table class="signatures">
    <tr>
      <td class="sig-col">
        <div class="sig-title">Disiapkan Oleh</div>
        @if(!empty($invSigData['prepared_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode"><img src="{{ $invSigData['prepared_qr_src'] }}" alt="QR verifikasi penyiap dokumen"></div>
        </div>
        @else
        <div class="sig-placeholder"></div>
        @endif
        <div class="sig-name">{{ $invSigData['prepared_by_name'] ?? '___________________' }}</div>
        <div class="sig-role">Staff AR</div>
      </td>
      <td class="sig-col">
        <div class="sig-title">Disetujui Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
      </td>
      <td class="sig-col">
        <div class="sig-title">Diterima Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $invEntitasPenagihName }}<br>by I.R.O.N System
  </div>

</div>
@endforeach

</body>
</html>
