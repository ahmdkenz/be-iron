<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Rekening Koran - {{ $report['klien']['nama_klien'] }}</title>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 11px;
      color: #333;
      margin: 0;
      padding: 0;
    }
    @page { margin: 20px 30px; }

    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: middle; }

    .text-center { text-align: center; }
    .text-right  { text-align: right; }
    .text-left   { text-align: left; }
    .font-bold   { font-weight: bold; }

    /* Header */
    .doc-title {
      text-align: center;
      font-size: 18px;
      font-weight: bold;
      letter-spacing: 3px;
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .divider-thick { border-top: 2px solid #0D47A1; margin-bottom: 2px; }
    .divider-thin  { border-top: 1px solid #ccc; margin-bottom: 12px; }

    /* Info klien */
    .info-table td { padding: 2px 0; font-size: 11px; }
    .info-lbl  { width: 120px; font-weight: bold; color: #555; }
    .info-colon { width: 12px; text-align: center; }
    .info-val  { color: #111; }

    /* Summary box */
    .summary-box {
      border: 1px solid #1565C0;
      border-radius: 4px;
      margin: 10px 0;
      padding: 0;
      overflow: hidden;
    }
    .summary-box table td {
      padding: 6px 10px;
      border-right: 1px solid #BBDEFB;
      text-align: center;
    }
    .summary-box table td:last-child { border-right: none; }
    .summary-label { font-size: 9px; color: #555; }
    .summary-value { font-size: 13px; font-weight: bold; color: #0D47A1; }

    /* Transaksi tabel */
    .trx-table { margin-top: 10px; }
    .trx-table th {
      background: #1565C0;
      color: #fff;
      font-size: 10px;
      padding: 5px 6px;
      text-align: center;
      border: 1px solid #0D47A1;
    }
    .trx-table td {
      padding: 4px 6px;
      font-size: 10px;
      border: 1px solid #CFD8DC;
    }
    .trx-table tr.saldo-awal td {
      background: #E8EAF6;
      font-weight: bold;
      color: #1A237E;
    }
    .trx-table tr.saldo-akhir td {
      background: #E8EAF6;
      font-weight: bold;
      color: #1A237E;
      border-top: 2px solid #1565C0;
    }
    .trx-table tr.row-even td { background: #E3F2FD; }
    .trx-table tr.row-odd  td { background: #ffffff; }
    .debit  { color: #E65100; }
    .kredit { color: #2E7D32; }

    /* Footer */
    .footer { margin-top: 16px; font-size: 9px; color: #999; text-align: center; }
  </style>
</head>
<body>

  <div class="doc-title">Rekening Koran</div>
  <div class="divider-thick"></div>
  <div class="divider-thin"></div>

  <!-- Info Klien & Periode -->
  <table style="margin-bottom: 10px;">
    <tr>
      <td style="width:50%;">
        <table class="info-table">
          <tr>
            <td class="info-lbl">Nama Klien</td>
            <td class="info-colon">:</td>
            <td class="info-val"><strong>{{ $report['klien']['nama_klien'] }}</strong></td>
          </tr>
          <tr>
            <td class="info-lbl">Kode Klien</td>
            <td class="info-colon">:</td>
            <td class="info-val">{{ $report['klien']['kode_klien'] }}</td>
          </tr>
          @if($report['klien']['perusahaan'])
          <tr>
            <td class="info-lbl">Entitas</td>
            <td class="info-colon">:</td>
            <td class="info-val">{{ $report['klien']['perusahaan'] }}</td>
          </tr>
          @endif
        </table>
      </td>
      <td style="width:50%; text-align:right;">
        <table class="info-table" style="float:right;">
          <tr>
            <td class="info-lbl">Periode</td>
            <td class="info-colon">:</td>
            <td class="info-val">{{ $report['periode_awal'] }} s/d {{ $report['periode_akhir'] }}</td>
          </tr>
          <tr>
            <td class="info-lbl">Dicetak</td>
            <td class="info-colon">:</td>
            <td class="info-val">{{ $printed_at }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- Summary -->
  <div class="summary-box">
    <table>
      <tr>
        <td>
          <div class="summary-label">Saldo Awal</div>
          <div class="summary-value">Rp {{ number_format($report['saldo_awal'], 0, ',', '.') }}</div>
        </td>
        <td>
          <div class="summary-label">Total Debit</div>
          <div class="summary-value" style="color:#E65100;">Rp {{ number_format($report['total_debit'], 0, ',', '.') }}</div>
        </td>
        <td>
          <div class="summary-label">Total Kredit</div>
          <div class="summary-value" style="color:#2E7D32;">Rp {{ number_format($report['total_kredit'], 0, ',', '.') }}</div>
        </td>
        <td>
          <div class="summary-label">Saldo Akhir</div>
          <div class="summary-value" style="color:{{ $report['saldo_akhir'] > 0 ? '#B71C1C' : '#2E7D32' }};">
            Rp {{ number_format($report['saldo_akhir'], 0, ',', '.') }}
          </div>
        </td>
      </tr>
    </table>
  </div>

  <!-- Tabel Transaksi -->
  <table class="trx-table">
    <thead>
      <tr>
        <th style="width:30px;">No</th>
        <th style="width:70px;">Tanggal</th>
        <th style="width:100px;">No. Dokumen</th>
        <th>Keterangan</th>
        <th style="width:90px; text-align:right;">Debit</th>
        <th style="width:90px; text-align:right;">Kredit</th>
        <th style="width:90px; text-align:right;">Saldo</th>
      </tr>
    </thead>
    <tbody>
      <tr class="saldo-awal">
        <td colspan="4" class="text-center">SALDO AWAL</td>
        <td class="text-right">-</td>
        <td class="text-right">-</td>
        <td class="text-right">Rp {{ number_format($report['saldo_awal'], 0, ',', '.') }}</td>
      </tr>
      @forelse($report['rows'] as $i => $row)
      <tr class="{{ $i % 2 === 0 ? 'row-odd' : 'row-even' }}">
        <td class="text-center">{{ $i + 1 }}</td>
        <td class="text-center">{{ $row['tanggal'] }}</td>
        <td>{{ $row['no_dokumen'] }}</td>
        <td>{{ $row['keterangan'] }}</td>
        <td class="text-right {{ $row['debit'] > 0 ? 'debit' : '' }}">
          {{ $row['debit'] > 0 ? 'Rp ' . number_format($row['debit'], 0, ',', '.') : '-' }}
        </td>
        <td class="text-right {{ $row['kredit'] > 0 ? 'kredit' : '' }}">
          {{ $row['kredit'] > 0 ? 'Rp ' . number_format($row['kredit'], 0, ',', '.') : '-' }}
        </td>
        <td class="text-right font-bold" style="color:{{ $row['saldo'] > 0 ? '#B71C1C' : '#2E7D32' }};">
          Rp {{ number_format($row['saldo'], 0, ',', '.') }}
        </td>
      </tr>
      @empty
      <tr>
        <td colspan="7" class="text-center" style="padding:20px; color:#999;">
          Tidak ada transaksi dalam periode ini
        </td>
      </tr>
      @endforelse
      <tr class="saldo-akhir">
        <td colspan="4" class="font-bold">TOTAL / SALDO AKHIR</td>
        <td class="text-right debit">Rp {{ number_format($report['total_debit'], 0, ',', '.') }}</td>
        <td class="text-right kredit">Rp {{ number_format($report['total_kredit'], 0, ',', '.') }}</td>
        <td class="text-right" style="color:{{ $report['saldo_akhir'] > 0 ? '#B71C1C' : '#2E7D32' }};">
          Rp {{ number_format($report['saldo_akhir'], 0, ',', '.') }}
        </td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    Dokumen ini digenerate otomatis oleh sistem &mdash; {{ $printed_at }}
  </div>

</body>
</html>
