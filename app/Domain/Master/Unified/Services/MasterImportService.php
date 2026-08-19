<?php

namespace App\Domain\Master\Unified\Services;

use App\Domain\Finance\KlienAr\DTO\KlienArDTO;
use App\Domain\Finance\KlienAr\Services\KlienArService;
use App\Domain\Master\Investor\DTO\InvestorDTO;
use App\Domain\Master\Investor\Services\InvestorService;
use App\Domain\Master\Resto\DTO\RestoDTO;
use App\Domain\Master\Resto\Services\RestoService;
use App\Models\Barang;
use App\Models\Brand;
use App\Models\ImportMasterBatch;
use App\Models\Investor;
use App\Models\Karyawan;
use App\Models\KlienAr;
use App\Models\Perusahaan;
use App\Models\Resto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

/**
 * Memproses import master data terpadu dari satu file XLSX dengan 2 sheet:
 *   - "MASTER DATA"  : Investor + Resto + KlienAr (flat per outlet, urutan dependency terjaga)
 *   - "MASTER BARANG": Barang
 *
 * Pola upsert, chunked commit, dan progress polling sama dengan service individual.
 *
 * Sheet "MASTER INVOICE" SENGAJA tidak lagi diproses di sini. Import invoice pindah
 * ke tab "Import Master Invoice" (InvoiceImportService) yang punya alur aman
 * preview → klasifikasi → proses aman → review penyesuaian, supaya reupload data
 * lapangan tidak menimpa invoice yang sudah ditagih/dibayar.
 */
class MasterImportService
{
    private const CHUNK = 100;

    /**
     * Kode error kalkulasi Excel standar → penjelasan bahasa awam. Rumus yang gagal (mis. =A1/0)
     * dikembalikan PhpSpreadsheet sebagai string kode ini (bukan exception), jadi kalau tidak
     * dideteksi khusus akan lolos ke importValue()/importDate() begitu saja.
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

    /**
     * Jumlah baris yang dibaca per rangeToArray()/removeRow() saat parsing sheet.
     * Terpisah dari CHUNK (interval commit DB) karena removeRow() punya overhead
     * per panggilan (menggeser row dimension/merge/cell collection di bawahnya),
     * jadi sengaja dibuat lebih jarang. Lihat komentar sama di InvoiceImportService.
     */
    private const PARSE_CHUNK = 1000;

    /**
     * Batas jumlah entri "diperbarui/dilewati" yang disimpan per baris (bukan agregat —
     * counter tetap akurat & tidak dibatasi). Mencegah kolom errors (json) & payload polling
     * membengkak saat re-import file besar (±13.000 baris) yang sebagian besar tidak berubah.
     */
    private const MAX_DETAILS = 500;

    /** Label field Indonesia untuk pesan "apa yang berubah" — dipakai formatDiffMessage(). */
    private const INVESTOR_FIELD_LABELS = [
        'nama_investor'   => 'Nama Investor',
        'ktp'             => 'KTP',
        'npwp'            => 'NPWP',
        'no_hp'           => 'No. HP',
        'pengelola'       => 'Pengelola',
        'no_hp_pengelola' => 'No. HP Pengelola',
        'kode_cabang'     => 'Kode Cabang',
        'id_cabang'       => 'ID Cabang',
        'status'          => 'Status',
    ];

    private const RESTO_FIELD_LABELS = [
        'nama_resto'       => 'Nama Resto',
        'supervisor'       => 'Supervisor',
        'no_hp_supervisor' => 'No. HP Supervisor',
        'stokis'           => 'Stokis',
        'area'             => 'Area',
        'kota'             => 'Kota',
        'alamat'           => 'Alamat',
        'no_telp'          => 'No. Telp',
        'keterangan'       => 'Keterangan',
        'perusahaan_id'    => 'Entitas (ID)',
        'brand_id'         => 'Brand (ID)',
        'investor_id'      => 'Investor (ID)',
        'karyawan_id'      => 'PIC Resto (ID)',
        'tgl_aktif'        => 'Tanggal Aktif',
        'status'           => 'Status',
    ];

    private const KLIEN_AR_FIELD_LABELS = [
        'nama_klien'     => 'Nama Klien',
        'tipe_klien'     => 'Tipe Klien',
        'no_npwp'        => 'NPWP',
        'no_wa'          => 'No. WA',
        'perusahaan_id'  => 'Entitas (ID)',
        'karyawan_ar_id' => 'PIC AR (ID)',
        'resto_id'       => 'Resto (ID)',
        'status'         => 'Status',
    ];

    private const BARANG_FIELD_LABELS = [
        'nama_barang' => 'Nama Barang',
        'spesifikasi' => 'Spesifikasi',
        'keterangan'  => 'Keterangan',
        'status'      => 'Status',
    ];

    public function __construct(
        private readonly InvestorService $investorService,
        private readonly RestoService    $restoService,
        private readonly KlienArService  $klienArService,
    ) {}

    public function process(ImportMasterBatch $batch): void
    {
        $disk = Storage::disk('local');
        if (!$batch->file_path || !$disk->exists($batch->file_path)) {
            throw new \RuntimeException("File import tidak ditemukan: {$batch->file_path}");
        }

        // Hindari COUNT(*) penuh per baris KlienAr baru — lihat KlienArService::primeKodeKlienCounter().
        $this->klienArService->primeKodeKlienCounter();

        $fullPath = $disk->path($batch->file_path);
        $reader   = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($fullPath);

        $batch->update(['status' => 'processing']);

        $errors  = [];
        $details = [];
        $counts  = ['investor_skipped' => 0, 'resto_skipped' => 0];

        try {
            $counts = $this->processMasterDataSheet($spreadsheet, $batch, $errors, $details);
            $this->processMasterBarangSheet($spreadsheet, $batch, $errors, $details);
            $this->noticeInvoiceSheetIgnored($spreadsheet, $errors);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        $batch     = $batch->fresh();
        $detailTotal = $counts['investor_skipped'] + $counts['resto_skipped']
            + $batch->investor_updated + $batch->resto_updated
            + $batch->klien_updated    + $batch->klien_skipped
            + $batch->barang_updated   + $batch->barang_skipped;

        $batch->update([
            'status'  => 'completed',
            'errors'  => [
                'gagal'            => $errors,
                'detail'           => $details,
                'detail_total'     => $detailTotal,
                'investor_skipped' => $counts['investor_skipped'],
                'resto_skipped'    => $counts['resto_skipped'],
            ],
            'message' => $this->buildSummaryMessage($batch, $counts['investor_skipped'], $counts['resto_skipped']),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Sheet 1: MASTER DATA (Investor + Resto + KlienAr)
    // ──────────────────────────────────────────────────────────────

    /** @return array{investor_skipped: int, resto_skipped: int} */
    private function processMasterDataSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ImportMasterBatch $batch, array &$errors, array &$details): array
    {
        $noSkip = ['investor_skipped' => 0, 'resto_skipped' => 0];

        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER DATA');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Sheet "MASTER DATA" tidak ditemukan dalam file.'];
            return $noSkip;
        }

        $sheet    = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectMasterHeaderStart($sheet, 'nama_investor', 27);

        if (!$detected['found']) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Header "nama_investor" tidak ditemukan di sheet MASTER DATA.'];
            return $noSkip;
        }

        $headerRow = array_map(fn($c) => $this->normalizeHeaderName((string) $c), $detected['headerRow']);
        if (in_array('nama_klien', $headerRow)) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Template lama masih memiliki kolom nama_klien. Download template terbaru.'];
            return $noSkip;
        }

        // Header-based column lookup — toleran terhadap perubahan urutan kolom dan template lama/baru
        $headerIdxMap = array_flip($headerRow);
        $col = static fn(array $row, string $name): mixed =>
            $row[$headerIdxMap[$name] ?? -1] ?? '';

        $batch->update(['master_total' => max(0, $detected['highestRow'] - $detected['dataStart'] + 1)]);

        // Preload referensi
        $actingUserId     = $batch->user_id;
        $brandMap         = $this->buildLowerMap(Brand::all(['id', 'nama_brand']), 'nama_brand');
        $karyawanRecords  = Karyawan::all(['id', 'nama_karyawan', 'nik']);
        $karyawanMap      = $this->buildLowerMap($karyawanRecords, 'nama_karyawan');
        $karyawanNikMap   = $this->buildLowerMap($karyawanRecords, 'nik');
        // id => nama_karyawan tersimpan saat ini — dipakai resolveKaryawanNameSync() untuk
        // mendeteksi perubahan nama via kombinasi Nama+NIK, DIPERBARUI runtime saat sinkron terjadi
        // (lihat applyKaryawanNameSync()) supaya baris berikutnya di import yang sama ikut konsisten.
        $karyawanNameById = $karyawanRecords->pluck('nama_karyawan', 'id')->all();
        $perusahaanMap    = $this->buildPerusahaanMap();

        // Preload dedup map Investor/Resto/KlienAr sekali di awal (pre-scan seluruh sheet,
        // TANPA removeRow() — beda dari chunkMasterRows() di bawah) — supaya loop utama tidak
        // query DB per baris untuk cek "sudah ada atau belum". Baris baru yang dibuat DALAM
        // loop utama tetap ditulis balik ke map yang sama (lihat pemakaian *Map[...] = ... di
        // bawah) supaya baris duplikat berikutnya di file yang sama tetap ter-dedup benar.
        $dedupMaps          = $this->preloadMasterDataDedupMaps($sheet, $detected, $col, $perusahaanMap);
        $investorMap        = $dedupMaps['investor'];
        $restoByKodeMap      = $dedupMaps['resto_by_kode'];
        $restoByNamaMap      = $dedupMaps['resto_by_nama'];
        $klienByPerusahaanMap = $dedupMaps['klien_by_perusahaan'];
        $klienByRestoMap      = $dedupMaps['klien_by_resto'];

        $invIns = $invUpd = $invFail = $invSkip = 0;
        $resIns = $resUpd = $resFail = $resSkip = 0;
        $kliIns = $kliUpd = $kliFail = $kliSkip = 0;
        $processed  = 0;
        // -1 supaya increment pertama ($lineNumber++ di awal closure) pas di $detected['dataStart'] —
        // yaitu nomor baris ASLI di Excel untuk baris data pertama, bukan angka virtual mulai dari 1.
        // Robust terhadap posisi header yang terdeteksi dinamis via detectMasterHeaderStart().
        $lineNumber = $detected['dataStart'] - 1;
        $inChunk    = 0;

        DB::beginTransaction();
        try {
            $this->chunkMasterRows($sheet, $detected['dataStart'], $detected['highestColumn'], self::PARSE_CHUNK,
                function (array $row) use (
                    &$errors, &$details, &$inChunk, &$processed, &$lineNumber,
                    &$invIns, &$invUpd, &$invFail, &$invSkip, &$resIns, &$resUpd, &$resFail, &$resSkip,
                    &$kliIns, &$kliUpd, &$kliFail, &$kliSkip,
                    &$karyawanMap, &$karyawanNameById,
                    &$investorMap, &$restoByKodeMap, &$restoByNamaMap, &$klienByPerusahaanMap, &$klienByRestoMap,
                    $col, $batch, $actingUserId, $brandMap, $karyawanNikMap, $perusahaanMap,
                ) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $batch->update([
                        'master_processed'  => min($processed, $batch->master_total),
                        'investor_inserted' => $invIns,  'investor_updated' => $invUpd,  'investor_failed' => $invFail,
                        'resto_inserted'    => $resIns,  'resto_updated'    => $resUpd,  'resto_failed'    => $resFail,
                        'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,  'klien_skipped' => $kliSkip,
                    ]);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) return;
                if (str_starts_with($firstCell, '[CONTOH]')) return;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) return;

                if ($formulaError = $this->detectFormulaError($row, 'MASTER DATA', $lineNumber)) {
                    $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => $formulaError];

                    return;
                }

                $namaInvestor  = trim($firstCell);
                $namaCabang    = trim((string) $col($row, 'nama_cabang'));
                $tipeKlienRaw  = trim((string) $col($row, 'tipe_klien'));
                $tipeKlienNorm = $this->normalizeTipeKlien($tipeKlienRaw);
                $tipeKlien     = $tipeKlienNorm['value'] ?? '';
                if ($tipeKlienNorm['error']) {
                    $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Client] ' . $tipeKlienNorm['error']];
                    $kliFail++;
                }
                $status        = $this->parseStatus(trim((string) $col($row, 'status')));
                $investorFailed = false;

                // ── 1. Investor ─────────────────────────────────────────────
                $investor = null;
                if ($namaInvestor !== '') {
                    $invData = [
                        'nama_investor'   => $namaInvestor,
                        'ktp'             => $this->importValue($col($row, 'ktp')),
                        'npwp'            => $this->importValue($col($row, 'npwp')),
                        'no_hp'           => $this->importValue($col($row, 'no_hp')),
                        'pengelola'       => $this->importValue($col($row, 'pengelola')),
                        'no_hp_pengelola' => $this->importValue($col($row, 'no_hp_pengelola')),
                        'kode_cabang'     => $this->importValue($col($row, 'kode_cabang')),
                        'id_cabang'       => $this->importValue($col($row, 'id_cabang')),
                        'status'          => $status,
                    ];

                    $validator = Validator::make($invData, [
                        'nama_investor'   => ['required', 'string', 'max:150'],
                        'ktp'             => ['nullable', 'string', 'max:20'],
                        'npwp'            => ['nullable', 'string', 'max:30'],
                        'no_hp'           => ['nullable', 'string', 'max:20'],
                        'pengelola'       => ['nullable', 'string', 'max:150'],
                        'no_hp_pengelola' => ['nullable', 'string', 'max:20'],
                        'kode_cabang'     => ['nullable', 'string', 'max:50'],
                        'id_cabang'       => ['nullable', 'string', 'max:50'],
                        'status'          => ['nullable', 'boolean'],
                    ]);

                    if ($validator->fails()) {
                        $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Investor] ' . implode('; ', $validator->errors()->all())];
                        $invFail++;
                        $investorFailed = true;
                    } else {
                        $investorKey = $this->investorDedupKey($invData['nama_investor'], $invData['kode_cabang'], $invData['id_cabang']);
                        $existing = $investorMap[$investorKey] ?? null;

                        try {
                            if ($existing) {
                                $invDiff = $this->investorDiff($existing, $invData);
                                if (!empty($invDiff)) {
                                    $existing->updated_by = $actingUserId;
                                    $investor = $this->investorService->update($existing, InvestorDTO::fromRequest($invData), eagerLoad: false);
                                    $investorMap[$investorKey] = $investor;
                                    $invUpd++;
                                    $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Investor] ' . $this->formatDiffMessage($invDiff, self::INVESTOR_FIELD_LABELS));
                                } else {
                                    $investor = $existing;
                                    $invSkip++;
                                    $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Investor] Data sudah sama persis dengan data tersimpan — tidak ada perubahan, baris dilewati.');
                                }
                            } else {
                                $investor = $this->investorService->create(InvestorDTO::fromRequest($invData), eagerLoad: false);
                                $investorMap[$investorKey] = $investor;
                                $invIns++;
                            }
                        } catch (\Throwable $e) {
                            $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Investor] Gagal menyimpan: ' . $e->getMessage()];
                            $invFail++;
                            $investorFailed = true;
                        }
                    }
                }

                // ── 2. Resto ────────────────────────────────────────────────
                $resto = null;
                if ($namaCabang !== '') {
                    $namaPerusahaan = $this->importValue($col($row, 'nama_entitas')) ?? '';
                    $namaBrand      = $this->importValue($col($row, 'nama_brand')) ?? '';
                    $kodeResto      = $this->importValue($col($row, 'kode_resto')) ?? '';
                    $namaPicResto   = $this->importValue($col($row, 'nama_pic')) ?? '';
                    $picArRaw       = $this->importValue($col($row, 'pic_ar')) ?? '';

                    $picResult        = $this->resolvePicRestoForRow($namaPicResto, $picArRaw, $tipeKlien, $karyawanMap, $karyawanNikMap, $karyawanNameById, $actingUserId);
                    $karyawanId       = $picResult['karyawan_id'];
                    $picRestoFallback = $picResult['used_fallback'];
                    $picIdentifier    = $picResult['identifier'];
                    $karyawanMap      = $picResult['karyawan_map'];
                    $karyawanNameById = $picResult['karyawan_name_by_id'];

                    $perusahaanId = null;
                    $brandId      = null;
                    $rowErrors    = $picResult['name_sync_errors'];

                    if ($namaPerusahaan) {
                        $perusahaanId = $perusahaanMap[strtolower($namaPerusahaan)] ?? null;
                        if (!$perusahaanId) $rowErrors[] = "Entitas '{$namaPerusahaan}' tidak ditemukan";
                    }
                    if ($namaBrand) {
                        $brandId = $brandMap[strtolower($namaBrand)] ?? null;
                        if (!$brandId) $rowErrors[] = "Brand '{$namaBrand}' tidak ditemukan";
                    }
                    if ($picIdentifier !== '') {
                        if (!$karyawanId) {
                            $picLabel    = $picRestoFallback ? 'PIC Resto/PIC AR' : 'PIC Resto';
                            $rowErrors[] = "{$picLabel} '{$picIdentifier}' tidak ditemukan (nama karyawan atau NIK)";
                        } elseif ($picResult['conflict_error']) {
                            $rowErrors[] = $picResult['conflict_error'];
                        }
                    }

                    // kode_resto adalah identitas unik outlet — jadi kunci lookup utama supaya
                    // dua cabang dengan nama sama (mis. "Veteran" di kota berbeda) tidak tertukar.
                    // Fallback ke nama_resto hanya berlaku untuk baris lama yang kode_resto-nya kosong.
                    if ($kodeResto !== '') {
                        $restoKey = $this->restoDedupKeyByKode($kodeResto);
                        $existingResto = $restoByKodeMap[$restoKey] ?? null;
                    } else {
                        $restoKey = $this->restoDedupKeyByNama($namaCabang);
                        $existingResto = $restoByNamaMap[$restoKey] ?? null;
                    }
                    if (!$existingResto && $kodeResto === '') {
                        $rowErrors[] = "kode_resto wajib diisi untuk data baru '{$namaCabang}'";
                    }

                    if (!empty($rowErrors)) {
                        $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Resto] ' . implode('; ', $rowErrors)];
                        $resFail++;
                    } else {
                        $resData = [
                            'nama_resto'       => $namaCabang,
                            'kode_resto'       => $kodeResto ?: null,
                            'investor_id'      => $investor?->id,
                            'perusahaan_id'    => $perusahaanId,
                            'brand_id'         => $brandId,
                            'karyawan_id'      => $karyawanId,
                            'supervisor'       => $this->importValue($col($row, 'supervisor')),
                            'no_hp_supervisor' => $this->importValue($col($row, 'no_hp_supervisor')),
                            'stokis'           => $this->importValue($col($row, 'stokis')),
                            'area'             => $this->importValue($col($row, 'area')),
                            'kota'             => $this->importValue($col($row, 'kota')),
                            'alamat'           => $this->importValue($col($row, 'alamat')),
                            'no_telp'          => $this->importValue($col($row, 'no_telp')),
                            'tgl_aktif'        => $this->importDate($col($row, 'tgl_aktif')),
                            'keterangan'       => $this->importValue($col($row, 'keterangan')),
                            'status'           => $status,
                        ];

                        $validator = Validator::make($resData, [
                            'nama_resto'       => ['required', 'string', 'max:150'],
                            'kode_resto'       => ['nullable', 'string', 'max:100'],
                            'investor_id'      => ['nullable', 'integer'],
                            'perusahaan_id'    => ['nullable', 'integer'],
                            'brand_id'         => ['nullable', 'integer'],
                            'karyawan_id'      => ['nullable', 'integer'],
                            'supervisor'       => ['nullable', 'string', 'max:150'],
                            'no_hp_supervisor' => ['nullable', 'string', 'max:20'],
                            'stokis'           => ['nullable', 'string', 'max:150'],
                            'area'             => ['nullable', 'string', 'max:100'],
                            'kota'             => ['nullable', 'string', 'max:100'],
                            'alamat'           => ['nullable', 'string'],
                            'no_telp'          => ['nullable', 'string', 'max:20'],
                            'tgl_aktif'        => ['nullable', 'date'],
                            'keterangan'       => ['nullable', 'string'],
                            'status'           => ['nullable', 'boolean'],
                        ]);

                        if ($validator->fails()) {
                            $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Resto] ' . implode('; ', $validator->errors()->all())];
                            $resFail++;
                        } else {
                            try {
                                if ($existingResto) {
                                    $resDiff = $this->restoDiff($existingResto, $resData);
                                    if (!empty($resDiff)) {
                                        $existingResto->updated_by = $actingUserId;
                                        $resto = $this->restoService->update($existingResto, RestoDTO::fromRequest($resData), eagerLoad: false);
                                        $this->writeRestoDedupMaps($resto, $restoByKodeMap, $restoByNamaMap);
                                        $resUpd++;
                                        $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Resto] ' . $this->formatDiffMessage($resDiff, self::RESTO_FIELD_LABELS));
                                    } else {
                                        $resto = $existingResto;
                                        $resSkip++;
                                        $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Resto] Data sudah sama persis dengan data tersimpan — tidak ada perubahan, baris dilewati.');
                                    }
                                } else {
                                    $resto = $this->restoService->create(RestoDTO::fromRequest($resData), eagerLoad: false);
                                    $this->writeRestoDedupMaps($resto, $restoByKodeMap, $restoByNamaMap);
                                    $resIns++;
                                }
                            } catch (\Throwable $e) {
                                $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Resto] Gagal menyimpan: ' . $e->getMessage()];
                                $resFail++;
                            }
                        }
                    }
                }

                // ── Toggle segmen: PT menggantikan RESTO untuk outlet yang sama ────
                // Kalau outlet ini sekarang berstatus PT, matikan Client AR RESTO lama
                // miliknya (kalau masih aktif) supaya invoice berikutnya tidak lagi
                // ter-resolve ke Client AR RESTO yang sudah usang. Arah sebaliknya
                // (RESTO menggantikan PT) tidak butuh aksi serupa: Client AR PT dipakai
                // bersama banyak outlet per perusahaan_id, jadi tidak boleh dimatikan
                // hanya karena satu outlet berubah balik jadi RESTO.
                if ($tipeKlien === 'PT' && $resto) {
                    $kliUpd += KlienAr::where('resto_id', $resto->id)
                        ->where('tipe_klien', 'RESTO')
                        ->where('status', true)
                        ->update(['status' => false, 'updated_by' => $actingUserId]);
                }

                // ── 3. KlienAr ──────────────────────────────────────────────
                if ($namaCabang !== '' && in_array($tipeKlien, ['PT', 'RESTO'])) {
                    $namaEntitasKli = $this->importValue($col($row, 'nama_entitas')) ?? '';
                    // no_npwp & no_wa diturunkan otomatis dari Investor (semua tipe) — diisi di blok penentuan nama klien di bawah
                    $noNpwp         = null;
                    $noWa           = null;
                    $rowErrors      = [];
                    $namaKlien      = $namaCabang;

                    $karyawanArId = null;
                    if ($tipeKlien === 'RESTO') {
                        // PIC AR untuk Client AR tipe RESTO selalu mengikuti PIC Resto (kolom nama_pic,
                        // atau pic_ar sbg fallback jika nama_pic kosong — lihat resolvePicRestoForRow()),
                        // bukan kolom pic_ar terpisah — mencegah kolom nama_pic & pic_ar diisi beda orang
                        // di Excel sehingga PIC AR "nyasar" dari PIC Resto yang sebenarnya (lihat validasi
                        // konflik di blok Resto di atas).
                        $karyawanArId = $karyawanId;
                        if (!$karyawanArId) $rowErrors[] = "PIC Resto (nama_pic atau pic_ar) wajib diisi untuk membuat Client AR tipe RESTO";
                    } elseif ($picArRaw !== '') {
                        $karyawanArId = $this->resolveKaryawanIdByNameOrNik($picArRaw, $karyawanMap, $karyawanNikMap);
                        if (!$karyawanArId) {
                            $rowErrors[] = "Karyawan AR '{$picArRaw}' tidak ditemukan (nama atau NIK)";
                        } else {
                            $nameSync = $this->resolveKaryawanNameSync($picArRaw, $karyawanArId, $karyawanNameById);
                            if ($nameSync['error']) {
                                $rowErrors[] = $nameSync['error'];
                            } elseif ($nameSync['should_update']) {
                                $this->applyKaryawanNameSync($karyawanArId, $nameSync['nama_baru'], $karyawanMap, $karyawanNameById, $actingUserId);
                            }
                        }
                    } else {
                        $rowErrors[] = "pic_ar wajib diisi untuk membuat Client AR";
                    }

                    $perusahaanIdKli = null;
                    if ($tipeKlien === 'PT') {
                        if (!$namaEntitasKli) {
                            $rowErrors[] = "nama_entitas wajib untuk tipe klien PT";
                        } else {
                            $perusahaanIdKli = $perusahaanMap[strtolower($namaEntitasKli)] ?? null;
                            if (!$perusahaanIdKli) $rowErrors[] = "Entitas '{$namaEntitasKli}' tidak ditemukan";
                        }
                    }

                    $restoIdKli = null;
                    if ($tipeKlien === 'RESTO') {
                        if ($resto) {
                            $restoIdKli = $resto->id;
                        } else {
                            $existingResto = Resto::where('nama_resto', $namaCabang)->latest()->first();
                            if ($existingResto) {
                                $restoIdKli = $existingResto->id;
                            } else {
                                $rowErrors[] = "Resto '{$namaCabang}' tidak ditemukan untuk tipe klien RESTO";
                            }
                        }
                    }

                    // Resolve Investor baris ini — sumber kontak Client AR (semua tipe) & nama klien RESTO
                    $klienInvestor = $investor;
                    if (!$klienInvestor) {
                        $restoForInv = $resto;
                        if (!$restoForInv && $restoIdKli) {
                            $restoForInv = Resto::find($restoIdKli);
                        }
                        $klienInvestor = $restoForInv?->loadMissing('investor')->investor;
                    }

                    // Kontak Client AR SELALU dari Investor
                    $noNpwp = $this->importValue($klienInvestor?->npwp);
                    $noWa   = $this->importValue($klienInvestor?->no_hp);

                    // Nama Client AR ditentukan berdasarkan tipe
                    if ($tipeKlien === 'PT') {
                        $namaKlien = $namaEntitasKli ?: $namaCabang;
                    } elseif ($tipeKlien === 'RESTO') {
                        $investorName = $klienInvestor?->nama_investor;
                        if ($investorName) {
                            $namaKlien = $investorName;
                        } elseif ($investorFailed) {
                            $rowErrors[] = "Dilewati: Investor baris ini gagal tersimpan — nama Client AR tidak dapat ditentukan";
                        } else {
                            // nama_investor kosong (bukan gagal simpan) — B2C fallback: pakai
                            // kode_resto (nama_resto) supaya outlet tetap punya Client AR yang
                            // bisa dicetak, tanpa membuat Investor palsu.
                            $fallbackKodeResto = $restoForInv?->kode_resto ?: ($kodeResto ?: null);
                            $fallbackNamaResto = $restoForInv?->nama_resto ?: $namaCabang;
                            $namaKlien = $this->buildFallbackKlienArName($fallbackKodeResto, $fallbackNamaResto);
                        }
                    }

                    if (!empty($rowErrors)) {
                        $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Client] ' . implode('; ', $rowErrors)];
                        $kliFail++;
                    } else {
                        $kliData = [
                            'nama_klien'     => $namaKlien,
                            'tipe_klien'     => $tipeKlien,
                            'karyawan_ar_id' => $karyawanArId,
                            'perusahaan_id'  => $perusahaanIdKli,
                            'resto_id'       => $restoIdKli,
                            'no_npwp'        => $noNpwp,
                            'no_wa'          => $noWa,
                            'status'         => $status,
                        ];

                        $validator = Validator::make($kliData, [
                            'nama_klien'     => ['required', 'string', 'max:150'],
                            'tipe_klien'     => ['required', 'in:PT,RESTO'],
                            'karyawan_ar_id' => ['required', 'integer'],
                            'perusahaan_id'  => ['nullable', 'integer'],
                            'resto_id'       => ['nullable', 'integer'],
                            'no_npwp'        => ['nullable', 'string', 'max:30'],
                            'no_wa'          => ['nullable', 'string', 'max:20'],
                            'status'         => ['nullable', 'boolean'],
                        ]);

                        if ($validator->fails()) {
                            $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Client] ' . implode('; ', $validator->errors()->all())];
                            $kliFail++;
                        } else {
                            // klienKey null berarti jalur fallback (nama_klien+tipe_klien) — path langka
                            // (hanya saat perusahaan_id/resto_id tidak berhasil diresolusi), sengaja
                            // dibiarkan query live per-baris seperti sebelumnya (tidak di-preload).
                            $klienKey = null;
                            if ($tipeKlien === 'PT' && $perusahaanIdKli) {
                                $klienKey = $this->klienDedupKeyByPerusahaan($perusahaanIdKli);
                                $existingKlien = $klienByPerusahaanMap[$klienKey] ?? null;
                            } elseif ($tipeKlien === 'RESTO' && $restoIdKli) {
                                $klienKey = $this->klienDedupKeyByResto($restoIdKli);
                                $existingKlien = $klienByRestoMap[$klienKey] ?? null;
                            } else {
                                $existingKlien = KlienAr::where('nama_klien', $namaKlien)
                                    ->where('tipe_klien', $tipeKlien)
                                    ->latest()->first();
                            }

                            try {
                                if ($existingKlien) {
                                    $kliDiff = $this->klienArDiff($existingKlien, $kliData);
                                    if (!empty($kliDiff)) {
                                        $existingKlien->updated_by = $actingUserId;
                                        $updatedKlien = $this->klienArService->update($existingKlien, KlienArDTO::fromRequest($kliData), eagerLoad: false);
                                        if ($klienKey !== null) {
                                            $tipeKlien === 'PT' ? $klienByPerusahaanMap[$klienKey] = $updatedKlien : $klienByRestoMap[$klienKey] = $updatedKlien;
                                        }
                                        $kliUpd++;
                                        $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Client] ' . $this->formatDiffMessage($kliDiff, self::KLIEN_AR_FIELD_LABELS));
                                    } else {
                                        $kliSkip++;
                                        $this->pushDetail($details, 'MASTER DATA', $lineNumber, '[Client] Data sudah sama persis dengan data tersimpan — tidak ada perubahan, baris dilewati.');
                                    }
                                } else {
                                    $newKlien = $this->klienArService->create(KlienArDTO::fromRequest($kliData), eagerLoad: false);
                                    if ($klienKey !== null) {
                                        $tipeKlien === 'PT' ? $klienByPerusahaanMap[$klienKey] = $newKlien : $klienByRestoMap[$klienKey] = $newKlien;
                                    }
                                    $kliIns++;
                                }
                            } catch (\Throwable $e) {
                                $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Client] Gagal menyimpan: ' . $e->getMessage()];
                                $kliFail++;
                            }
                        }
                    }
                }
                }
            );
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }

        $batch->update([
            'master_processed'  => $batch->master_total,
            'investor_inserted' => $invIns,  'investor_updated' => $invUpd,  'investor_failed' => $invFail,
            'resto_inserted'    => $resIns,  'resto_updated'    => $resUpd,  'resto_failed'    => $resFail,
            'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,  'klien_skipped' => $kliSkip,
        ]);

        return ['investor_skipped' => $invSkip, 'resto_skipped' => $resSkip];
    }

    // ──────────────────────────────────────────────────────────────
    //  Sheet 2: MASTER BARANG (Barang)
    // ──────────────────────────────────────────────────────────────

    private function processMasterBarangSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ImportMasterBatch $batch, array &$errors, array &$details): void
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER BARANG');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'MASTER BARANG', 'row' => 0, 'message' => 'Sheet "MASTER BARANG" tidak ditemukan dalam file.'];
            return;
        }

        $sheet    = $spreadsheet->getSheet($sheetIndex);
        $detected = $this->detectMasterHeaderStart($sheet, 'kode_barang', 6);

        if (!$detected['found']) {
            $errors[] = ['sheet' => 'MASTER BARANG', 'row' => 0, 'message' => 'Header "kode_barang" tidak ditemukan di sheet MASTER BARANG.'];
            return;
        }

        // Header-based column lookup — toleran terhadap template lama yang masih memiliki kolom nama_brand
        $headerRow    = array_map(fn($c) => $this->normalizeHeaderName((string) $c), $detected['headerRow']);
        $headerIdxMap = array_flip($headerRow);
        $col = static fn(array $row, string $name): mixed =>
            $row[$headerIdxMap[$name] ?? -1] ?? '';

        $batch->update(['barang_total' => max(0, $detected['highestRow'] - $detected['dataStart'] + 1)]);

        $actingUserId = $batch->user_id;
        // Pre-scan sekali di awal — lihat komentar preloadMasterDataDedupMaps() untuk rasional.
        $barangMap = $this->preloadBarangMap($sheet, $detected, $col);

        $brgIns = $brgUpd = $brgSkip = $brgFail = 0;
        $processed  = 0;
        // sama seperti processMasterDataSheet() — lihat komentar di sana
        $lineNumber = $detected['dataStart'] - 1;
        $inChunk    = 0;

        DB::beginTransaction();
        try {
            $this->chunkMasterRows($sheet, $detected['dataStart'], $detected['highestColumn'], self::PARSE_CHUNK,
                function (array $row) use (
                    &$errors, &$details, &$inChunk, &$processed, &$lineNumber,
                    &$brgIns, &$brgUpd, &$brgSkip, &$brgFail, &$barangMap,
                    $col, $batch, $actingUserId,
                ) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $batch->update([
                        'barang_processed' => min($processed, $batch->barang_total),
                        'barang_inserted'  => $brgIns,
                        'barang_updated'   => $brgUpd,
                        'barang_skipped'   => $brgSkip,
                        'barang_failed'    => $brgFail,
                    ]);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) return;
                if (str_starts_with($firstCell, '[CONTOH]')) return;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) return;

                if ($formulaError = $this->detectFormulaError($row, 'MASTER BARANG', $lineNumber)) {
                    $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => $formulaError];

                    return;
                }

                $rawKode   = strtoupper(trim((string) $col($row, 'kode_barang')));
                $rawNama   = trim((string) $col($row, 'nama_barang'));
                $rawStatus = trim((string) $col($row, 'status'));

                $data = [
                    'kode_barang' => $rawKode === '' ? null : $rawKode,
                    'nama_barang' => $rawNama,
                    'spesifikasi' => $this->importValue($col($row, 'spesifikasi')),
                    'keterangan'  => $this->importValue($col($row, 'keterangan')),
                    'status'      => $this->parseStatus($rawStatus),
                ];

                $validator = Validator::make($data, [
                    'kode_barang' => ['required', 'string', 'max:50'],
                    'nama_barang' => ['required', 'string', 'max:150'],
                    'spesifikasi' => ['nullable', 'string'],
                    'keterangan'  => ['nullable', 'string'],
                    'status'      => ['nullable', 'boolean'],
                ]);
                if ($validator->fails()) {
                    $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    $brgFail++;
                    return;
                }

                // kode_barang adalah identitas unik barang — nama_barang bisa sama untuk produk berbeda (varian/kategori berbeda)
                $existing = $barangMap[$rawKode] ?? null;

                try {
                    if ($existing) {
                        $brgDiff = $this->barangDiff($existing, $data);
                        if (!empty($brgDiff)) {
                            // update() mutasi objek $existing di tempat — otomatis konsisten dengan
                            // referensi yang sama di $barangMap, tidak perlu tulis balik eksplisit.
                            $existing->update([
                                'nama_barang' => $data['nama_barang'],
                                'spesifikasi' => $data['spesifikasi'],
                                'keterangan'  => $data['keterangan'],
                                'status'      => $data['status'] ?? true,
                                'updated_by'  => $actingUserId,
                            ]);
                            $brgUpd++;
                            $this->pushDetail($details, 'MASTER BARANG', $lineNumber, $this->formatDiffMessage($brgDiff, self::BARANG_FIELD_LABELS));
                        } else {
                            $brgSkip++;
                            $this->pushDetail($details, 'MASTER BARANG', $lineNumber, 'Data sudah sama persis dengan data tersimpan — tidak ada perubahan, baris dilewati.');
                        }
                    } else {
                        $barangMap[$rawKode] = Barang::create([
                            'kode_barang' => $rawKode,
                            'nama_barang' => $data['nama_barang'],
                            'spesifikasi' => $data['spesifikasi'],
                            'keterangan'  => $data['keterangan'],
                            'status'      => $data['status'] ?? true,
                        ]);
                        $brgIns++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
                    $brgFail++;
                }
                }
            );
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }

        $batch->update([
            'barang_processed' => $batch->barang_total,
            'barang_inserted'  => $brgIns,
            'barang_updated'   => $brgUpd,
            'barang_skipped'   => $brgSkip,
            'barang_failed'    => $brgFail,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Sheet MASTER INVOICE — tidak diproses di sini
    // ──────────────────────────────────────────────────────────────

    /**
     * File template lama masih memuat sheet MASTER INVOICE. Diamkan saja akan
     * membingungkan (user mengira invoice ikut terimport), jadi beri pemberitahuan
     * eksplisit bahwa sheet itu diabaikan dan harus lewat tab Import Master Invoice.
     */
    private function noticeInvoiceSheetIgnored(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, array &$errors): void
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER INVOICE');
        if ($sheetIndex === null) {
            return;
        }

        $errors[] = [
            'sheet'   => 'MASTER INVOICE',
            'row'     => 0,
            'message' => 'Diabaikan: sheet MASTER INVOICE tidak lagi diproses di Import Master Data. Upload invoice lewat tab "Import Master Invoice" agar perubahan pada invoice yang sudah ditagih/dibayar ditinjau lebih dulu.',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function findSheetIndex(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $name): ?int
    {
        foreach ($spreadsheet->getSheetNames() as $i => $sheetName) {
            if (strtolower(trim($sheetName)) === strtolower($name)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Preview-scan (maks $previewRows baris pertama) untuk menemukan baris header
     * berdasarkan isi kolom pertama, TANPA memuat seluruh sheet ke satu array PHP.
     * Menggantikan parseSheet() lama — lihat komentar PARSE_CHUNK di atas.
     *
     * @return array{found: bool, dataStart: int, highestColumn: string, highestRow: int, headerRow: array<int,string>}
     */
    private function detectMasterHeaderStart(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $headerFirstCol,
        int $maxCols,
        int $previewRows = 100,
    ): array {
        $highestRow   = $sheet->getHighestRow();
        $maxColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxCols);
        $previewEnd   = min($previewRows, $highestRow);

        $preview = $sheet->rangeToArray("A1:{$maxColLetter}{$previewEnd}", null, true, false, false);

        foreach ($preview as $idx => $cells) {
            $firstCell = trim($this->xlsxRawValueToString($cells[0] ?? ''));
            if ($this->normalizeHeaderName($firstCell) === strtolower($headerFirstCol)) {
                return [
                    'found'         => true,
                    'dataStart'     => $idx + 2, // 0-based idx -> baris header 1-based -> baris data pertama
                    'highestColumn' => $maxColLetter,
                    'highestRow'    => $highestRow,
                    'headerRow'     => array_map(fn ($c) => $this->xlsxRawValueToString($c), $cells),
                ];
            }
        }

        return ['found' => false, 'dataStart' => 0, 'highestColumn' => $maxColLetter, 'highestRow' => $highestRow, 'headerRow' => []];
    }

    /**
     * Baca baris data mulai $dataStart per $chunkSize baris via rangeToArray(), panggil
     * $onRow(array $cells) per baris, lalu lepas baris yang sudah diproses lewat removeRow()
     * supaya memori tidak menumpuk. removeRow() menggeser baris di bawahnya naik, jadi chunk
     * berikutnya SELALU dibaca ulang dari $dataStart yang tetap — pola sama seperti
     * InvoiceImportService::chunkInvoiceRows() / AbstractBankParser::runChunkedRows().
     */
    private function chunkMasterRows(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $dataStart, string $highestColumn, int $chunkSize, callable $onRow,
    ): void {
        while (($currentHighest = $sheet->getHighestRow()) >= $dataStart) {
            $end   = min($dataStart + $chunkSize - 1, $currentHighest);
            $count = $end - $dataStart + 1;
            $rows  = $sheet->rangeToArray("A{$dataStart}:{$highestColumn}{$end}", null, true, false, false);

            foreach ($rows as $rawRow) {
                $onRow(array_map(fn ($c) => $this->xlsxRawValueToString($c), $rawRow));
            }

            $sheet->removeRow($dataStart, $count);
        }
    }

    /** @return array{0: array<string,int>, 1: array<string,int>} [ktp => investor_id, npwp => investor_id] */
    /** @return array<string,int> lower(field) => id (first occurrence) */
    private function buildLowerMap(iterable $collection, string $field): array
    {
        $map = [];
        foreach ($collection as $model) {
            $val = $model->{$field};
            if ($val !== null && $val !== '' && !isset($map[strtolower($val)])) {
                $map[strtolower($val)] = $model->id;
            }
        }
        return $map;
    }

    // ──────────────────────────────────────────────────────────────
    //  Preload dedup map Investor/Resto/KlienAr — hindari query per baris di
    //  processMasterDataSheet() untuk lookup "sudah ada atau belum". Key builder
    //  dipakai KONSISTEN baik saat preload maupun saat lookup per-baris di loop
    //  utama, supaya tidak ada drift logic.
    // ──────────────────────────────────────────────────────────────

    private function investorDedupKey(string $namaInvestor, ?string $kodeCabang, ?string $idCabang): string
    {
        return strtolower($namaInvestor) . '|' . strtolower((string) $kodeCabang) . '|' . strtolower((string) $idCabang);
    }

    private function restoDedupKeyByKode(string $kodeResto): string
    {
        return strtolower($kodeResto);
    }

    private function restoDedupKeyByNama(string $namaResto): string
    {
        return strtolower($namaResto);
    }

    /**
     * Fallback nama Client AR RESTO/B2C ketika nama_investor kosong di baris MASTER
     * DATA (bukan gagal simpan — genuinely tidak diisi). Format: "{kode_resto}
     * ({nama_resto})". Dipotong ke 150 char (batas validasi KlienAr.nama_klien) supaya
     * nama_resto yang sangat panjang tidak menggagalkan baris.
     */
    private function buildFallbackKlienArName(?string $kodeResto, string $namaResto): string
    {
        $label = $kodeResto ? "{$kodeResto} ({$namaResto})" : $namaResto;

        return mb_strlen($label) > 150 ? mb_substr($label, 0, 150) : $label;
    }

    private function klienDedupKeyByPerusahaan(int $perusahaanId): string
    {
        return (string) $perusahaanId;
    }

    private function klienDedupKeyByResto(int $restoId): string
    {
        return (string) $restoId;
    }

    /**
     * Tulis balik Resto yang baru dibuat/diupdate ke KEDUA map (by kode & by nama) — bukan
     * cuma map yang cocok dengan mode lookup baris ini. Meniru perilaku query-per-baris asli:
     * baris LAIN di file yang sama boleh mencari resto ini lewat salah satu dari 2 cara
     * (kode_resto ATAU nama_resto), terlepas dari cara baris INI menemukannya.
     */
    private function writeRestoDedupMaps(Resto $resto, array &$restoByKodeMap, array &$restoByNamaMap): void
    {
        if ($resto->kode_resto) {
            $restoByKodeMap[$this->restoDedupKeyByKode($resto->kode_resto)] = $resto;
        }
        if ($resto->nama_resto) {
            $restoByNamaMap[$this->restoDedupKeyByNama($resto->nama_resto)] = $resto;
        }
    }

    /**
     * Pre-scan SELURUH sheet MASTER DATA (satu kali baca penuh via rangeToArray(), TANPA
     * removeRow() — beda dari chunkMasterRows() yang dipakai loop utama) untuk mengumpulkan
     * kandidat key dedup Investor/Resto/KlienAr, lalu bulk-query sekali per entitas alih-alih
     * 1 query per baris. Baris baru yang dibuat SELAMA loop utama tetap ditulis balik ke map
     * yang dikembalikan di sini (lihat pemanggil) supaya baris duplikat berikutnya di file
     * yang sama tetap ter-dedup benar — persis seperti pola Brand/Karyawan/Perusahaan yang
     * sudah ada di atas.
     *
     * Jalur fallback yang jarang terjadi (KlienAr by nama_klien+tipe_klien saat perusahaan_id/
     * resto_id gagal diresolusi, dan Resto-by-nama saat baris Resto sendiri gagal validasi)
     * SENGAJA tidak di-preload — tetap query live per-baris seperti sebelumnya, karena hanya
     * relevan untuk baris yang sudah gagal validasi di jalur utama (bukan hot path).
     *
     * @return array{
     *     investor: array<string, Investor>,
     *     resto_by_kode: array<string, Resto>,
     *     resto_by_nama: array<string, Resto>,
     *     klien_by_perusahaan: array<string, KlienAr>,
     *     klien_by_resto: array<string, KlienAr>,
     * }
     */
    private function preloadMasterDataDedupMaps(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $detected,
        callable $col,
        array $perusahaanMap,
    ): array {
        $empty = [
            'investor' => [], 'resto_by_kode' => [], 'resto_by_nama' => [],
            'klien_by_perusahaan' => [], 'klien_by_resto' => [],
        ];

        if ($detected['highestRow'] < $detected['dataStart']) {
            return $empty;
        }

        $rawRows = $sheet->rangeToArray(
            "A{$detected['dataStart']}:{$detected['highestColumn']}{$detected['highestRow']}",
            null, true, false, false,
        );

        $namaInvestorList     = [];
        $kodeRestoList        = [];
        $namaRestoFallbackList = [];
        $ptPerusahaanIdSet    = [];
        $parsedRows           = [];

        foreach ($rawRows as $rawRow) {
            $row = array_map(fn ($c) => $this->xlsxRawValueToString($c), $rawRow);

            $firstCell = trim((string) ($row[0] ?? ''));
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;
            if (str_starts_with($firstCell, '#') || str_starts_with($firstCell, '[CONTOH]')) continue;

            $namaInvestor = trim($firstCell);
            $namaCabang   = trim((string) $col($row, 'nama_cabang'));
            $tipeKlien    = $this->normalizeTipeKlien(trim((string) $col($row, 'tipe_klien')))['value'] ?? '';
            $namaEntitas  = $this->importValue($col($row, 'nama_entitas')) ?? '';
            $kodeResto    = $this->importValue($col($row, 'kode_resto')) ?? '';

            if ($namaInvestor !== '') {
                $namaInvestorList[strtolower($namaInvestor)] = $namaInvestor;
            }

            $parsedRows[] = [
                'nama_cabang' => $namaCabang,
                'tipe_klien'  => $tipeKlien,
                'kode_resto'  => $kodeResto,
            ];

            if ($namaCabang !== '') {
                if ($kodeResto !== '') {
                    $kodeRestoList[strtolower($kodeResto)] = $kodeResto;
                } else {
                    $namaRestoFallbackList[strtolower($namaCabang)] = $namaCabang;
                }

                if ($tipeKlien === 'PT' && $namaEntitas !== '') {
                    $pid = $perusahaanMap[strtolower($namaEntitas)] ?? null;
                    if ($pid) $ptPerusahaanIdSet[$pid] = true;
                }
            }
        }

        $investorMap = [];
        if (! empty($namaInvestorList)) {
            Investor::whereIn('nama_investor', array_values($namaInvestorList))
                ->orderBy('created_at')
                ->get()
                ->each(function (Investor $inv) use (&$investorMap) {
                    $investorMap[$this->investorDedupKey((string) $inv->nama_investor, $inv->kode_cabang, $inv->id_cabang)] = $inv;
                });
        }

        $restoByKodeMap = [];
        if (! empty($kodeRestoList)) {
            Resto::whereIn('kode_resto', array_values($kodeRestoList))
                ->orderBy('created_at')
                ->get()
                ->each(function (Resto $r) use (&$restoByKodeMap) {
                    $restoByKodeMap[$this->restoDedupKeyByKode((string) $r->kode_resto)] = $r;
                });
        }

        $restoByNamaMap = [];
        if (! empty($namaRestoFallbackList)) {
            Resto::whereIn('nama_resto', array_values($namaRestoFallbackList))
                ->orderBy('created_at')
                ->get()
                ->each(function (Resto $r) use (&$restoByNamaMap) {
                    $restoByNamaMap[$this->restoDedupKeyByNama((string) $r->nama_resto)] = $r;
                });
        }

        // Fase 2: perlu resto_id EXISTING (dari map di atas) untuk preload KlienAr tipe RESTO —
        // resto yang baru akan dibuat DALAM loop utama otomatis tidak punya KlienAr existing juga.
        $restoIdsForKlien = [];
        foreach ($parsedRows as $pr) {
            if ($pr['tipe_klien'] !== 'RESTO' || $pr['nama_cabang'] === '') continue;

            $existingResto = $pr['kode_resto'] !== ''
                ? ($restoByKodeMap[$this->restoDedupKeyByKode($pr['kode_resto'])] ?? null)
                : ($restoByNamaMap[$this->restoDedupKeyByNama($pr['nama_cabang'])] ?? null);

            if ($existingResto) {
                $restoIdsForKlien[$existingResto->id] = true;
            }
        }

        $klienByPerusahaanMap = [];
        if (! empty($ptPerusahaanIdSet)) {
            KlienAr::whereIn('perusahaan_id', array_keys($ptPerusahaanIdSet))
                ->where('tipe_klien', 'PT')
                ->orderBy('created_at')
                ->get()
                ->each(function (KlienAr $k) use (&$klienByPerusahaanMap) {
                    $klienByPerusahaanMap[$this->klienDedupKeyByPerusahaan((int) $k->perusahaan_id)] = $k;
                });
        }

        $klienByRestoMap = [];
        if (! empty($restoIdsForKlien)) {
            KlienAr::whereIn('resto_id', array_keys($restoIdsForKlien))
                ->where('tipe_klien', 'RESTO')
                ->orderBy('created_at')
                ->get()
                ->each(function (KlienAr $k) use (&$klienByRestoMap) {
                    $klienByRestoMap[$this->klienDedupKeyByResto((int) $k->resto_id)] = $k;
                });
        }

        return [
            'investor' => $investorMap,
            'resto_by_kode' => $restoByKodeMap,
            'resto_by_nama' => $restoByNamaMap,
            'klien_by_perusahaan' => $klienByPerusahaanMap,
            'klien_by_resto' => $klienByRestoMap,
        ];
    }

    /**
     * Pre-scan sheet MASTER BARANG (pola sama seperti preloadMasterDataDedupMaps()) supaya
     * lookup "kode_barang sudah ada atau belum" tidak query per baris.
     *
     * @return array<string, Barang> kode_barang (uppercase) => Barang
     */
    private function preloadBarangMap(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $detected, callable $col): array
    {
        if ($detected['highestRow'] < $detected['dataStart']) {
            return [];
        }

        $rawRows = $sheet->rangeToArray(
            "A{$detected['dataStart']}:{$detected['highestColumn']}{$detected['highestRow']}",
            null, true, false, false,
        );

        $kodeSet = [];
        foreach ($rawRows as $rawRow) {
            $row = array_map(fn ($c) => $this->xlsxRawValueToString($c), $rawRow);

            $firstCell = trim((string) ($row[0] ?? ''));
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;
            if (str_starts_with($firstCell, '#') || str_starts_with($firstCell, '[CONTOH]')) continue;

            $kode = strtoupper(trim((string) $col($row, 'kode_barang')));
            if ($kode !== '') $kodeSet[$kode] = true;
        }

        if (empty($kodeSet)) {
            return [];
        }

        $map = [];
        Barang::whereIn('kode_barang', array_keys($kodeSet))->get()
            ->each(function (Barang $b) use (&$map) {
                $map[strtoupper((string) $b->kode_barang)] = $b;
            });

        return $map;
    }

    /**
     * Hilangkan tanda mandatory "(*)" dari nama header Excel (mis. "nama_investor (*)" → "nama_investor")
     * lalu lowercase+trim — supaya template lama (tanpa tanda) dan baru (dengan tanda) sama-sama terbaca.
     */
    private function normalizeHeaderName(string $raw): string
    {
        $s = strtolower(trim($raw));
        return trim(preg_replace('/\(\s*\*\s*\)\s*$/', '', $s));
    }

    /**
     * Parse identifier PIC (nama_pic / pic_ar) menjadi [nama, nik]. Format yang didukung:
     *   "Nama", "NIK", "Nama / NIK", "Nama - NIK", "Nama (NIK)".
     * NIK dikenali sebagai token alfanumerik 4-30 karakter yang mengandung minimal 1 digit
     * (mis. NIK KTP 16-digit, atau kode karyawan alfanumerik spt "FL0401780"). Syarat "minimal
     * 1 digit" sengaja dipertahankan supaya nama gabungan yang kebetulan memakai "-"/"/" (mis.
     * "Budi - Santoso") tidak salah kebaca sebagai NIK — nama praktis tidak pernah mengandung
     * digit. Kalau tidak ada token NIK yang cocok, seluruh identifier dianggap nama saja
     * (perilaku lama tidak berubah).
     *
     * @return array{nama: ?string, nik: ?string}
     */
    private function parsePicIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return ['nama' => null, 'nik' => null];
        }

        $nikToken = '(?=[A-Za-z0-9]*\d)[A-Za-z0-9]{4,30}';

        if (preg_match("/^{$nikToken}$/", $identifier)) {
            return ['nama' => null, 'nik' => $identifier];
        }

        if (preg_match("/^(.+?)\\s*\\(\\s*({$nikToken})\\s*\\)$/", $identifier, $m)) {
            return ['nama' => trim($m[1]) !== '' ? trim($m[1]) : null, 'nik' => $m[2]];
        }

        if (preg_match("/^(.+?)\\s*[\\/\\-]\\s*({$nikToken})$/", $identifier, $m)) {
            return ['nama' => trim($m[1]) !== '' ? trim($m[1]) : null, 'nik' => $m[2]];
        }

        return ['nama' => $identifier, 'nik' => null];
    }

    /**
     * Resolve id karyawan dari input yang boleh berupa nama_karyawan, nik, ATAU kombinasi
     * Nama+NIK (lihat parsePicIdentifier()) — case-insensitive untuk nama. Untuk kombinasi,
     * NIK dipakai sebagai identitas utama (nama pada kombinasi tidak dipakai untuk lookup,
     * hanya untuk sinkronisasi — lihat resolveKaryawanNameSync()).
     */
    private function resolveKaryawanIdByNameOrNik(string $identifier, array $karyawanMap, array $karyawanNikMap): ?int
    {
        $parsed = $this->parsePicIdentifier($identifier);

        if ($parsed['nik'] !== null) {
            return $karyawanNikMap[strtolower($parsed['nik'])] ?? null;
        }

        return $karyawanMap[strtolower($parsed['nama'] ?? '')] ?? null;
    }

    /**
     * Tentukan apakah tb_karyawan.nama_karyawan perlu disinkronkan dari identifier PIC
     * (nama_pic/pic_ar) yang memuat kombinasi Nama+NIK — murni pengambilan keputusan
     * (read-only, tidak menyentuh DB), supaya bisa diuji tanpa DB.
     *
     * Guardrail: sinkronisasi HANYA layak terjadi kalau identifier memuat NIK valid yang
     * sudah ter-resolve ke $karyawanId — nama-only tidak pernah memicu ini (mencegah sistem
     * "menebak" orang dari nama saja).
     *
     * @param array<int,string> $karyawanNameById id => nama_karyawan tersimpan saat ini
     * @return array{nama_baru: ?string, should_update: bool, error: ?string}
     */
    private function resolveKaryawanNameSync(string $identifier, ?int $karyawanId, array $karyawanNameById): array
    {
        if ($karyawanId === null) {
            return ['nama_baru' => null, 'should_update' => false, 'error' => null];
        }

        $parsed = $this->parsePicIdentifier($identifier);
        if ($parsed['nik'] === null || !$parsed['nama']) {
            return ['nama_baru' => null, 'should_update' => false, 'error' => null];
        }

        $namaBaru = $parsed['nama'];
        if (mb_strlen($namaBaru) > 100) {
            return [
                'nama_baru'     => null,
                'should_update' => false,
                'error'         => "Nama PIC '{$namaBaru}' (NIK {$parsed['nik']}) melebihi 100 karakter — nama karyawan tidak diperbarui.",
            ];
        }

        $namaLama = $karyawanNameById[$karyawanId] ?? null;
        if ($namaLama === $namaBaru) {
            return ['nama_baru' => null, 'should_update' => false, 'error' => null];
        }

        return ['nama_baru' => $namaBaru, 'should_update' => true, 'error' => null];
    }

    /**
     * Terapkan hasil resolveKaryawanNameSync(): update tb_karyawan.nama_karyawan (+updated_by)
     * secara sempit (hanya 2 kolom ini — nik/perusahaan_id/relasi lain tidak tersentuh), lalu
     * perbarui peta lookup runtime supaya baris berikutnya di import yang sama ikut konsisten.
     * Side-effect DB, sengaja tidak diuji unit (pola sama dengan pemanggilan *Service->update()
     * lain di file ini) — lihat catatan test DB di memory project.
     */
    private function applyKaryawanNameSync(int $karyawanId, string $namaBaru, array &$karyawanMap, array &$karyawanNameById, int $actingUserId): void
    {
        Karyawan::whereKey($karyawanId)->update([
            'nama_karyawan' => $namaBaru,
            'updated_by'    => $actingUserId,
        ]);

        $karyawanNameById[$karyawanId]      = $namaBaru;
        $karyawanMap[strtolower($namaBaru)] = $karyawanId;
    }

    /**
     * Resolve PIC Resto (kolom nama_pic) untuk satu baris MASTER DATA, dengan aturan:
     *   - nama_pic boleh diisi nama karyawan, NIK, ATAU kombinasi Nama+NIK (lihat
     *     parsePicIdentifier()) — kombinasi men-sync nama_karyawan bila berbeda dari master
     *     (lihat resolveKaryawanNameSync() / applyKaryawanNameSync()).
     *   - Jika nama_pic kosong dan tipe_klien PT/RESTO, pic_ar dipakai sebagai pengganti (fallback).
     *   - Khusus tipe_klien RESTO: jika nama_pic & pic_ar SAMA-SAMA diisi (bukan hasil fallback) dan
     *     keduanya berhasil di-resolve tapi menunjuk karyawan yang berbeda, baris dianggap ambigu.
     *     (Tidak berlaku untuk PT karena nama_pic = PIC Resto dan pic_ar = PIC AR Client memang dua
     *     peran berbeda yang boleh diisi orang berbeda.)
     *
     * @param array<string,int> $karyawanMap lower(nama) => id (state awal; hasil sinkron dikembalikan lewat return, bukan by-ref, supaya method ini tetap pure/mudah diuji)
     * @param array<int,string> $karyawanNameById id => nama_karyawan tersimpan saat ini
     * @return array{karyawan_id: ?int, identifier: string, used_fallback: bool, conflict_error: ?string, name_sync_errors: string[], karyawan_map: array<string,int>, karyawan_name_by_id: array<int,string>}
     */
    private function resolvePicRestoForRow(
        string $namaPicResto,
        string $picArRaw,
        string $tipeKlien,
        array $karyawanMap,
        array $karyawanNikMap,
        array $karyawanNameById,
        int $actingUserId,
    ): array {
        $identifier   = $namaPicResto;
        $usedFallback = false;

        if ($identifier === '' && $picArRaw !== '' && in_array($tipeKlien, ['PT', 'RESTO'], true)) {
            $identifier   = $picArRaw;
            $usedFallback = true;
        }

        $nameSyncErrors = [];
        $karyawanId     = null;

        if ($identifier !== '') {
            $karyawanId = $this->resolveKaryawanIdByNameOrNik($identifier, $karyawanMap, $karyawanNikMap);

            $sync = $this->resolveKaryawanNameSync($identifier, $karyawanId, $karyawanNameById);
            if ($sync['error']) {
                $nameSyncErrors[] = $sync['error'];
            } elseif ($sync['should_update']) {
                $this->applyKaryawanNameSync($karyawanId, $sync['nama_baru'], $karyawanMap, $karyawanNameById, $actingUserId);
            }
        }

        $conflictError = null;
        if ($tipeKlien === 'RESTO' && !$usedFallback && $namaPicResto !== '' && $picArRaw !== '' && $karyawanId) {
            $picArKaryawanId = $this->resolveKaryawanIdByNameOrNik($picArRaw, $karyawanMap, $karyawanNikMap);

            $sync = $this->resolveKaryawanNameSync($picArRaw, $picArKaryawanId, $karyawanNameById);
            if ($sync['error']) {
                $nameSyncErrors[] = $sync['error'];
            } elseif ($sync['should_update']) {
                $this->applyKaryawanNameSync($picArKaryawanId, $sync['nama_baru'], $karyawanMap, $karyawanNameById, $actingUserId);
            }

            if ($picArKaryawanId && $picArKaryawanId !== $karyawanId) {
                $conflictError = "nama_pic ('{$namaPicResto}') dan pic_ar ('{$picArRaw}') menunjuk karyawan yang berbeda — isi salah satu saja atau samakan keduanya";
            }
        }

        return [
            'karyawan_id'         => $karyawanId,
            'identifier'          => $identifier,
            'used_fallback'       => $usedFallback,
            'conflict_error'      => $conflictError,
            'name_sync_errors'    => $nameSyncErrors,
            'karyawan_map'        => $karyawanMap,
            'karyawan_name_by_id' => $karyawanNameById,
        ];
    }

    /** @return array<string,int> nama_perusahaan & singkatan (lower) => id */
    private function buildPerusahaanMap(): array
    {
        $map = [];
        foreach (Perusahaan::all(['id', 'nama_perusahaan', 'nama_singkatan_perusahaan']) as $p) {
            if ($p->nama_perusahaan && !isset($map[strtolower($p->nama_perusahaan)])) {
                $map[strtolower($p->nama_perusahaan)] = $p->id;
            }
            if ($p->nama_singkatan_perusahaan && !isset($map[strtolower($p->nama_singkatan_perusahaan)])) {
                $map[strtolower($p->nama_singkatan_perusahaan)] = $p->id;
            }
        }
        return $map;
    }

    private function buildSummaryMessage(ImportMasterBatch $batch, int $investorSkipped = 0, int $restoSkipped = 0): string
    {
        $parts = [];
        if ($batch->master_total > 0) {
            $parts[] = sprintf(
                'MASTER DATA: Investor +%d ~%d ⊘%d ✗%d | Resto +%d ~%d ⊘%d ✗%d | Client +%d ~%d ⊘%d ✗%d',
                $batch->investor_inserted, $batch->investor_updated, $investorSkipped, $batch->investor_failed,
                $batch->resto_inserted,    $batch->resto_updated,    $restoSkipped,    $batch->resto_failed,
                $batch->klien_inserted,    $batch->klien_updated,    $batch->klien_skipped, $batch->klien_failed,
            );
        }
        if ($batch->barang_total > 0) {
            $parts[] = sprintf(
                'MASTER BARANG: Barang +%d ~%d ⊘%d ✗%d',
                $batch->barang_inserted, $batch->barang_updated, $batch->barang_skipped, $batch->barang_failed,
            );
        }
        return implode(' | ', $parts) ?: 'Import selesai.';
    }

    /**
     * Konversi nilai mentah dari rangeToArray() (bukan lagi objek Cell — setReadDataOnly(true)
     * tidak memuat style, jadi info format tanggal cell tidak tersedia). Cabang isDateTime()
     * versi lama sengaja dihilangkan: importDate() sudah punya fallback numerik sendiri yang
     * menghasilkan tanggal identik untuk cell bertipe serial number.
     */
    private function xlsxRawValueToString(mixed $value): string
    {
        if ($value === null)  return '';
        if (is_bool($value))  return $value ? '1' : '0';
        if (is_int($value))   return (string) $value;
        if (is_float($value)) {
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }
        return trim((string) $value);
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
                $col         = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
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
        $s = trim((string) $val);
        if ($s === '' || $s === '-') return null;

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // Fallback: serial number Excel yang lolos tanpa format tanggal pada cell (mis. hasil paste/text)
        if (is_numeric($s)) {
            try {
                return PhpSpreadsheetDate::excelToDateTimeObject((float) $s)->format('Y-m-d');
            } catch (\Throwable) {
                return $s;
            }
        }
        return $s;
    }

    private function parseStatus(string $raw): bool
    {
        if ($raw === '') return true;
        return in_array(strtolower($raw), ['aktif', '1', 'true', 'yes', 'ya']);
    }

    /**
     * Normalisasi alias B2C/B2B pada kolom Excel tipe_klien menjadi kontrak internal
     * RESTO/PT yang dipakai KlienAr.tipe_klien di seluruh sistem — B2C selalu berarti
     * RESTO, B2B selalu berarti PT. Kosong tetap valid (baris ini tidak membuat Client AR);
     * hanya nilai non-kosong yang tidak dikenali yang dianggap error.
     *
     * @return array{value: ?string, error: ?string}
     */
    private function normalizeTipeKlien(string $raw): array
    {
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return ['value' => '', 'error' => null];
        }

        if (in_array($s, ['RESTO', 'B2C', 'RESTO/B2C', 'B2C/RESTO'], true)) {
            return ['value' => 'RESTO', 'error' => null];
        }
        if (in_array($s, ['PT', 'B2B', 'PT/B2B', 'B2B/PT'], true)) {
            return ['value' => 'PT', 'error' => null];
        }

        return ['value' => null, 'error' => "tipe_klien harus RESTO/B2C atau PT/B2B (nilai '{$raw}' tidak dikenali)"];
    }

    private function normalizeStr(mixed $val): ?string
    {
        if ($val === null) return null;
        $s = trim((string) $val);
        return ($s === '' || $s === '-') ? null : $s;
    }

    private function normalizeId(mixed $val): ?int
    {
        if ($val === null || $val === '' || $val === '-') return null;
        $i = (int) $val;
        return $i === 0 ? null : $i;
    }

    /**
     * Tambah 1 entri detail (diperbarui/dilewati), dibatasi MAX_DETAILS agar payload/kolom json
     * tidak membengkak pada import ±13.000 baris — counter agregat tetap akurat & tidak dibatasi.
     */
    private function pushDetail(array &$details, string $sheet, int $row, string $message): void
    {
        if (count($details) < self::MAX_DETAILS) {
            $details[] = ['sheet' => $sheet, 'row' => $row, 'message' => $message];
        }
    }

    /** Format hasil *Diff() jadi pesan siap-tampil: "Label: lama → baru; Label2: lama2 → baru2". */
    private function formatDiffMessage(array $diff, array $fieldLabels): string
    {
        $parts = [];
        foreach ($diff as $field => $change) {
            $label   = $fieldLabels[$field] ?? $field;
            $lama    = $change['lama'] ?? '(kosong)';
            $baru    = $change['baru'] ?? '(kosong)';
            $parts[] = "{$label}: {$lama} → {$baru}";
        }
        return implode('; ', $parts);
    }

    private function statusLabel(bool $status): string
    {
        return $status ? 'Aktif' : 'Nonaktif';
    }

    /** @return array<string, array{lama: ?string, baru: ?string}> field yang berubah — kosong berarti tidak ada perubahan. */
    private function investorDiff(Investor $existing, array $import): array
    {
        $diff = [];
        foreach (['nama_investor', 'ktp', 'npwp', 'no_hp', 'pengelola', 'no_hp_pengelola', 'kode_cabang', 'id_cabang'] as $f) {
            $lama = $this->normalizeStr($existing->{$f});
            $baru = $this->normalizeStr($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama, 'baru' => $baru];
            }
        }
        $statusLama = (bool) $existing->status;
        $statusBaru = (bool) ($import['status'] ?? true);
        if ($statusLama !== $statusBaru) {
            $diff['status'] = ['lama' => $this->statusLabel($statusLama), 'baru' => $this->statusLabel($statusBaru)];
        }
        return $diff;
    }

    /** @return array<string, array{lama: ?string, baru: ?string}> */
    private function restoDiff(Resto $existing, array $import): array
    {
        $diff = [];
        foreach (['nama_resto', 'supervisor', 'no_hp_supervisor', 'stokis', 'area', 'kota', 'alamat', 'no_telp', 'keterangan'] as $f) {
            $lama = $this->normalizeStr($existing->{$f});
            $baru = $this->normalizeStr($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama, 'baru' => $baru];
            }
        }
        foreach (['perusahaan_id', 'brand_id', 'investor_id', 'karyawan_id'] as $f) {
            $lama = $this->normalizeId($existing->{$f});
            $baru = $this->normalizeId($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama !== null ? (string) $lama : null, 'baru' => $baru !== null ? (string) $baru : null];
            }
        }
        // tgl_aktif: bandingkan sebagai Y-m-d
        $existingDate = $existing->tgl_aktif
            ? (is_string($existing->tgl_aktif) ? substr($existing->tgl_aktif, 0, 10) : $existing->tgl_aktif->format('Y-m-d'))
            : null;
        if ($existingDate !== $import['tgl_aktif']) {
            $diff['tgl_aktif'] = ['lama' => $existingDate, 'baru' => $import['tgl_aktif']];
        }
        $statusLama = (bool) $existing->status;
        $statusBaru = (bool) ($import['status'] ?? true);
        if ($statusLama !== $statusBaru) {
            $diff['status'] = ['lama' => $this->statusLabel($statusLama), 'baru' => $this->statusLabel($statusBaru)];
        }
        return $diff;
    }

    /** @return array<string, array{lama: ?string, baru: ?string}> */
    private function barangDiff(Barang $existing, array $import): array
    {
        $diff = [];
        foreach (['nama_barang', 'spesifikasi', 'keterangan'] as $f) {
            $lama = $this->normalizeStr($existing->{$f});
            $baru = $this->normalizeStr($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama, 'baru' => $baru];
            }
        }
        $statusLama = (bool) $existing->status;
        $statusBaru = (bool) ($import['status'] ?? true);
        if ($statusLama !== $statusBaru) {
            $diff['status'] = ['lama' => $this->statusLabel($statusLama), 'baru' => $this->statusLabel($statusBaru)];
        }
        return $diff;
    }

    /** @return array<string, array{lama: ?string, baru: ?string}> */
    private function klienArDiff(KlienAr $existing, array $import): array
    {
        $diff = [];
        foreach (['nama_klien', 'tipe_klien', 'no_npwp', 'no_wa'] as $f) {
            $lama = $this->normalizeStr($existing->{$f});
            $baru = $this->normalizeStr($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama, 'baru' => $baru];
            }
        }
        foreach (['perusahaan_id', 'karyawan_ar_id', 'resto_id'] as $f) {
            $lama = $this->normalizeId($existing->{$f});
            $baru = $this->normalizeId($import[$f]);
            if ($lama !== $baru) {
                $diff[$f] = ['lama' => $lama !== null ? (string) $lama : null, 'baru' => $baru !== null ? (string) $baru : null];
            }
        }
        $statusLama = (bool) $existing->status;
        $statusBaru = (bool) ($import['status'] ?? true);
        if ($statusLama !== $statusBaru) {
            $diff['status'] = ['lama' => $this->statusLabel($statusLama), 'baru' => $this->statusLabel($statusBaru)];
        }
        return $diff;
    }
}
