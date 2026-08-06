<?php

namespace App\Domain\Finance\OpeningBalance\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Models\Barang;
use App\Models\Invoice;
use App\Models\KlienAr;
use App\Models\OpeningBalanceImportBatch;
use App\Models\Resto;
use App\Support\Helpers\ImportDateParser;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Memproses import Opening Balance AR dari file CSV/XLSX yang diupload lewat tab
 * "Import Master Opening Balance" (halaman Import Master Data).
 *
 * Desain FLAT & AUTO-GROUP (meniru pola Import Master Invoice) — 1 baris = 1 invoice
 * historis. `no_urut` adalah KUNCI GRUP (boleh berulang di banyak baris untuk klien yang
 * sama, TIDAK unik per baris): semua baris dengan `no_urut` sama digabung jadi 1 Opening
 * Balance. Identitas klien (nama_klien/kode_resto/nama_resto/tipe_klien) hanya diambil
 * dari baris PERTAMA tiap grup — baris berikutnya boleh mengosongkan/mengulang kolom itu,
 * tidak divalidasi silang (tahan typo, lihat buildDetailsForGroup()).
 *
 * Aturan agregasi per grup (didorong oleh constraint DB tb_opening_balance_detail:
 * no_invoice_asal & tanggal_invoice_asal NOT NULL):
 *   - 1 baris & no_invoice_asal kosong -> lump sum TANPA rincian (details=[]), perilaku
 *     lama dipertahankan persis untuk backfill saldo agregat.
 *   - selain itu (>1 baris, atau 1 baris dengan no_invoice_asal terisi) -> setiap baris
 *     WAJIB no_invoice_asal + tanggal_invoice_asal, masing-masing jadi 1 detail record.
 *     Baris yang gagal syarat itu dilaporkan sbg error baris (bukan menggagalkan grup).
 *
 * Tanggal header OB (tanggal_invoice) TIDAK lagi dibaca dari file — diambil dari
 * `$batch->cutover_date` (diisi user 1x di form upload, seragam untuk seluruh batch).
 * Rasional: baris-baris dalam 1 grup punya tanggal_invoice_asal yang bisa berbeda jauh
 * (lintas tahun), jadi tidak representatif dipakai sebagai "tanggal saldo dicatat".
 *
 * Kedua jalur parsing (parseCsv/parseXlsx) menghasilkan struktur identik
 * ($obRows keyed by no_urut -> list baris, $rawItems keyed by "no_urut|lower(no_invoice_asal)")
 * supaya logic agregasi (buildDetailsForGroup) tidak dobel dan hasil akhirnya konsisten
 * apa pun format filenya.
 *
 * Partial-write (BEDA dari desain lama yang all-or-nothing): setiap grup OB memanggil
 * InvoiceService::createOpeningBalance() satu per satu — method itu membungkus
 * transaksinya sendiri, jadi grup yang gagal dicatat sebagai error dan dilewati, grup
 * lain tetap diproses.
 */
class OpeningBalanceImportService
{
    private const MAX_STORED_ERRORS = 200;

    /**
     * Kode error kalkulasi Excel standar → penjelasan bahasa awam. Rumus yang gagal (mis. =A1/0)
     * dikembalikan PhpSpreadsheet sebagai string kode ini (bukan exception), jadi kalau tidak
     * dideteksi khusus akan lolos ke importNum()/importDate() dan diam-diam jadi 0/null.
     * Hanya relevan untuk jalur XLSX — CSV dibaca via fgetcsv() sehingga tidak pernah berisi rumus.
     */
    private const EXCEL_FORMULA_ERRORS = [
        '#DIV/0!'       => 'rumus melakukan pembagian dengan angka nol',
        '#REF!'         => 'rumus merujuk ke sel/baris/kolom yang sudah dihapus atau tidak valid',
        '#VALUE!'       => 'rumus mendapat jenis data yang salah (misalnya teks dihitung seperti angka)',
        '#NAME?'        => 'rumus menggunakan nama fungsi atau referensi yang tidak dikenali Excel',
        '#NULL!'        => 'rumus merujuk ke perpotongan sel yang tidak valid',
        '#NUM!'         => 'rumus menghasilkan angka yang tidak valid (terlalu besar/kecil atau tidak masuk akal)',
        '#N/A'          => 'rumus tidak menemukan data yang dicari (misalnya VLOOKUP gagal menemukan hasil)',
        '#GETTING_DATA' => 'rumus sedang mengambil data dari sumber luar yang tidak tersedia',
        '#SPILL!'       => 'hasil rumus tumpah ke sel lain yang sudah terisi data',
        '#CALC!'        => 'rumus gagal dihitung oleh Excel',
    ];

    private const CSV_TIPE_OB = 'OB';

    private const CSV_TIPE_ITEM = 'ITEM';

    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function process(OpeningBalanceImportBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (! $batch->file_path || ! $disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        abort_if(! $batch->cutover_date, 422, 'Batch import tidak memiliki tanggal cutover.');
        $cutoverDate = $batch->cutover_date->format('Y-m-d');

        $batch->update(['status' => 'processing']);

        $filePath = $disk->path($batch->file_path);
        $ext = strtolower(pathinfo($batch->original_filename ?: $batch->file_path, PATHINFO_EXTENSION));
        $isCsv = ! in_array($ext, ['xlsx', 'xls'], true);

        [$obRows, $rawItems, $errors] = $isCsv ? $this->parseCsv($filePath) : $this->parseXlsx($filePath);

        $batch->update(['total_ob' => count($obRows)]);

        [$ptNamaGroups, $restoMap] = $this->buildKlienMapsForOb();
        $restoMasterMap = $this->buildRestoMasterMap();

        $barangByKode = Barang::whereNotNull('kode_barang')->get(['id', 'kode_barang'])
            ->keyBy(fn (Barang $b) => strtolower($b->kode_barang));
        $barangByNama = Barang::all(['id', 'nama_barang'])
            ->keyBy(fn (Barang $b) => strtolower(trim($b->nama_barang)));

        $failedOb = 0;
        $processed = 0;

        // Pass 1 — resolve identitas PER GRUP no_urut, lalu kumpulkan rows ke bucket per
        // klien_id HASIL RESOLUSI (bukan per no_urut). Ini krusial untuk PT/B2B: data nyata
        // memberi no_urut per (PT + resto) — puluhan/ratusan no_urut bisa resolve ke klien PT
        // yang SAMA. Kalau langsung build+create per no_urut seperti sebelumnya, cuma grup
        // PERTAMA yang berhasil (grup berikutnya kena "duplikat klien+tanggal" dan di-skip
        // diam-diam — datanya hilang, bukan cuma kehilangan info resto).
        $buckets = [];
        foreach ($obRows as $noUrut => $groupRows) {
            $processed++;
            if ($processed % 50 === 0) {
                $batch->update(['processed_ob' => $processed, 'failed_ob' => $failedOb]);
            }

            $identity = $groupRows[0];

            $masterError = $this->validateRowAgainstMasterData($identity['tipe_klien'], $identity['kode_resto'], $restoMasterMap);
            if ($masterError) {
                $errors[] = ['sheet' => $identity['sheet'], 'row' => $identity['source_row'], 'message' => $masterError];
                $failedOb++;

                continue;
            }

            $resolved = $this->resolveKlienForOb($identity['tipe_klien'], $identity['nama_klien'], $identity['kode_resto'], $ptNamaGroups, $restoMap);
            if (! $resolved['klien']) {
                $errors[] = ['sheet' => $identity['sheet'], 'row' => $identity['source_row'], 'message' => $resolved['error']];
                $failedOb++;

                continue;
            }
            $klien = $resolved['klien'];

            $buckets[$klien->id] ??= ['klien' => $klien, 'sheet' => $identity['sheet'], 'source_row' => $identity['source_row'], 'rows' => []];
            $buckets[$klien->id]['rows'] = [...$buckets[$klien->id]['rows'], ...$groupRows];
        }

        $batch->update(['processed_ob' => count($obRows), 'failed_ob' => $failedOb]);

        // Pass 2 — 1 Opening Balance per klien (bucket), dari rows gabungan semua no_urut
        // asalnya. inserted_ob/skipped_ob sekarang dihitung di level klien final — jumlahnya
        // BISA lebih kecil dari jumlah no_urut mentah kalau banyak no_urut resolve ke klien
        // yang sama (itu benar, bukan bug).
        $insertedOb = $skippedOb = 0;
        $insertedDetail = $insertedItem = 0;

        foreach ($buckets as $bucket) {
            $klien = $bucket['klien'];

            $built = $this->buildDetailsForGroup($bucket['rows'], $rawItems, $barangByKode, $barangByNama, $errors);
            if ($built === null) {
                $errors[] = [
                    'sheet' => $bucket['sheet'],
                    'row' => $bucket['source_row'],
                    'message' => "Klien '{$klien->nama_klien}': tidak ada baris valid untuk membentuk Opening Balance (semua baris gagal validasi no_invoice_asal/tanggal_invoice_asal).",
                ];
                $failedOb++;

                continue;
            }

            $exists = Invoice::where('klien_ar_id', $klien->id)
                ->where('is_opening_balance', true)
                ->whereDate('tanggal_invoice', $cutoverDate)
                ->exists();
            if ($exists) {
                $skippedOb++;

                continue;
            }

            try {
                $data = [
                    'no_invoice' => $this->invoiceService->generateOpeningBalanceNoInvoice($klien, $cutoverDate),
                    'tanggal' => $cutoverDate,
                    'klien_ar_id' => $klien->id,
                    'saldo_awal' => $built['saldo_awal'],
                    'keterangan' => $built['keterangan'] ?: 'Opening Balance (Import)',
                    'details' => $built['details'],
                ];

                $this->invoiceService->createOpeningBalance($data, notify: false);
                $insertedOb++;
                $insertedDetail += count($built['details']);
                $insertedItem += array_sum(array_map(fn ($d) => count($d['items']), $built['details']));
            } catch (Throwable $e) {
                $errors[] = ['sheet' => $bucket['sheet'], 'row' => $bucket['source_row'], 'message' => 'Gagal menyimpan: '.$e->getMessage()];
                $failedOb++;
            }
        }

        // Item yang no_urut+no_invoice_asal tidak cocok baris manapun (di grup manapun) → orphan.
        foreach ($rawItems as $orphanItems) {
            foreach ($orphanItems as $it) {
                $errors[] = [
                    'sheet' => 'Item Invoice Asal',
                    'row' => $it['source_row'],
                    'message' => 'no_urut + no_invoice_asal tidak cocok dengan baris manapun di Data Opening Balance.',
                ];
            }
        }

        if (count($errors) > self::MAX_STORED_ERRORS) {
            $errors = array_slice($errors, 0, self::MAX_STORED_ERRORS);
        }

        $batch->update([
            'processed_ob' => count($obRows),
            'inserted_ob' => $insertedOb,
            'skipped_ob' => $skippedOb,
            'failed_ob' => $failedOb,
            'total_detail' => $insertedDetail,
            'inserted_detail' => $insertedDetail,
            'total_item' => $insertedItem,
            'inserted_item' => $insertedItem,
            'errors' => $errors,
            'status' => 'completed',
            'message' => sprintf(
                'Opening Balance +%d ⊘%d ✗%d | Rincian Invoice Asal +%d | Item +%d',
                $insertedOb, $skippedOb, $failedOb, $insertedDetail, $insertedItem,
            ),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Agregasi 1 grup no_urut -> 1 Opening Balance (+ rincian opsional)
    // ──────────────────────────────────────────────────────────────

    /**
     * Terapkan aturan agregasi (lihat docblock kelas). $rawItems dikonsumsi (key dihapus)
     * saat item berhasil dilampirkan ke detail terkait — sisa $rawItems setelah SEMUA grup
     * diproses oleh caller = item orphan.
     *
     * $groupRows di sini adalah rows GABUNGAN dari semua no_urut yang resolve ke klien yang
     * sama (lihat process()) — tiap row tetap membawa `no_urut` ASAL-nya sendiri (dipakai
     * untuk item-key), karena item di sheet "Item Invoice Asal" merujuk ke no_urut asal,
     * bukan ke klien hasil merge.
     *
     * @param  array<int,array>  $groupRows
     * @param  array<string,array>  $rawItems  keyed by "no_urut|lower(no_invoice_asal)", by-ref (dikonsumsi)
     * @return array{details: array, saldo_awal: float, keterangan: ?string}|null null kalau tidak ada baris valid sama sekali
     */
    private function buildDetailsForGroup(array $groupRows, array &$rawItems, $barangByKode, $barangByNama, array &$errors): ?array
    {
        if (count($groupRows) === 1 && empty($groupRows[0]['no_invoice_asal'])) {
            $row = $groupRows[0];

            return ['details' => [], 'saldo_awal' => $row['sisa_tagihan_asal'], 'keterangan' => $row['keterangan']];
        }

        $details = [];
        $saldoAwal = 0.0;

        foreach ($groupRows as $row) {
            if (empty($row['no_invoice_asal']) || ! $row['tanggal_invoice_asal']) {
                $errors[] = [
                    'sheet' => $row['sheet'],
                    'row' => $row['source_row'],
                    'message' => 'no_invoice_asal dan tanggal_invoice_asal wajib diisi untuk baris ini (no_urut ini punya lebih dari 1 baris, atau baris tunggal dengan no_invoice_asal terisi).',
                ];

                continue;
            }

            $itemKey = $row['no_urut'].'|'.strtolower(trim($row['no_invoice_asal']));
            $items = [];
            $jumlahTagihanAsal = 0.0;
            foreach ($rawItems[$itemKey] ?? [] as $it) {
                $barangId = $this->resolveBarangId($it['kode_barang'], $it['nama_barang'], $barangByKode, $barangByNama);
                $subtotal = $it['subtotal'] > 0 ? $it['subtotal'] : round($it['qty'] * $it['harga_satuan'], 2);
                $items[] = [
                    'barang_id' => $barangId,
                    'kode_barang' => $it['kode_barang'],
                    'nama_barang' => $it['nama_barang'],
                    'qty' => $it['qty'],
                    'satuan' => $it['satuan'],
                    'harga_satuan' => $it['harga_satuan'],
                    'subtotal' => $subtotal,
                    'keterangan' => $it['keterangan'],
                ];
                $jumlahTagihanAsal += $subtotal;
            }
            unset($rawItems[$itemKey]);

            $details[] = [
                'no_invoice_asal' => $row['no_invoice_asal'],
                'tanggal_invoice_asal' => $row['tanggal_invoice_asal'],
                'deskripsi' => $row['deskripsi'] ?? '',
                'jumlah_tagihan_asal' => $jumlahTagihanAsal > 0 ? $jumlahTagihanAsal : $row['sisa_tagihan_asal'],
                'sisa_tagihan_asal' => $row['sisa_tagihan_asal'],
                'keterangan' => $row['keterangan'],
                'kode_resto' => $row['kode_resto'] ?? null,
                'nama_resto' => $row['nama_resto'] ?? null,
                'items' => $items,
            ];
            $saldoAwal += $row['sisa_tagihan_asal'];
        }

        if (empty($details)) {
            return null;
        }

        return ['details' => $details, 'saldo_awal' => $saldoAwal, 'keterangan' => null];
    }

    // ──────────────────────────────────────────────────────────────
    //  Resolusi Client AR & Barang
    // ──────────────────────────────────────────────────────────────

    /**
     * Validasi 1 grup OB terhadap MASTER DATA — dipanggil SEBELUM resolveKlienForOb().
     * PT: kode_resto BOLEH diisi (opsional, freeform) — dipakai murni sebagai info asal
     * resto per rincian invoice (lihat buildDetailsForGroup()), TIDAK divalidasi ke
     * MASTER DATA dan TIDAK memengaruhi resolusi klien (tetap resolve via nama_klien saja).
     * Ini konsisten dengan pola tb_invoice_item, di mana kode_resto/nama_resto per item
     * B2B juga bebas/tidak divalidasi (lihat memory project_ob_ar_import_flat_autogroup_redesign).
     * RESTO: kode_resto wajib diisi & harus konsisten dengan MASTER DATA (tb_resto +
     * Client AR aktif tipe RESTO untuk outlet tsb) — persis pola
     * InvoiceImportService::validateRowAgainstMasterData().
     */
    public function validateRowAgainstMasterData(string $tipeKlien, ?string $kodeResto, array $restoMasterMap): ?string
    {
        if ($tipeKlien === 'PT') {
            return null;
        }

        if (! $kodeResto) {
            return 'kode_resto wajib diisi untuk baris tipe_klien=RESTO.';
        }

        $entry = $restoMasterMap[strtoupper($kodeResto)] ?? null;
        if (! $entry) {
            return "kode_resto '{$kodeResto}' tidak ditemukan di MASTER DATA (tb_resto) atau belum memiliki Client AR aktif.";
        }

        if ($entry['tipe_klien'] !== 'RESTO') {
            return "kode_resto '{$kodeResto}' sudah terkonsolidasi ke Client AR PT '{$entry['nama_klien']}' di MASTER DATA — kirim baris ini dengan tipe_klien=PT tanpa kode_resto.";
        }

        return null;
    }

    /**
     * PT: resolve via nama_klien unik (TANPA kode_klien sama sekali — dihapus dari
     * template, mirip pola B2B di Import Invoice). RESTO: resolve STRICT via
     * kode_resto — tanpa fallback ke nama, supaya salah ketik tidak nyasar ke outlet
     * lain (persis komentar resolveKlienForRow() di Import Invoice).
     *
     * Mengembalikan hasil lewat return (bukan by-ref $errors) supaya method ini tetap
     * pure/mudah diuji.
     *
     * @return array{klien: ?KlienAr, error: ?string}
     */
    private function resolveKlienForOb(string $tipeKlien, string $namaKlien, ?string $kodeResto, $ptNamaGroups, $restoMap): array
    {
        if ($tipeKlien === 'PT') {
            $matches = $ptNamaGroups->get(strtolower(trim($namaKlien))) ?? collect();

            if ($matches->isEmpty()) {
                return ['klien' => null, 'error' => "Client PT '{$namaKlien}' tidak ditemukan di sistem."];
            }

            if ($matches->count() > 1) {
                return [
                    'klien' => null,
                    'error' => "Nama klien PT '{$namaKlien}' cocok dengan {$matches->count()} klien berbeda — pastikan nama klien di sistem unik.",
                ];
            }

            return ['klien' => $matches->first(), 'error' => null];
        }

        $klien = $restoMap->get(strtoupper($kodeResto ?? ''));

        return $klien
            ? ['klien' => $klien, 'error' => null]
            : ['klien' => null, 'error' => "kode_resto '{$kodeResto}' tidak ditemukan atau belum terhubung ke Client AR aktif tipe RESTO."];
    }

    /**
     * Terima PT/RESTO (istilah tb_klien_ar.tipe_klien) maupun B2B/B2C (istilah familiar
     * dari Import Invoice) sebagai sinonim — sama-sama valid, diterjemahkan ke PT/RESTO
     * segera saat parsing supaya logic downstream (validateRowAgainstMasterData,
     * resolveKlienForOb) tetap konsisten pakai istilah tb_klien_ar.
     */
    private function normalizeTipeKlien(string $raw): ?string
    {
        return match (strtoupper(trim($raw))) {
            'PT', 'B2B' => 'PT',
            'RESTO', 'B2C' => 'RESTO',
            default => null,
        };
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     *   [ptNamaGroups keyed by lower(nama_klien), restoMap keyed by upper(kode_resto)]
     *   — hanya Client AR aktif.
     */
    private function buildKlienMapsForOb(): array
    {
        $ptNamaGroups = KlienAr::where('tipe_klien', 'PT')->where('status', true)->whereNotNull('nama_klien')
            ->get(['id', 'nama_klien', 'perusahaan_id'])
            ->groupBy(fn (KlienAr $k) => strtolower(trim($k->nama_klien)));

        $restoMap = KlienAr::with('resto:id,kode_resto')->where('tipe_klien', 'RESTO')->where('status', true)
            ->get(['id', 'nama_klien', 'resto_id', 'perusahaan_id'])
            ->filter(fn (KlienAr $k) => filled($k->resto?->kode_resto))
            ->keyBy(fn (KlienAr $k) => strtoupper($k->resto->kode_resto));

        return [$ptNamaGroups, $restoMap];
    }

    /**
     * Peta acuan MASTER DATA per kode_resto: segmen yang SEDANG AKTIF untuk tiap outlet.
     * Duplikasi 1:1 dari InvoiceImportService::buildRestoMasterMap() — dipakai murni
     * untuk validasi/pesan error (validateRowAgainstMasterData()), BUKAN untuk
     * auto-attach klien PT ke baris RESTO.
     *
     * @return array<string, array{tipe_klien: string, nama_klien: string, klien_id: int}>
     */
    private function buildRestoMasterMap(): array
    {
        $map = [];
        $restos = Resto::whereNotNull('kode_resto')
            ->where('kode_resto', '!=', '')
            ->get(['id', 'kode_resto', 'perusahaan_id']);

        if ($restos->isEmpty()) {
            return $map;
        }

        $restoKlienByRestoId = [];
        foreach (
            KlienAr::where('tipe_klien', 'RESTO')->where('status', true)
                ->whereIn('resto_id', $restos->pluck('id'))
                ->get(['id', 'resto_id', 'nama_klien']) as $klien
        ) {
            $restoKlienByRestoId[$klien->resto_id] ??= $klien;
        }

        $ptKlienByPerusahaanId = [];
        foreach (
            KlienAr::where('tipe_klien', 'PT')->where('status', true)
                ->whereIn('perusahaan_id', $restos->pluck('perusahaan_id')->filter()->unique())
                ->get(['id', 'perusahaan_id', 'nama_klien']) as $klien
        ) {
            $ptKlienByPerusahaanId[$klien->perusahaan_id] ??= $klien;
        }

        foreach ($restos as $resto) {
            $kodeKey = strtoupper($resto->kode_resto);

            if (isset($restoKlienByRestoId[$resto->id])) {
                $klien = $restoKlienByRestoId[$resto->id];
                $map[$kodeKey] = ['tipe_klien' => 'RESTO', 'nama_klien' => $klien->nama_klien, 'klien_id' => $klien->id];

                continue;
            }

            if ($resto->perusahaan_id && isset($ptKlienByPerusahaanId[$resto->perusahaan_id])) {
                $klien = $ptKlienByPerusahaanId[$resto->perusahaan_id];
                $map[$kodeKey] = ['tipe_klien' => 'PT', 'nama_klien' => $klien->nama_klien, 'klien_id' => $klien->id];
            }
        }

        return $map;
    }

    private function resolveBarangId(?string $kodeBarang, string $namaBarang, $barangByKode, $barangByNama): ?int
    {
        if ($kodeBarang) {
            $barang = $barangByKode->get(strtolower($kodeBarang));
            if ($barang) {
                return $barang->id;
            }
        }

        return $barangByNama->get(strtolower(trim($namaBarang)))?->id;
    }

    // ──────────────────────────────────────────────────────────────
    //  CSV — 1 tabel flat, dibedakan kolom `tipe_baris` (OB/ITEM)
    // ──────────────────────────────────────────────────────────────

    /**
     * Urutan kolom HARUS identik dengan header gabungan di
     * OpeningBalanceImportTemplateService::buildRows().
     */
    private const CSV_COL_TIPE_BARIS = 0;

    private const CSV_COL_NO_URUT = 1;

    private const CSV_COL_NAMA_KLIEN = 2;

    private const CSV_COL_KODE_RESTO = 3;

    private const CSV_COL_NAMA_RESTO = 4;

    private const CSV_COL_TIPE_KLIEN = 5;

    private const CSV_COL_NO_INVOICE_ASAL = 6;

    private const CSV_COL_TANGGAL_INVOICE_ASAL = 7;

    private const CSV_COL_SISA_TAGIHAN_ASAL = 8;

    private const CSV_COL_DESKRIPSI = 9;

    private const CSV_COL_KETERANGAN = 10;

    private const CSV_COL_KODE_BARANG = 11;

    private const CSV_COL_NAMA_BARANG = 12;

    private const CSV_COL_QTY = 13;

    private const CSV_COL_SATUAN = 14;

    private const CSV_COL_HARGA_SATUAN = 15;

    private const CSV_COL_SUBTOTAL = 16;

    /** @return array{0: array<int|string, array>, 1: array<string, array>, 2: array} [obRows keyed by no_urut -> list baris, rawItems keyed by "no_urut|lower(no_invoice_asal)", errors] */
    private function parseCsv(string $filePath): array
    {
        $errors = [];
        $obRows = [];
        $rawItems = [];

        $delimiter = $this->detectCsvDelimiter($filePath);
        $handle = $this->openCsvHandle($filePath);

        $headerFound = false;
        $lineNumber = 0;

        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $lineNumber++;

            if (! $headerFound) {
                if ($this->normalizeHeaderName((string) ($row[self::CSV_COL_TIPE_BARIS] ?? '')) === 'tipe_baris') {
                    if ($this->normalizeHeaderName((string) ($row[self::CSV_COL_TIPE_KLIEN] ?? '')) !== 'tipe_klien') {
                        fclose($handle);

                        throw new \RuntimeException('Format CSV tidak sesuai template terbaru (kolom ke-6 harus "tipe_klien"). Download ulang Template CSV.');
                    }

                    $headerFound = true;
                }

                continue;
            }

            $tipeRaw = trim((string) ($row[self::CSV_COL_TIPE_BARIS] ?? ''));
            if ($tipeRaw === '' || str_starts_with($tipeRaw, '#')) {
                continue; // baris kosong atau baris komentar penuh
            }

            $tipe = strtoupper($tipeRaw);
            $noUrut = trim((string) ($row[self::CSV_COL_NO_URUT] ?? ''));
            $keterangan = $this->importValue($row[self::CSV_COL_KETERANGAN] ?? null);

            if ($tipe === self::CSV_TIPE_OB) {
                $namaKlien = trim((string) ($row[self::CSV_COL_NAMA_KLIEN] ?? ''));
                $deskripsiRaw = trim((string) ($row[self::CSV_COL_DESKRIPSI] ?? ''));
                // Baris kelanjutan contoh (nama_klien kosong, penanda "[CONTOH]" cuma di deskripsi).
                if (str_starts_with($namaKlien, '[CONTOH]') || str_starts_with($deskripsiRaw, '[CONTOH]')) {
                    continue;
                }

                if ($noUrut === '') {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi untuk baris tipe_baris=OB.'];

                    continue;
                }

                $sisaTagihanAsal = $this->importNum($row[self::CSV_COL_SISA_TAGIHAN_ASAL] ?? null);
                if ($sisaTagihanAsal <= 0) {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'sisa_tagihan_asal harus lebih dari 0.'];

                    continue;
                }

                $isFirstInGroup = ! isset($obRows[$noUrut]);
                $tipeKlienRaw = trim((string) ($row[self::CSV_COL_TIPE_KLIEN] ?? ''));
                $tipeKlien = $this->normalizeTipeKlien($tipeKlienRaw);

                if ($isFirstInGroup) {
                    if ($tipeKlien === null) {
                        $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => "tipe_klien '{$tipeKlienRaw}' tidak valid. Harus PT/B2B atau RESTO/B2C."];

                        continue;
                    }
                    if ($namaKlien === '') {
                        $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'nama_klien wajib diisi (baris pertama untuk no_urut ini).'];

                        continue;
                    }
                }

                $obRows[$noUrut][] = [
                    'sheet' => 'Data Opening Balance',
                    'source_row' => $lineNumber,
                    'no_urut' => $noUrut,
                    'tipe_klien' => $isFirstInGroup ? $tipeKlien : null,
                    'nama_klien' => $isFirstInGroup ? $namaKlien : null,
                    'kode_resto' => $this->importValue($row[self::CSV_COL_KODE_RESTO] ?? null),
                    'nama_resto' => $this->importValue($row[self::CSV_COL_NAMA_RESTO] ?? null),
                    'no_invoice_asal' => $this->importValue($row[self::CSV_COL_NO_INVOICE_ASAL] ?? null),
                    'tanggal_invoice_asal' => $this->importDate($row[self::CSV_COL_TANGGAL_INVOICE_ASAL] ?? null),
                    'sisa_tagihan_asal' => $sisaTagihanAsal,
                    'deskripsi' => $this->importValue($row[self::CSV_COL_DESKRIPSI] ?? null) ?? '',
                    'keterangan' => $keterangan,
                ];

                continue;
            }

            if ($tipe === self::CSV_TIPE_ITEM) {
                $namaBarang = trim((string) ($row[self::CSV_COL_NAMA_BARANG] ?? ''));
                if (str_starts_with($namaBarang, '[CONTOH]')) {
                    continue;
                }

                $noInvoiceAsal = trim((string) ($row[self::CSV_COL_NO_INVOICE_ASAL] ?? ''));
                $qty = $this->importNum($row[self::CSV_COL_QTY] ?? null);
                $hargaSatuan = $this->importNum($row[self::CSV_COL_HARGA_SATUAN] ?? null);

                if ($noUrut === '' || $noInvoiceAsal === '') {
                    $errors[] = ['sheet' => 'Item Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut dan no_invoice_asal wajib diisi untuk baris tipe_baris=ITEM.'];

                    continue;
                }

                $key = $noUrut.'|'.strtolower(trim($noInvoiceAsal));
                $rawItems[$key][] = [
                    'source_row' => $lineNumber,
                    'kode_barang' => $this->importValue($row[self::CSV_COL_KODE_BARANG] ?? null),
                    'nama_barang' => $namaBarang,
                    'qty' => $qty,
                    'satuan' => $this->importValue($row[self::CSV_COL_SATUAN] ?? null),
                    'harga_satuan' => $hargaSatuan,
                    'subtotal' => $this->importNum($row[self::CSV_COL_SUBTOTAL] ?? null),
                    'keterangan' => $keterangan,
                ];

                continue;
            }

            $errors[] = ['sheet' => 'CSV', 'row' => $lineNumber, 'message' => "tipe_baris '{$tipeRaw}' tidak dikenali — harus OB atau ITEM."];
        }
        fclose($handle);

        return [$obRows, $rawItems, $errors];
    }

    private function openCsvHandle(string $filePath)
    {
        $handle = fopen($filePath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        return $handle;
    }

    /** Sampling beberapa baris pertama — file template pakai ';' (locale Excel Indonesia), file lain mungkin pakai ','. */
    private function detectCsvDelimiter(string $filePath, int $sampleLines = 20): string
    {
        $handle = $this->openCsvHandle($filePath);
        $sample = '';
        for ($i = 0; $i < $sampleLines; $i++) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $sample .= $line;
        }
        fclose($handle);

        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    // ──────────────────────────────────────────────────────────────
    //  XLSX — 2 sheet data (Data Opening Balance + Item Invoice Asal)
    // ──────────────────────────────────────────────────────────────

    /** @return array{0: array<int|string, array>, 1: array<string, array>, 2: array} [obRows keyed by no_urut -> list baris, rawItems keyed by "no_urut|lower(no_invoice_asal)", errors] */
    private function parseXlsx(string $filePath): array
    {
        $errors = [];

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            $obRows = $this->parseObSheet($spreadsheet, $errors);
            $rawItems = $this->parseItemSheet($spreadsheet, $errors);

            return [$obRows, $rawItems, $errors];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /** @return array<int|string, array> keyed by no_urut -> list baris (grup) */
    private function parseObSheet(Spreadsheet $spreadsheet, array &$errors): array
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'Data Opening Balance');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'Data Opening Balance', 'row' => 0, 'message' => 'Sheet "Data Opening Balance" tidak ditemukan dalam file.'];

            return [];
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectHeaderStart($sheet, 'nama_klien', 10);
        if (! $detected['found']) {
            $errors[] = ['sheet' => 'Data Opening Balance', 'row' => 0, 'message' => 'Header "nama_klien" tidak ditemukan — pastikan menggunakan template terbaru (download ulang template).'];

            return [];
        }

        // Guard format lama: versi template sebelumnya taruh tipe_klien di kolom D (dan versi
        // sebelum itu lagi taruh "tanggal" di situ). Kolom A tetap "nama_klien" di semua versi,
        // jadi detectHeaderStart() di atas tidak cukup untuk menangkap file stale — tanpa guard
        // ini, kolom I file lama akan diam-diam dibaca sebagai tipe_klien dan gagal validasi
        // untuk SEMUA baris dengan pesan yang membingungkan (bukan mengarah ke akar masalah:
        // file salah versi/urutan kolom).
        $headerRow = $detected['dataStart'] - 1;
        $tipeKlienHeader = $this->normalizeHeaderName($this->cellToString($sheet->getCell("I{$headerRow}")->getValue()));
        if ($tipeKlienHeader !== 'tipe_klien') {
            $errors[] = [
                'sheet' => 'Data Opening Balance',
                'row' => 0,
                'message' => 'Format file tidak sesuai template terbaru (kolom I harus "tipe_klien") — urutan kolom template sudah berubah (tipe_klien & no_urut sekarang di paling kanan). Download ulang Template XLSX.',
            ];

            return [];
        }

        $obRows = [];
        $this->eachDataRow($sheet, $detected, function (array $row, int $lineNumber) use (&$obRows, &$errors) {
            $namaKlien = trim($row[0] ?? '');
            $tipeKlienRaw = trim($row[8] ?? '');
            $noUrutRaw = trim($row[9] ?? '');
            $deskripsiRaw = trim($row[6] ?? '');

            // Baris kelanjutan contoh (baris ke-2 dst dari 1 grup no_urut contoh) sengaja
            // mengosongkan nama_klien — penanda "[CONTOH]"-nya cuma ada di kolom deskripsi.
            // Cek deskripsi juga supaya baris ini tetap diabaikan (bukan diproses sebagai
            // data sungguhan dengan tipe_klien kosong).
            if (str_starts_with($namaKlien, '[CONTOH]') || str_starts_with($namaKlien, '#')
                || str_starts_with($deskripsiRaw, '[CONTOH]')) {
                return;
            }
            if ($namaKlien === '' && $tipeKlienRaw === '' && $noUrutRaw === '') {
                return;
            } // baris kosong

            if ($formulaError = $this->detectFormulaError($row, 'Data Opening Balance', $lineNumber)) {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => $formulaError];

                return;
            }

            if ($noUrutRaw === '') {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'no_urut wajib diisi.'];

                return;
            }

            $sisaTagihanAsal = $this->importNum($row[5] ?? null);
            if ($sisaTagihanAsal <= 0) {
                $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'sisa_tagihan_asal harus lebih dari 0.'];

                return;
            }

            $isFirstInGroup = ! isset($obRows[$noUrutRaw]);
            $tipeKlien = $this->normalizeTipeKlien($tipeKlienRaw);

            if ($isFirstInGroup) {
                if ($tipeKlien === null) {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => "tipe_klien '{$tipeKlienRaw}' tidak valid. Harus PT/B2B atau RESTO/B2C."];

                    return;
                }
                if ($namaKlien === '') {
                    $errors[] = ['sheet' => 'Data Opening Balance', 'row' => $lineNumber, 'message' => 'nama_klien wajib diisi (baris pertama untuk no_urut ini).'];

                    return;
                }
            }

            $obRows[$noUrutRaw][] = [
                'sheet' => 'Data Opening Balance',
                'source_row' => $lineNumber,
                'no_urut' => $noUrutRaw,
                'tipe_klien' => $isFirstInGroup ? $tipeKlien : null,
                'nama_klien' => $isFirstInGroup ? $namaKlien : null,
                'kode_resto' => $this->importValue($row[1] ?? null),
                'nama_resto' => $this->importValue($row[2] ?? null),
                'no_invoice_asal' => $this->importValue($row[3] ?? null),
                'tanggal_invoice_asal' => $this->importDate($row[4] ?? null),
                'sisa_tagihan_asal' => $sisaTagihanAsal,
                'deskripsi' => $this->importValue($row[6] ?? null) ?? '',
                'keterangan' => $this->importValue($row[7] ?? null),
            ];
        });

        return $obRows;
    }

    /** @return array<string, array> keyed by "no_urut|lower(no_invoice_asal)", list of item rows */
    private function parseItemSheet(Spreadsheet $spreadsheet, array &$errors): array
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'Item Invoice Asal');
        if ($sheetIndex === null) {
            return [];
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectHeaderStart($sheet, 'no_urut_ob', 9);
        if (! $detected['found']) {
            return [];
        }

        $items = [];
        $this->eachDataRow($sheet, $detected, function (array $row, int $lineNumber) use (&$items, &$errors) {
            $noUrutOb = trim($row[0] ?? '');
            $namaBarang = trim($row[3] ?? '');
            if (str_starts_with($namaBarang, '[CONTOH]')) {
                return;
            }
            if ($noUrutOb === '' && trim($row[1] ?? '') === '') {
                return;
            } // baris kosong

            if ($formulaError = $this->detectFormulaError($row, 'Item Invoice Asal', $lineNumber)) {
                $errors[] = ['sheet' => 'Item Invoice Asal', 'row' => $lineNumber, 'message' => $formulaError];

                return;
            }

            $noInvoiceAsal = trim($row[1] ?? '');
            $qty = $this->importNum($row[4] ?? null);
            $hargaSatuan = $this->importNum($row[6] ?? null);

            if ($noUrutOb === '' || $noInvoiceAsal === '') {
                $errors[] = ['sheet' => 'Item Invoice Asal', 'row' => $lineNumber, 'message' => 'no_urut_ob dan no_invoice_asal wajib diisi.'];

                return;
            }

            $key = $noUrutOb.'|'.strtolower(trim($noInvoiceAsal));
            $items[$key][] = [
                'source_row' => $lineNumber,
                'kode_barang' => $this->importValue($row[2] ?? null),
                'nama_barang' => $namaBarang,
                'qty' => $qty,
                'satuan' => $this->importValue($row[5] ?? null),
                'harga_satuan' => $hargaSatuan,
                'subtotal' => $this->importNum($row[7] ?? null),
                'keterangan' => $this->importValue($row[8] ?? null),
            ];
        });

        return $items;
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers XLSX
    // ──────────────────────────────────────────────────────────────

    private function findSheetIndex(Spreadsheet $spreadsheet, string $name): ?int
    {
        foreach ($spreadsheet->getSheetNames() as $i => $sheetName) {
            if (strtolower(trim($sheetName)) === strtolower($name)) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{found: bool, dataStart: int, highestColumn: string, highestRow: int} */
    private function detectHeaderStart(Worksheet $sheet, string $headerFirstCol, int $maxCols, int $previewRows = 30): array
    {
        $highestRow = $sheet->getHighestRow();
        $maxColLetter = Coordinate::stringFromColumnIndex($maxCols);
        $previewEnd = min($previewRows, $highestRow);

        $preview = $sheet->rangeToArray("A1:{$maxColLetter}{$previewEnd}", null, true, false, false);

        foreach ($preview as $idx => $cells) {
            $firstCell = trim($this->cellToString($cells[0] ?? ''));
            if ($this->normalizeHeaderName($firstCell) === strtolower($headerFirstCol)) {
                return [
                    'found' => true,
                    'dataStart' => $idx + 2,
                    'highestColumn' => $maxColLetter,
                    'highestRow' => $highestRow,
                ];
            }
        }

        return ['found' => false, 'dataStart' => 0, 'highestColumn' => $maxColLetter, 'highestRow' => $highestRow];
    }

    /** Baca seluruh baris data (dataStart..highestRow) sekaligus — volume XLSX yang didukung fitur ini realistis kecil-menengah. */
    private function eachDataRow(Worksheet $sheet, array $detected, callable $onRow): void
    {
        if ($detected['highestRow'] < $detected['dataStart']) {
            return;
        }

        $rows = $sheet->rangeToArray(
            "A{$detected['dataStart']}:{$detected['highestColumn']}{$detected['highestRow']}",
            null, true, false, false,
        );

        foreach ($rows as $idx => $rawRow) {
            $lineNumber = $detected['dataStart'] + $idx;
            $onRow(array_map(fn ($c) => $this->cellToString($c), $rawRow), $lineNumber);
        }
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }

        return trim((string) $value);
    }

    private function normalizeHeaderName(string $raw): string
    {
        return trim(preg_replace('/\(\s*\*\s*\)\s*$/', '', strtolower(trim($raw))));
    }

    /**
     * Deteksi sel yang berisi kode error kalkulasi Excel (rumus gagal, mis. =A1/0 → "#DIV/0!")
     * di mana pun dalam baris, dan kembalikan pesan siap-tampil (bahasa awam, sebut sheet, baris,
     * & kolom persis sesuai file fisik) atau null kalau baris bersih. $row harus 0-based dari
     * kolom A (hasil rangeToArray("A...")) supaya nomor kolom yang dilaporkan akurat.
     */
    private function detectFormulaError(array $row, string $sheetName, int $lineNumber): ?string
    {
        foreach ($row as $idx => $val) {
            $s = trim((string) $val);
            if (isset(self::EXCEL_FORMULA_ERRORS[$s])) {
                $col         = Coordinate::stringFromColumnIndex($idx + 1);
                $explanation = self::EXCEL_FORMULA_ERRORS[$s];

                return "Baris {$lineNumber}: sheet '{$sheetName}' kolom {$col} berisi rumus Excel yang error "
                    . "(kode {$s}: {$explanation}). Buka file Excel Anda, ganti sel tersebut dengan angka/teks "
                    . "biasa (bukan rumus), lalu upload ulang.";
            }
        }

        return null;
    }

    private function importValue(mixed $val): ?string
    {
        $s = trim((string) $val);

        return ($s === '' || $s === '-') ? null : $s;
    }

    private function importDate(mixed $val): ?string
    {
        return ImportDateParser::parse($val);
    }

    private function importNum(mixed $val): float
    {
        $s = str_replace(['.', ','], ['', '.'], trim((string) $val));

        return is_numeric($s) ? (float) $s : 0.0;
    }
}
