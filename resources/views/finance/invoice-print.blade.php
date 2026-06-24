<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Invoice {{ $invoice->no_invoice }}</title>
  <style>
    /* CSS Dasar (Aman untuk DomPDF) */
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 16px;
      color: #333;
      margin: 0;
      padding: 0;
    }
    @page { margin: 30px 40px; }

    /* CSS Khusus Preview HTML di Browser */
    @if(request()->has('html'))
    body { background: #e0e4e8; padding: 40px; }
    .print-container { background: #fff; width: 210mm; min-height: 297mm; padding: 15mm 18mm; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin: 0 auto; margin-top: 30px;}
    .toolbar { position: fixed; top: 0; left: 0; right: 0; height: 56px; background: #fff; border-bottom: 1px solid #ccc; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; box-shadow: 0 2px 5px rgba(0,0,0,.08); z-index: 100;}
    .toolbar-left { display: flex; align-items: center; gap: 12px; }
    .toolbar-icon { width: 32px; height: 32px; background: #b71c1c; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; font-weight: bold; }
    .toolbar-title { font-size: 14px; font-weight: bold; }
    .toolbar-sub { font-size: 12px; color: #666; }
    .tbtn { padding: 8px 16px; font-size: 13px; font-weight: bold; border: none; border-radius: 6px; cursor: pointer; color: #fff; background: #b71c1c; }
    @else
    /* Sembunyikan toolbar jika rendering di PDF */
    .toolbar { display: none; }
    .print-container { width: 100%; }
    @endif

    /* Komponen Elemen */
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
    .font-bold { font-weight: bold; }

    /* Header */
    .company-name { font-size: 28px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #111; }
    .company-address { font-size: 16px; color: #555; line-height: 1.4; }
    .doc-title { text-align: center; font-size: 26px; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 25px; margin-top: 5px;}

    .divider-thick { border-top: 3px solid #b71c1c; margin-bottom: 3px; }
    .divider-thin { border-top: 1px solid #ccc; margin-bottom: 20px; }

    /* Info Table */
    .info-container { border: 1px solid #ccc; margin-bottom: 25px; }
    .info-header { background: #faa18fa8; border-bottom: 1px solid #ccc; padding: 10px 14px; font-weight: bold; font-size: 16px; text-transform: uppercase; color: #111; }
    .info-col { width: 50%; padding: 12px 14px; }
    .info-col-left { border-right: 1px solid #ccc; }
    
    .dl-table td { padding: 4px 0; font-size: 16px; }
    .dl-lbl { width: 34%; font-weight: bold; color: #555; }
    .dl-colon { width: 5%; text-align: center; }
    .dl-val { width: 61%; color: #111; }

    .badge { padding: 4px 8px; font-weight: bold; font-size: 13px; border-radius: 4px; border: 1px solid #ccc; text-transform: uppercase; background: #eee; }
    .badge-TERKIRIM { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
    .badge-SEBAGIAN { color: #c2410c; border-color: #fed7aa; background: #fff7ed; }
    .badge-LUNAS { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }

    /* Items Table */
    .items-table { margin-bottom: 30px; }
    .items-table th { background: #faa18fa8; padding: 12px 8px; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: 15px; font-weight: bold; text-transform: uppercase; color: #111; }
    .items-table td { padding: 12px 8px; border-bottom: 1px solid #eee; font-size: 16px; }
    .item-desc { font-size: 14px; color: #666; font-style: italic; margin-top: 6px; display: block; }

    .col-no { width: 4%; }
    .col-kode { width: 12%; }
    .col-desc { width: 26%; }
    .col-qty { width: 8%; }
    .col-sat { width: 8%; }
    .col-harga { width: 20%; }
    .col-sub { width: 22%; }

    /* Opening Balance Detail Table */
    .ob-section-title { font-size: 14px; font-weight: bold; text-transform: uppercase; color: #b71c1c; letter-spacing: 1px; margin-bottom: 8px; margin-top: 4px; }
    .ob-detail-table { margin-bottom: 30px; width: 100%; }
    .ob-detail-table th { background: #faa18fa8; padding: 10px 8px; border-top: 1px solid #ccc; border-bottom: 1px solid #ccc; font-size: 14px; font-weight: bold; text-transform: uppercase; color: #111; }
    .ob-detail-table td { padding: 10px 8px; border-bottom: 1px solid #eee; font-size: 15px; vertical-align: top; }
    .ob-detail-no { width: 5%; }
    .ob-detail-invoice { width: 18%; }
    .ob-detail-date { width: 14%; }
    .ob-detail-desc { width: 28%; }
    .ob-detail-jumlah { width: 17%; }
    .ob-detail-sisa { width: 18%; }
    .ob-detail-row-summary { background: #fffbf0; }
    .ob-detail-row-summary td { border-bottom: 2px solid #ccc; }

    /* OB Grand total row */
    .ob-grand-row td { background: #fef2f2; border-top: 2px solid #b71c1c; border-bottom: 2px solid #b71c1c; font-weight: bold; color: #b71c1c; font-size: 16px; padding: 10px 8px; }

    /* Summary Section */
    .summary-left { width: 55%; padding-right: 30px; }
    .summary-right { width: 45%; }
    
    .terbilang-box { border: 1px solid #FCC6BB; border-left: 4px solid #b71c1c; padding: 14px; background: #fdfdfd; margin-bottom: 15px; }
    .terbilang-lbl { font-size: 14px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 5px; }
    .terbilang-val { font-size: 16px; font-style: italic; font-weight: bold; color: #b71c1c; }

    .note-box { border: 1px solid #fef08a; border-left: 4px solid #facc15; padding: 14px; background: #fffdf0; }
    .note-lbl { font-size: 14px; font-weight: bold; color: #ca8a04; text-transform: uppercase; margin-bottom: 5px; }
    .note-val { font-size: 16px; color: #854d0e; }

    .totals-table td { padding: 10px; font-size: 16px; border-bottom: 1px solid #eee; }
    .totals-lbl { font-weight: bold; color: #555; width: 45%; }
    .totals-val { font-weight: bold; text-align: right; width: 55%; color: #111; }
    
    .totals-grand td { border-bottom: none; border-top: 2px solid #ccc; padding-top: 14px; font-size: 18px; color: #000; }
    .totals-sisa td { background: #fef2f2; border-bottom: 2px solid #b71c1c; border-top: 2px solid #b71c1c; padding-top: 12px; padding-bottom: 12px; color: #b71c1c; font-size: 18px; }
    .totals-sisa .totals-val { color: #b71c1c; }

    /* Signatures */
    .signatures { margin-top: 40px; text-align: center; }
    .sig-col { width: 33.33%; padding: 0 10px; }
    .sig-title { font-weight: bold; font-size: 15px; color: #555; text-transform: uppercase; margin-bottom: 12px; }
    .sig-name { font-weight: bold; font-size: 16px; text-decoration: underline; margin-bottom: 4px; }
    .sig-role { font-size: 14px; color: #666; }
    .sig-barcode-wrap { min-height: 160px; margin-bottom: 10px; }
    .sig-barcode { display: inline-block; max-width: 100%; }
    .sig-barcode img { width: 150px; height: 150px; }
    .sig-barcode-code { margin-top: 4px; font-size: 10px; letter-spacing: 1px; color: #777; }
    .sig-placeholder { height: 160px; }

    /* Footer */
    .footer { text-align: center; margin-top: 50px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 15px; color: #888; }
  </style>
</head>
<body>

@if(request()->has('html'))
<div class="toolbar">
  <div class="toolbar-left">
    <div class="toolbar-icon">IV</div>
    <div>
      <div class="toolbar-title">Invoice {{ $invoice->no_invoice }}</div>
      <div class="toolbar-sub">{{ $invoice->klienAr->nama_klien }} &bull; {{ \Carbon\Carbon::parse($invoice->tanggal_invoice)->isoFormat('D MMM YYYY') }}</div>
    </div>
  </div>
</div>
@endif

<div class="print-container">

  <!-- Header -->
  <table>
    <tr>
      <td style="width: 20%; vertical-align: middle;">
        @php
            // Menyematkan gambar ke Base64 spesifik agar PDF Render (dompdf) tidak gagal menarik resource dari path HTTP
            $logoPath = public_path('images/sma/logo_sma.jpeg');
            if (request()->has('html')) {
                $logoUrl = asset('images/sma/logo_sma.jpeg');
            } else {
                $logoUrl = file_exists($logoPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($logoPath)) : '';
            }
        @endphp
        @if($logoUrl)
        <img src="{{ $logoUrl }}" class="logo-img" style="max-width:80px; max-height:80px;" alt="Logo SMA">
        @endif
      </td>
      <td style="width: 60%; vertical-align: middle;" class="text-center">
        <div class="company-name">
          @if($invoice->klienAr->tipe_klien === 'PT')
            {{ $invoice->karyawan?->perusahaan?->nama_perusahaan ?? $invoice->perusahaan->nama_perusahaan }}
          @else
            {{ $invoice->perusahaan->nama_perusahaan }}
          @endif
        </div>
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

  <div class="doc-title">{{ $invoice->is_opening_balance ? 'SALDO AWAL (OPENING BALANCE)' : 'INVOICE' }}</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Invoice</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. Invoice</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoice->no_invoice }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tgl. Invoice</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($invoice->tanggal_invoice)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @if($invoice->tanggal_jatuh_tempo)
            <tr>
              <td class="dl-lbl">Jatuh Tempo</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($invoice->tanggal_jatuh_tempo)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
              <td class="dl-val">
                <span class="badge badge-{{ $invoice->status }}">{{ $invoice->status }}</span>
                @if($invoice->is_opening_balance)<span class="badge badge-OB" style="margin-left:4px;">OB</span>@endif
              </td>
            </tr>
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Kepada</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">{{ $invoice->klienAr->nama_klien }}</strong> @if($invoice->klienAr->alias)<span style="color:#666;">({{ $invoice->klienAr->alias }})</span>@endif</td>
            </tr>
            @if($invoice->klienAr->perusahaan && $invoice->klienAr->tipe_klien === 'PT')
            <tr>
              <td class="dl-lbl">Penerima Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong>{{ $invoice->klienAr->perusahaan->nama_perusahaan }}</strong></td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">No. NPWP</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoice->klienAr->no_npwp ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tipe Klien</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoice->klienAr->tipe_klien ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Staff AR</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoice->klienAr->karyawanAr->nama_karyawan ?? '-' }}</td>
            </tr>
            @if($invoice->klienAr->tipe_klien === 'PT' && $invoice->resto)
            <tr>
              <td class="dl-lbl">Resto Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $invoice->resto->nama_resto }} ({{ $invoice->resto->kode_resto }})</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">Penagih</td><td class="dl-colon">:</td>
              <td class="dl-val">
                @if($invoice->klienAr->tipe_klien === 'PT')
                  {{ $invoice->karyawan?->perusahaan?->nama_perusahaan ?? '-' }}
                @else
                  {{ $invoice->klienAr->karyawanAr?->perusahaan?->nama_perusahaan ?? $invoice->perusahaan?->nama_perusahaan ?? '-' }}
                @endif
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  @if($invoice->is_opening_balance)
  {{-- ======== OPENING BALANCE: Rincian Invoice Periode Sebelumnya ======== --}}
  <div class="ob-section-title">Rincian Invoice Periode Sebelumnya</div>

  @php
    $obDetails  = $invoice->openingBalanceDetails ?? collect();
    $obSubtotal = $obDetails->isNotEmpty()
        ? (float) $obDetails->sum('sisa_tagihan_asal')
        : (float) $invoice->subtotal;
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
          <span class="font-bold">{{ $invoice->keterangan ?: 'Opening Balance' }}</span>
        </td>
        <td class="text-right font-bold">Rp {{ number_format((float)$invoice->subtotal, 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

  @else
  {{-- Detail per invoice asal --}}
  <table class="ob-detail-table">
    <thead>
      <tr>
        <th class="ob-detail-no text-center">No</th>
        <th class="ob-detail-invoice text-left">No. Invoice Asal</th>
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
        <td colspan="5" class="text-right">Tagihan Periode Sebelumnya</td>
        <td class="text-right">Rp {{ number_format($obSubtotal, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>
  @endif

  {{-- ======== OPENING BALANCE: Invoice Bulan Berjalan ======== --}}
  @php
    $regularInvoicesInPeriod = $regularInvoicesInPeriod ?? collect();
    $totalTagihanPeriode = $regularInvoicesInPeriod->sum('subtotal');
    $totalSisaPeriode    = $regularInvoicesInPeriod->sum(fn($inv) => max(0, (float)$inv->subtotal - (float)$inv->total_pembayaran - (float)$inv->total_penyesuaian));
  @endphp

  <div class="ob-section-title" style="margin-top: 18px;">Invoice Bulan Berjalan</div>

  <table class="ob-detail-table">
    <thead>
      <tr>
        <th class="ob-detail-no text-center">No</th>
        <th class="ob-detail-invoice text-left">No. Invoice</th>
        <th class="ob-detail-invoice text-left">No. Surat Jalan</th>
        <th class="ob-detail-date text-center">Tanggal</th>
        <th class="ob-detail-jumlah text-right">Total Tagihan</th>
        <th class="ob-detail-sisa text-right">Sisa Tagihan</th>
      </tr>
    </thead>
    <tbody>
      @forelse($regularInvoicesInPeriod as $ri => $regInv)
      <tr>
        <td class="ob-detail-no text-center" style="color:#777;">{{ $ri + 1 }}</td>
        <td class="ob-detail-invoice font-bold" style="color:#b71c1c;">{{ $regInv->no_invoice }}</td>
        <td class="ob-detail-invoice" style="color:#555;">{{ $regInv->no_surat_jalan ?: '-' }}</td>
        <td class="ob-detail-date text-center" style="color:#555;">{{ \Carbon\Carbon::parse($regInv->tanggal_invoice)->isoFormat('D MMM YYYY') }}</td>
        <td class="ob-detail-jumlah text-right" style="color:#555;">Rp {{ number_format((float)$regInv->subtotal, 0, ',', '.') }}</td>
        <td class="ob-detail-sisa text-right font-bold">Rp {{ number_format(max(0, (float)$regInv->subtotal - (float)$regInv->total_pembayaran), 0, ',', '.') }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="6" class="text-center" style="padding:20px; color:#777; font-style:italic;">
          Tidak ada invoice reguler dalam periode ini.
        </td>
      </tr>
      @endforelse
    </tbody>
    @if($regularInvoicesInPeriod->isNotEmpty())
    <tfoot>
      <tr class="ob-grand-row">
        <td colspan="4" class="text-right">TOTAL INVOICE BULAN BERJALAN</td>
        <td class="text-right">Rp {{ number_format((float)$totalTagihanPeriode, 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format((float)$totalSisaPeriode, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
    @endif
  </table>

  @else
  {{-- ======== INVOICE BIASA: Item Barang ======== --}}
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
      @forelse($invoice->items as $i => $item)
      <tr>
        <td class="col-no text-center" style="color:#777;">{{ $i + 1 }}</td>
        <td class="col-kode" style="color:#555; font-size:14px;">{{ $item->barang?->kode_barang ?? '-' }}</td>
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
  @endif

  <!-- Summary -->
  @php
    $isOb = $invoice->is_opening_balance;
    $totalBerjalan = isset($totalSisaPeriode) ? (float)$totalSisaPeriode : 0;
    $obGrandTotal  = $isOb ? ($obSubtotal + $totalBerjalan) : 0;
    $obSisaBayar   = $isOb ? max(0, $obGrandTotal - (float)$invoice->total_pembayaran - (float)$invoice->total_penyesuaian) : 0;
    $terbilangAmt  = $isOb ? $obGrandTotal : (float)$invoice->subtotal;
  @endphp
  <table>
    <tr>
      <td class="summary-left">
        <div class="terbilang-box">
          <div class="terbilang-lbl">Terbilang</div>
          <div class="terbilang-val">"{{ \App\Support\Helpers\Terbilang::convert((int) $terbilangAmt) }} Rupiah"</div>
        </div>

        @if($invoice->keterangan)
        <div class="note-box">
          <div class="note-lbl">Catatan Invoice</div>
          <div class="note-val">{{ $invoice->keterangan }}</div>
        </div>
        @endif
      </td>
      <td class="summary-right">
        <table class="totals-table">
          @if($isOb)
          <tr>
            <td class="totals-lbl">Tagihan Periode Sebelumnya</td>
            <td class="totals-val">Rp {{ number_format($obSubtotal, 0, ',', '.') }}</td>
          </tr>
          <tr>
            <td class="totals-lbl">Invoice Bulan Berjalan</td>
            <td class="totals-val">Rp {{ number_format($totalBerjalan, 0, ',', '.') }}</td>
          </tr>
          @else
          <tr>
            <td class="totals-lbl">Total Barang</td>
            <td class="totals-val">Rp {{ number_format((float)$invoice->subtotal, 0, ',', '.') }}</td>
          </tr>
          @if((float)$invoice->tagihan_periode_sebelumnya > 0)
          <tr>
            <td class="totals-lbl">Tagihan Sebelumnya</td>
            <td class="totals-val">Rp {{ number_format((float)$invoice->tagihan_periode_sebelumnya, 0, ',', '.') }}</td>
          </tr>
          @endif
          @endif

          <tr class="totals-grand">
            <td class="totals-lbl">GRAND TOTAL</td>
            <td class="totals-val">Rp {{ number_format($isOb ? $obGrandTotal : (float)$invoice->subtotal, 0, ',', '.') }}</td>
          </tr>

          @if((float)$invoice->total_pembayaran > 0)
          <tr>
            <td class="totals-lbl" style="color:#166534;">Sudah Dibayar</td>
            <td class="totals-val" style="color:#166534;">- Rp {{ number_format((float)$invoice->total_pembayaran, 0, ',', '.') }}</td>
          </tr>
          @endif

          @if((float)$invoice->total_penyesuaian > 0)
          <tr>
            <td class="totals-lbl" style="color:#166534;">Credit Note</td>
            <td class="totals-val" style="color:#166534;">- Rp {{ number_format((float)$invoice->total_penyesuaian, 0, ',', '.') }}</td>
          </tr>
          @endif

          <tr class="totals-sisa">
            <td class="totals-lbl">SISA BAYAR</td>
            <td class="totals-val">Rp {{ number_format($isOb ? $obSisaBayar : max(0, (float)$invoice->subtotal - (float)$invoice->total_pembayaran - (float)$invoice->total_penyesuaian), 0, ',', '.') }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Signatures -->
  <table class="signatures">
    <tr>
      <td class="sig-col" style="width: {{ $invoice->is_opening_balance ? '50%' : '33.33%' }};">
        <div class="sig-title">{{ $invoice->is_opening_balance ? 'Diajukan Oleh' : 'Disiapkan Oleh' }}</div>
        @if(!empty($signatureData['prepared_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode"><img src="{{ $signatureData['prepared_qr_src'] }}" alt="QR verifikasi penyiap dokumen"></div>
        </div>
        @else
        <div class="sig-placeholder"></div>
        @endif
        <div class="sig-name">{{ $signatureData['prepared_by_name'] ?? '___________________' }}</div>
        <div class="sig-role">{{ $invoice->is_opening_balance ? 'Pengaju Opening Balance' : 'Staff AR' }}</div>
      </td>
      @if(!$invoice->is_opening_balance)
      <td class="sig-col">
        <div class="sig-title">Disetujui Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
      </td>
      @endif
      <td class="sig-col" style="width: {{ $invoice->is_opening_balance ? '50%' : '33.33%' }};">
        <div class="sig-title">Diterima Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $invoice->karyawan?->perusahaan?->nama_perusahaan ?? $invoice->perusahaan->nama_perusahaan }}<br>by I.R.O.N System
  </div>

</div>

{{-- ======== HALAMAN 2+: Invoice Reguler Lengkap (hanya untuk print OB) ======== --}}
@if($invoice->is_opening_balance && isset($regularInvoicesSignatureData) && $regularInvoicesInPeriod->isNotEmpty())
  @foreach($regularInvoicesInPeriod as $regInv)
  @php
    $regSigData     = $regularInvoicesSignatureData[$regInv->id] ?? [];
    $regSubtotal    = (float) $regInv->subtotal;
    $regTagihanSblm = (float) $regInv->tagihan_periode_sebelumnya;
  @endphp

  <div style="page-break-before: always;"></div>

  <div class="print-container">

    <!-- Header -->
    <table>
      <tr>
        <td style="width: 20%; vertical-align: middle;">
          @if(!empty($logoUrl))
          <img src="{{ $logoUrl }}" style="max-width:80px; max-height:80px;" alt="Logo SMA">
          @endif
        </td>
        <td style="width: 60%; vertical-align: middle;" class="text-center">
          <div class="company-name">
            @if($regInv->klienAr->tipe_klien === 'PT')
              {{ $regInv->karyawan?->perusahaan?->nama_perusahaan ?? $regInv->perusahaan->nama_perusahaan }}
            @else
              {{ $regInv->perusahaan->nama_perusahaan }}
            @endif
          </div>
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

    <div class="doc-title">
      INVOICE
      @if(!$regInv->is_opening_balance)
      @endif
    </div>

    <!-- Info Box -->
    <div class="info-container">
      <div class="info-header">Informasi Invoice</div>
      <table>
        <tr>
          <td class="info-col info-col-left">
            <table class="dl-table">
              <tr>
                <td class="dl-lbl">No. Invoice</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->no_invoice }}</td>
              </tr>
              <tr>
                <td class="dl-lbl">Tgl. Invoice</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ \Carbon\Carbon::parse($regInv->tanggal_invoice)->isoFormat('D MMMM YYYY') }}</td>
              </tr>
              @if($regInv->tanggal_jatuh_tempo)
              <tr>
                <td class="dl-lbl">Jatuh Tempo</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ \Carbon\Carbon::parse($regInv->tanggal_jatuh_tempo)->isoFormat('D MMMM YYYY') }}</td>
              </tr>
              @endif
              <tr>
                <td class="dl-lbl">No. Surat Jalan</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->no_surat_jalan ?: '-' }}</td>
              </tr>
              <tr>
                <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
                <td class="dl-val"><span class="badge badge-{{ $regInv->status }}">{{ $regInv->status }}</span></td>
              </tr>
            </table>
          </td>
          <td class="info-col">
            <table class="dl-table">
              <tr>
                <td class="dl-lbl">Kepada</td><td class="dl-colon">:</td>
                <td class="dl-val"><strong style="color:#b71c1c;">{{ $regInv->klienAr->nama_klien }}</strong> @if($regInv->klienAr->alias)<span style="color:#666;">({{ $regInv->klienAr->alias }})</span>@endif</td>
              </tr>
              <tr>
                <td class="dl-lbl">No. NPWP</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->klienAr->no_npwp ?: '-' }}</td>
              </tr>
              <tr>
                <td class="dl-lbl">Tipe Klien</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->klienAr->tipe_klien ?: '-' }}</td>
              </tr>
              <tr>
                <td class="dl-lbl">Staff AR</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->klienAr->karyawanAr->nama_karyawan ?? '-' }}</td>
              </tr>
              @if($regInv->klienAr->tipe_klien === 'PT' && $regInv->resto && !$regInv->tanggal_kirim_barang)
              <tr>
                <td class="dl-lbl">Resto Tagihan</td><td class="dl-colon">:</td>
                <td class="dl-val">{{ $regInv->resto->nama_resto }} ({{ $regInv->resto->kode_resto }})</td>
              </tr>
              @endif
              <tr>
                <td class="dl-lbl">Penagih</td><td class="dl-colon">:</td>
                <td class="dl-val">
                  @if($regInv->klienAr->tipe_klien === 'PT')
                    {{ $regInv->karyawan?->perusahaan?->nama_perusahaan ?? '-' }}
                  @else
                    {{ $regInv->klienAr->karyawanAr?->perusahaan?->nama_perusahaan ?? $regInv->perusahaan?->nama_perusahaan ?? '-' }}
                  @endif
                </td>
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
        @forelse($regInv->items as $i => $item)
        <tr>
          <td class="col-no text-center" style="color:#777;">{{ $i + 1 }}</td>
          <td class="col-kode" style="color:#555; font-size:14px;">{{ $item->barang?->kode_barang ?? '-' }}</td>
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
            <div class="terbilang-val">"{{ \App\Support\Helpers\Terbilang::convert((int) $regSubtotal) }} Rupiah"</div>
          </div>
          @if($regInv->keterangan)
          <div class="note-box">
            <div class="note-lbl">Catatan Invoice</div>
            <div class="note-val">{{ $regInv->keterangan }}</div>
          </div>
          @endif
        </td>
        <td class="summary-right">
          <table class="totals-table">
            <tr>
              <td class="totals-lbl">Total Barang</td>
              <td class="totals-val">Rp {{ number_format($regSubtotal, 0, ',', '.') }}</td>
            </tr>
            <tr class="totals-grand">
              <td class="totals-lbl">GRAND TOTAL</td>
              <td class="totals-val">Rp {{ number_format($regSubtotal, 0, ',', '.') }}</td>
            </tr>
            @if((float)$regInv->total_pembayaran > 0)
            <tr>
              <td class="totals-lbl" style="color:#166534;">Sudah Dibayar</td>
              <td class="totals-val" style="color:#166534;">- Rp {{ number_format((float)$regInv->total_pembayaran, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="totals-sisa">
              <td class="totals-lbl">SISA BAYAR</td>
              <td class="totals-val">Rp {{ number_format(max(0, $regSubtotal - (float)$regInv->total_pembayaran), 0, ',', '.') }}</td>
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
          @if(!empty($regSigData['prepared_qr_src']))
          <div class="sig-barcode-wrap">
            <div class="sig-barcode"><img src="{{ $regSigData['prepared_qr_src'] }}" alt="QR verifikasi penyiap dokumen"></div>
          </div>
          @else
          <div class="sig-placeholder"></div>
          @endif
          <div class="sig-name">{{ $regSigData['prepared_by_name'] ?? '___________________' }}</div>
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
      Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $regInv->karyawan?->perusahaan?->nama_perusahaan ?? $regInv->perusahaan->nama_perusahaan }}<br>by I.R.O.N System
    </div>

  </div>
  @endforeach
@endif

</body>
</html>
