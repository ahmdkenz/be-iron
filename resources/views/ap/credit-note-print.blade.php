<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>{{ $koreksi->tipe === 'CREDIT_NOTE' ? 'Credit Note' : 'Debit Note' }} {{ $koreksi->no_dokumen }}</title>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 16px;
      color: #333;
      margin: 0;
      padding: 0;
    }
    @page { margin: 30px 40px; }

    @if(request()->has('html'))
    body { background: #e0e4e8; padding: 40px; }
    .print-container { background: #fff; width: 210mm; min-height: 297mm; padding: 15mm 18mm; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin: 0 auto; margin-top: 30px; }
    .toolbar { position: fixed; top: 0; left: 0; right: 0; height: 56px; background: #fff; border-bottom: 1px solid #ccc; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; box-shadow: 0 2px 5px rgba(0,0,0,.08); z-index: 100; }
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
    .font-bold { font-weight: bold; }

    .company-name { font-size: 28px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #111; }
    .company-address { font-size: 16px; color: #555; line-height: 1.4; }
    .doc-title { text-align: center; font-size: 26px; font-weight: bold; letter-spacing: 4px; text-transform: uppercase; margin-bottom: 8px; margin-top: 5px; }
    .doc-no { text-align: center; font-size: 15px; color: #666; margin-bottom: 20px; }

    .divider-thick { border-top: 3px solid #b71c1c; margin-bottom: 3px; }
    .divider-thin { border-top: 1px solid #ccc; margin-bottom: 20px; }

    .info-container { border: 1px solid #ccc; margin-bottom: 25px; }
    .info-header { background: #faa18fa8; border-bottom: 1px solid #ccc; padding: 10px 14px; font-weight: bold; font-size: 16px; text-transform: uppercase; color: #111; }
    .info-col { width: 50%; padding: 12px 14px; }
    .info-col-left { border-right: 1px solid #ccc; }

    .dl-table td { padding: 4px 0; font-size: 16px; }
    .dl-lbl { width: 36%; font-weight: bold; color: #555; }
    .dl-colon { width: 5%; text-align: center; }
    .dl-val { width: 59%; color: #111; }

    .badge { padding: 4px 8px; font-weight: bold; font-size: 13px; border-radius: 4px; border: 1px solid #ccc; text-transform: uppercase; background: #eee; }
    .badge-APPROVED { color: #15803d; border-color: #bbf7d0; background: #f0fdf4; }
    .badge-PENDING { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
    .badge-REJECTED { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }

    /* Nilai box */
    .nilai-box { border: 1px solid #ccc; margin: 25px 0; }
    .nilai-header { padding: 10px 14px; font-weight: bold; font-size: 15px; text-transform: uppercase; color: #111; }
    .nilai-header-cn { background: #fef2f2; border-bottom: 1px solid #fecaca; color: #b71c1c; }
    .nilai-header-dn { background: #eff6ff; border-bottom: 1px solid #bfdbfe; color: #1d4ed8; }
    .nilai-body { padding: 18px 14px; text-align: center; }
    .nilai-amount { font-size: 28px; font-weight: bold; }
    .nilai-amount-cn { color: #b71c1c; }
    .nilai-amount-dn { color: #1d4ed8; }
    .nilai-caption { font-size: 13px; color: #777; margin-top: 6px; }

    /* Item detail table */
    .items-box { border: 1px solid #ccc; margin-bottom: 25px; }
    .items-header { padding: 10px 14px; font-weight: bold; font-size: 15px; text-transform: uppercase; background: #f9fafb; border-bottom: 1px solid #ccc; color: #111; }
    .items-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .items-table th { padding: 8px 10px; font-weight: bold; font-size: 13px; border-bottom: 2px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.03em; text-align: left; background: #f9fafb; color: #555; }
    .items-table th.text-right, .items-table td.text-right { text-align: right; }
    .items-table td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    .items-table tr:last-child td { border-bottom: none; }
    .items-table tfoot td { border-top: 2px solid #e5e7eb; font-weight: bold; padding: 10px 10px; font-size: 15px; }
    .selisih-neg { color: #b71c1c; }
    .selisih-pos { color: #15803d; }

    /* Alasan box */
    .alasan-box { border: 1px solid #fef08a; border-left: 4px solid #facc15; padding: 14px; background: #fffdf0; margin-bottom: 25px; }
    .alasan-lbl { font-size: 14px; font-weight: bold; color: #ca8a04; text-transform: uppercase; margin-bottom: 5px; }
    .alasan-val { font-size: 16px; color: #854d0e; }

    /* Signatures */
    .signatures { margin-top: 40px; text-align: center; }
    .sig-col { width: 33.33%; padding: 0 10px; }
    .sig-title { font-weight: bold; font-size: 15px; color: #555; text-transform: uppercase; margin-bottom: 12px; }
    .sig-name { font-weight: bold; font-size: 16px; text-decoration: underline; margin-bottom: 4px; }
    .sig-role { font-size: 14px; color: #666; }
    .sig-barcode-wrap { min-height: 160px; margin-bottom: 10px; }
    .sig-barcode { display: inline-block; max-width: 100%; }
    .sig-barcode img { width: 150px; height: 150px; }
    .sig-placeholder { height: 160px; }

    /* Footer */
    .footer { text-align: center; margin-top: 50px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 15px; color: #888; }
  </style>
</head>
<body>

@if(request()->has('html'))
<div class="toolbar">
  <div class="toolbar-left">
    <div class="toolbar-icon">{{ $koreksi->tipe === 'CREDIT_NOTE' ? 'CN' : 'DN' }}</div>
    <div>
      <div class="toolbar-title">{{ $koreksi->tipe === 'CREDIT_NOTE' ? 'Credit Note' : 'Debit Note' }} {{ $koreksi->no_dokumen }}</div>
      <div class="toolbar-sub">{{ $vendor?->nama_vendor }} &bull; Ref: {{ $tagihan?->no_tagihan }}</div>
    </div>
  </div>
</div>
@endif

@php
    $logoPath = public_path('images/sma/logo_sma.jpeg');
    if (request()->has('html')) {
        $logoUrl = asset('images/sma/logo_sma.jpeg');
    } else {
        $logoUrl = file_exists($logoPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath)) : '';
    }

    $namaPerusahaan = $tagihan?->perusahaan?->nama_perusahaan
        ?? $koreksi->endingBalanceAp?->perusahaan?->nama_perusahaan
        ?? '';

    $tanggalDokumen = $koreksi->approved_at ?? $koreksi->submitted_at;
    $isCn = $koreksi->tipe === 'CREDIT_NOTE';
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
        <div class="company-name">{{ $namaPerusahaan }}</div>
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

  <div class="doc-title">{{ $isCn ? 'CREDIT NOTE' : 'DEBIT NOTE' }}</div>
  <div class="doc-no">{{ $koreksi->no_dokumen }}</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Dokumen</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. Dokumen</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $koreksi->no_dokumen }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tanggal</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tanggalDokumen ? \Carbon\Carbon::parse($tanggalDokumen)->isoFormat('D MMMM YYYY') : '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
              <td class="dl-val"><span class="badge badge-{{ $koreksi->status }}">{{ $koreksi->status }}</span></td>
            </tr>
            @if($koreksi->dokumen_url)
            <tr>
              <td class="dl-lbl">Dok. Pendukung</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $koreksi->dokumen_url }}</td>
            </tr>
            @endif
          </table>
        </td>
        <td class="info-col">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">Kepada</td><td class="dl-colon">:</td>
              <td class="dl-val"><strong style="color:#b71c1c;">{{ $vendor?->nama_vendor ?? '-' }}</strong></td>
            </tr>
            <tr>
              <td class="dl-lbl">Kode Vendor</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $vendor?->kode_vendor ?? '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">No. NPWP</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $vendor?->no_npwp ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Ref. Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan?->no_tagihan ?? '-' }}</td>
            </tr>
            @if($tagihan?->tanggal_tagihan)
            <tr>
              <td class="dl-lbl">Tgl. Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @endif
          </table>
        </td>
      </tr>
    </table>
  </div>

  <!-- Alasan -->
  <div class="alasan-box">
    <div class="alasan-lbl">Keterangan / Alasan</div>
    <div class="alasan-val">{{ $koreksi->alasan_koreksi }}</div>
  </div>

  <!-- Nilai -->
  <div class="nilai-box">
    <div class="nilai-header {{ $isCn ? 'nilai-header-cn' : 'nilai-header-dn' }}">
      {{ $isCn ? 'Nilai Pengurangan Hutang' : 'Nilai Penambahan Hutang' }}
    </div>
    <div class="nilai-body">
      <div class="nilai-amount {{ $isCn ? 'nilai-amount-cn' : 'nilai-amount-dn' }}">
        Rp {{ number_format(abs((float) $koreksi->nilai_koreksi), 0, ',', '.') }}
      </div>
      <div class="nilai-caption">
        {{ $isCn ? 'Pengurangan atas tagihan' : 'Penambahan atas tagihan' }}
        {{ $tagihan?->no_tagihan ?? '' }}
      </div>
    </div>
  </div>

  <!-- Detail Item (jika ada) -->
  @if($koreksi->items->isNotEmpty())
  <div class="items-box">
    <div class="items-header">Rincian Item {{ $isCn ? 'Pengurangan' : 'Penambahan' }}</div>
    <table class="items-table">
      <thead>
        <tr>
          <th style="width: 30%">Nama Barang</th>
          <th class="text-right" style="width: 8%">Qty Lama</th>
          <th class="text-right" style="width: 13%">Harga Lama</th>
          <th class="text-right" style="width: 12%">Subtotal Lama</th>
          <th class="text-right" style="width: 8%">Qty Baru</th>
          <th class="text-right" style="width: 13%">Harga Baru</th>
          <th class="text-right" style="width: 12%">Subtotal Baru</th>
          <th class="text-right" style="width: 4%">Selisih</th>
        </tr>
      </thead>
      <tbody>
        @foreach($koreksi->items as $item)
        <tr>
          <td>{{ $item->nama_barang }}</td>
          <td class="text-right">{{ rtrim(rtrim(number_format($item->qty_lama, 3, ',', '.'), '0'), ',') }}</td>
          <td class="text-right">{{ number_format($item->harga_satuan_lama, 0, ',', '.') }}</td>
          <td class="text-right">{{ number_format($item->subtotal_lama, 0, ',', '.') }}</td>
          <td class="text-right">{{ rtrim(rtrim(number_format($item->qty_baru, 3, ',', '.'), '0'), ',') }}</td>
          <td class="text-right">{{ number_format($item->harga_satuan_baru, 0, ',', '.') }}</td>
          <td class="text-right">{{ number_format($item->subtotal_baru, 0, ',', '.') }}</td>
          <td class="text-right {{ $item->selisih < 0 ? 'selisih-neg' : 'selisih-pos' }}">
            {{ ($item->selisih >= 0 ? '+' : '') . number_format($item->selisih, 0, ',', '.') }}
          </td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7" class="text-right">Total Selisih:</td>
          <td class="text-right {{ (float)$koreksi->nilai_koreksi < 0 ? 'selisih-neg' : 'selisih-pos' }}">
            {{ ((float)$koreksi->nilai_koreksi >= 0 ? '+' : '') . number_format($koreksi->nilai_koreksi, 0, ',', '.') }}
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  @endif

  <!-- Signatures -->
  <table class="signatures">
    <tr>
      <!-- Disiapkan Oleh -->
      <td class="sig-col">
        <div class="sig-title">Disiapkan Oleh</div>
        @if(!empty($signatureData['prepared_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode">
            <img src="{{ $signatureData['prepared_qr_src'] }}" alt="QR Disiapkan Oleh">
          </div>
        </div>
        @else
        <div class="sig-placeholder"></div>
        @endif
        <div class="sig-name">{{ $signatureData['prepared_by_name'] ?? '___________________' }}</div>
        <div class="sig-role">Pengaju</div>
      </td>

      <!-- Disetujui Oleh -->
      <td class="sig-col">
        <div class="sig-title">Disetujui Oleh</div>
        @if(!empty($signatureData['approved_qr_src']))
        <div class="sig-barcode-wrap">
          <div class="sig-barcode">
            <img src="{{ $signatureData['approved_qr_src'] }}" alt="QR Disetujui Oleh">
          </div>
        </div>
        <div class="sig-name">{{ $signatureData['approved_by_name'] ?? '___________________' }}</div>
        @else
        <div class="sig-placeholder"></div>
        <div class="sig-name">___________________</div>
        @endif
        <div class="sig-role">Manager</div>
      </td>

      <!-- Diterima Oleh -->
      <td class="sig-col">
        <div class="sig-title">Diterima Oleh</div>
        <div class="sig-placeholder"></div>
        <div class="sig-name">{{ $signatureData['received_by_name'] ?? '___________________' }}</div>
        <div class="sig-role">Vendor</div>
      </td>
    </tr>
  </table>

  <!-- Footer -->
  <div class="footer">
    Dicetak pada {{ now()->isoFormat('D MMMM YYYY HH:mm') }} &bull; {{ $namaPerusahaan }}<br>by I.R.O.N System
  </div>

</div>
</body>
</html>
