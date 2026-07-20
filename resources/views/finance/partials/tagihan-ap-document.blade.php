{{-- Dokumen Tagihan AP tunggal — dipakai untuk cetak Tagihan AP reguler standalone
     maupun tiap halaman "Tagihan Bulan Berjalan" yang dilampirkan di cetak Opening Balance AP.
     Variabel yang wajib di-pass: $tagihan (TagihanAp), $signatureData (array dari buildSignatureData()). --}}

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

  <div class="doc-title">Tagihan AP</div>

  <!-- Info Box -->
  <div class="info-container">
    <div class="info-header">Informasi Tagihan</div>
    <table>
      <tr>
        <td class="info-col info-col-left">
          <table class="dl-table">
            <tr>
              <td class="dl-lbl">No. Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->no_tagihan }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">No. Invoice Vendor</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->no_invoice_vendor ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Tgl. Tagihan</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($tagihan->tanggal_tagihan)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @if($tagihan->tanggal_jatuh_tempo)
            <tr>
              <td class="dl-lbl">Jatuh Tempo</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ \Carbon\Carbon::parse($tagihan->tanggal_jatuh_tempo)->isoFormat('D MMMM YYYY') }}</td>
            </tr>
            @endif
            <tr>
              <td class="dl-lbl">No. PO</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->no_po ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">No. Terima Barang</td><td class="dl-colon">:</td>
              <td class="dl-val">{{ $tagihan->no_terima_barang ?: '-' }}</td>
            </tr>
            <tr>
              <td class="dl-lbl">Status</td><td class="dl-colon">:</td>
              <td class="dl-val"><span class="badge badge-{{ $tagihan->status }}">{{ $tagihan->status }}</span></td>
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

  <!-- Item Tagihan -->
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
      @forelse($tagihan->items as $i => $item)
      <tr>
        <td class="col-no text-center" style="color:#777;">{{ $i + 1 }}</td>
        <td class="col-kode" style="color:#555; font-size:14px;">{{ $item->kode_barang ?: '-' }}</td>
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
          Tidak ada data barang untuk tagihan ini.
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
          <div class="terbilang-val">"{{ \App\Support\Helpers\Terbilang::convert((int) $tagihan->total_tagihan) }} Rupiah"</div>
        </div>

        @if($tagihan->keterangan)
        <div class="note-box">
          <div class="note-lbl">Catatan Tagihan</div>
          <div class="note-val">{{ $tagihan->keterangan }}</div>
        </div>
        @endif
      </td>
      <td class="summary-right">
        <table class="totals-table">
          <tr>
            <td class="totals-lbl">Subtotal</td>
            <td class="totals-val">Rp {{ number_format((float)$tagihan->subtotal, 0, ',', '.') }}</td>
          </tr>
          <tr>
            <td class="totals-lbl">PPN Masukan</td>
            <td class="totals-val">Rp {{ number_format((float)$tagihan->ppn_masukan, 0, ',', '.') }}</td>
          </tr>
          @if((float)$tagihan->pph23 > 0)
          <tr>
            <td class="totals-lbl">PPh 23</td>
            <td class="totals-val">- Rp {{ number_format((float)$tagihan->pph23, 0, ',', '.') }}</td>
          </tr>
          @endif

          <tr class="totals-grand">
            <td class="totals-lbl">TOTAL TAGIHAN</td>
            <td class="totals-val">Rp {{ number_format((float)$tagihan->total_tagihan, 0, ',', '.') }}</td>
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
            <td class="totals-lbl">SISA TAGIHAN</td>
            <td class="totals-val">Rp {{ number_format((float)$tagihan->sisa_tagihan, 0, ',', '.') }}</td>
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
      <td class="sig-col">
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
