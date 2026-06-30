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
 */
class MasterImportService
{
    private const CHUNK = 100;

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

        $fullPath    = $disk->path($batch->file_path);
        $spreadsheet = IOFactory::load($fullPath);

        $batch->update(['status' => 'processing']);

        $errors = [];

        $this->processMasterDataSheet($spreadsheet, $batch, $errors);
        $this->processMasterBarangSheet($spreadsheet, $batch, $errors);

        $batch->update([
            'status'  => 'completed',
            'errors'  => $errors,
            'message' => $this->buildSummaryMessage($batch->fresh()),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Sheet 1: MASTER DATA (Investor + Resto + KlienAr)
    // ──────────────────────────────────────────────────────────────

    private function processMasterDataSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ImportMasterBatch $batch, array &$errors): void
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER DATA');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Sheet "MASTER DATA" tidak ditemukan dalam file.'];
            return;
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows  = $this->parseSheet($sheet, 'nama_investor', 27);

        if (empty($rows)) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Header "nama_investor" tidak ditemukan di sheet MASTER DATA.'];
            return;
        }

        $headerRow = array_map(fn($c) => strtolower(trim((string) $c)), $rows[0] ?? []);
        if (in_array('nama_klien', $headerRow)) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Template lama masih memiliki kolom nama_klien. Download template terbaru.'];
            return;
        }

        $batch->update(['master_total' => count($rows)]);

        // Preload referensi
        $actingUserId  = $batch->user_id;
        $brandMap      = $this->buildLowerMap(Brand::all(['id', 'nama_brand']), 'nama_brand');
        $karyawanMap   = $this->buildLowerMap(Karyawan::all(['id', 'nama_karyawan']), 'nama_karyawan');
        $perusahaanMap = $this->buildPerusahaanMap();

        $invIns  = $invUpd  = $invFail  = 0;
        $resIns  = $resUpd  = $resFail  = 0;
        $kliIns  = $kliUpd  = $kliFail  = 0;
        $processed     = 0;
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $batch->update([
                        'master_processed'  => min($processed, $batch->master_total),
                        'investor_inserted' => $invIns,  'investor_updated' => $invUpd,  'investor_failed' => $invFail,
                        'resto_inserted'    => $resIns,  'resto_updated'    => $resUpd,  'resto_failed'    => $resFail,
                        'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,
                    ]);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $namaInvestor  = trim($firstCell);
                $namaCabang    = trim((string) ($row[9] ?? ''));
                $tipeKlien     = strtoupper(trim((string) ($row[25] ?? '')));
                $status        = $this->parseStatus(trim((string) ($row[26] ?? '')));
                $investorFailed = false;

                // ── 1. Investor ─────────────────────────────────────────────
                $investor = null;
                if ($namaInvestor !== '') {
                    $invData = [
                        'nama_investor'   => $namaInvestor,
                        'ktp'             => $this->importValue($row[1] ?? ''),
                        'npwp'            => $this->importValue($row[2] ?? ''),
                        'no_hp'           => $this->importValue($row[3] ?? ''),
                        'pengelola'       => $this->importValue($row[4] ?? ''),
                        'no_hp_pengelola' => $this->importValue($row[5] ?? ''),
                        'kode_cabang'     => $this->importValue($row[6] ?? ''),
                        'id_cabang'       => $this->importValue($row[7] ?? ''),
                        'status'          => $status,
                    ];

                    $validator = Validator::make($invData, [
                        'nama_investor'   => ['required', 'string', 'max:150'],
                        'ktp'             => ['nullable', 'string', 'max:20'],
                        'npwp'            => ['nullable', 'string', 'max:20'],
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
                        $existing = Investor::where('nama_investor', $invData['nama_investor'])
                            ->where('kode_cabang', $invData['kode_cabang'])
                            ->where('id_cabang', $invData['id_cabang'])
                            ->latest()->first();

                        try {
                            if ($existing) {
                                $existing->updated_by = $actingUserId;
                                $investor = $this->investorService->update($existing, InvestorDTO::fromRequest($invData));
                                $invUpd++;
                            } else {
                                $investor = $this->investorService->create(InvestorDTO::fromRequest($invData));
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
                    $namaPerusahaan = $this->importValue($row[10] ?? '') ?? '';
                    $namaBrand      = $this->importValue($row[11] ?? '') ?? '';
                    $kodeResto      = $this->importValue($row[8] ?? '') ?? '';
                    $namaPicResto   = $this->importValue($row[12] ?? '') ?? '';

                    // Fallback: untuk tipe PT, gunakan pic_ar jika nama_pic kosong
                    $picRestoFallback = false;
                    if ($namaPicResto === '' && $tipeKlien === 'PT') {
                        $namaPicArFallback = $this->importValue($row[22] ?? '') ?? '';
                        if ($namaPicArFallback !== '') {
                            $namaPicResto     = $namaPicArFallback;
                            $picRestoFallback = true;
                        }
                    }

                    $perusahaanId = null;
                    $brandId      = null;
                    $karyawanId   = null;
                    $rowErrors    = [];

                    if ($namaPerusahaan) {
                        $perusahaanId = $perusahaanMap[strtolower($namaPerusahaan)] ?? null;
                        if (!$perusahaanId) $rowErrors[] = "Entitas '{$namaPerusahaan}' tidak ditemukan";
                    }
                    if ($namaBrand) {
                        $brandId = $brandMap[strtolower($namaBrand)] ?? null;
                        if (!$brandId) $rowErrors[] = "Brand '{$namaBrand}' tidak ditemukan";
                    }
                    if ($namaPicResto) {
                        $karyawanId = $karyawanMap[strtolower($namaPicResto)] ?? null;
                        if (!$karyawanId) {
                            $picLabel    = $picRestoFallback ? 'PIC Resto/PIC AR' : 'PIC Resto';
                            $rowErrors[] = "{$picLabel} '{$namaPicResto}' tidak ditemukan";
                        }
                    }

                    $existingResto = Resto::where('nama_resto', $namaCabang)->latest()->first();
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
                            'supervisor'       => $this->importValue($row[13] ?? ''),
                            'no_hp_supervisor' => $this->importValue($row[14] ?? ''),
                            'stokis'           => $this->importValue($row[15] ?? ''),
                            'area'             => $this->importValue($row[16] ?? ''),
                            'kota'             => $this->importValue($row[17] ?? ''),
                            'alamat'           => $this->importValue($row[18] ?? ''),
                            'no_telp'          => $this->importValue($row[19] ?? ''),
                            'tgl_aktif'        => $this->importDate($row[20] ?? ''),
                            'keterangan'       => $this->importValue($row[21] ?? ''),
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
                                    $existingResto->updated_by = $actingUserId;
                                    $resto = $this->restoService->update($existingResto, RestoDTO::fromRequest($resData));
                                    $resUpd++;
                                } else {
                                    $resto = $this->restoService->create(RestoDTO::fromRequest($resData));
                                    $resIns++;
                                }
                            } catch (\Throwable $e) {
                                $errors[] = ['sheet' => 'MASTER DATA', 'row' => $lineNumber, 'message' => '[Resto] Gagal menyimpan: ' . $e->getMessage()];
                                $resFail++;
                            }
                        }
                    }
                }

                // ── 3. KlienAr ──────────────────────────────────────────────
                if ($namaCabang !== '' && in_array($tipeKlien, ['PT', 'RESTO'])) {
                    $namaPicAr      = $this->importValue($row[22] ?? '');
                    $namaEntitasKli = $this->importValue($row[10] ?? '') ?? '';
                    $noNpwp         = $this->importValue($row[23] ?? '');
                    $noWa           = $this->importValue($row[24] ?? '');
                    $rowErrors      = [];
                    $namaKlien      = $namaCabang;

                    $karyawanArId = null;
                    if ($namaPicAr) {
                        $karyawanArId = $karyawanMap[strtolower($namaPicAr)] ?? null;
                        if (!$karyawanArId) $rowErrors[] = "Karyawan AR '{$namaPicAr}' tidak ditemukan";
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

                    // Nama Client AR ditentukan otomatis berdasarkan tipe
                    if ($tipeKlien === 'PT') {
                        $namaKlien = $namaEntitasKli ?: $namaCabang;
                    } elseif ($tipeKlien === 'RESTO') {
                        $investorName = $investor?->nama_investor;
                        if (!$investorName) {
                            $restoForInv = $resto;
                            if (!$restoForInv && $restoIdKli) {
                                $restoForInv = Resto::find($restoIdKli);
                            }
                            $investorName = $restoForInv?->loadMissing('investor')->investor?->nama_investor;
                        }
                        if ($investorName) {
                            $namaKlien = $investorName;
                        } elseif ($investorFailed) {
                            $rowErrors[] = "Dilewati: Investor baris ini gagal tersimpan — nama Client AR tidak dapat ditentukan";
                        } else {
                            $rowErrors[] = "Resto '{$namaCabang}' tidak memiliki investor — tidak dapat menentukan nama Client AR";
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
                            if ($tipeKlien === 'PT' && $perusahaanIdKli) {
                                $existingKlien = KlienAr::where('perusahaan_id', $perusahaanIdKli)
                                    ->where('tipe_klien', 'PT')
                                    ->latest()->first();
                            } elseif ($tipeKlien === 'RESTO' && $restoIdKli) {
                                $existingKlien = KlienAr::where('resto_id', $restoIdKli)
                                    ->where('tipe_klien', 'RESTO')
                                    ->latest()->first();
                            } else {
                                $existingKlien = KlienAr::where('nama_klien', $namaKlien)
                                    ->where('tipe_klien', $tipeKlien)
                                    ->latest()->first();
                            }

                            try {
                                if ($existingKlien) {
                                    $existingKlien->updated_by = $actingUserId;
                                    $this->klienArService->update($existingKlien, KlienArDTO::fromRequest($kliData));
                                    $kliUpd++;
                                } else {
                                    $this->klienArService->create(KlienArDTO::fromRequest($kliData));
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
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }

        $batch->update([
            'master_processed'  => $batch->master_total,
            'investor_inserted' => $invIns,  'investor_updated' => $invUpd,  'investor_failed' => $invFail,
            'resto_inserted'    => $resIns,  'resto_updated'    => $resUpd,  'resto_failed'    => $resFail,
            'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Sheet 2: MASTER BARANG (Barang)
    // ──────────────────────────────────────────────────────────────

    private function processMasterBarangSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ImportMasterBatch $batch, array &$errors): void
    {
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER BARANG');
        if ($sheetIndex === null) {
            $errors[] = ['sheet' => 'MASTER BARANG', 'row' => 0, 'message' => 'Sheet "MASTER BARANG" tidak ditemukan dalam file.'];
            return;
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows  = $this->parseSheet($sheet, 'kode_barang', 6);

        if (empty($rows)) {
            $errors[] = ['sheet' => 'MASTER BARANG', 'row' => 0, 'message' => 'Header "kode_barang" tidak ditemukan di sheet MASTER BARANG.'];
            return;
        }

        $batch->update(['barang_total' => count($rows)]);

        $actingUserId = $batch->user_id;
        $brandMap = $this->buildLowerMap(Brand::all(['id', 'nama_brand']), 'nama_brand');
        $kodeMap  = Barang::all(['id', 'kode_barang'])
            ->mapWithKeys(fn($b) => [strtoupper($b->kode_barang) => $b->id])
            ->all();
        $newKodeSeen = [];

        $brgIns = $brgUpd = $brgFail = 0;
        $processed     = 0;
        $lineNumber    = 0;
        $headerSkipped = false;
        $inChunk       = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($inChunk >= self::CHUNK) {
                    DB::commit();
                    $batch->update([
                        'barang_processed' => min($processed, $batch->barang_total),
                        'barang_inserted'  => $brgIns,
                        'barang_updated'   => $brgUpd,
                        'barang_failed'    => $brgFail,
                    ]);
                    DB::beginTransaction();
                    $inChunk = 0;
                }
                $lineNumber++;
                $processed++;
                $inChunk++;

                $firstCell = trim((string) ($row[0] ?? ''));
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

                $rawKode   = strtoupper(trim((string) ($row[0] ?? '')));
                $rawNama   = trim((string) ($row[1] ?? ''));
                $rawStatus = trim((string) ($row[5] ?? ''));

                $data = [
                    'kode_barang' => $rawKode === '' ? null : $rawKode,
                    'nama_barang' => $rawNama,
                    'spesifikasi' => $this->importValue($row[2] ?? ''),
                    'nama_brand'  => $this->importValue($row[3] ?? ''),
                    'keterangan'  => $this->importValue($row[4] ?? ''),
                    'status'      => $this->parseStatus($rawStatus),
                ];

                $existing = Barang::whereRaw('LOWER(nama_barang) = ?', [strtolower($rawNama)])->first();

                $rules = [
                    'nama_barang' => ['required', 'string', 'max:150'],
                    'spesifikasi' => ['nullable', 'string'],
                    'keterangan'  => ['nullable', 'string'],
                    'status'      => ['nullable', 'boolean'],
                ];
                if (!$existing) {
                    $rules['kode_barang'] = ['required', 'string', 'max:50'];
                }

                $validator = Validator::make($data, $rules);
                if ($validator->fails()) {
                    $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => implode('; ', $validator->errors()->all())];
                    $brgFail++;
                    continue;
                }

                if (!$existing) {
                    $kodeUpper = strtoupper($data['kode_barang']);
                    if (isset($kodeMap[$kodeUpper]) || isset($newKodeSeen[$kodeUpper])) {
                        $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => "kode_barang '{$kodeUpper}' sudah digunakan oleh barang lain."];
                        $brgFail++;
                        continue;
                    }
                }

                $brandId = null;
                if ($data['nama_brand'] !== null) {
                    $brandId = $brandMap[strtolower($data['nama_brand'])] ?? null;
                    if ($brandId === null) {
                        $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => "Brand '{$data['nama_brand']}' tidak ditemukan di sistem."];
                        $brgFail++;
                        continue;
                    }
                }

                try {
                    if ($existing) {
                        $existing->update([
                            'nama_barang' => $data['nama_barang'],
                            'spesifikasi' => $data['spesifikasi'],
                            'brand_id'    => $brandId,
                            'keterangan'  => $data['keterangan'],
                            'status'      => $data['status'] ?? true,
                            'updated_by'  => $actingUserId,
                        ]);
                        $brgUpd++;
                    } else {
                        $kodeUpper = strtoupper($data['kode_barang']);
                        $barang    = Barang::create([
                            'kode_barang' => $kodeUpper,
                            'nama_barang' => $data['nama_barang'],
                            'spesifikasi' => $data['spesifikasi'],
                            'brand_id'    => $brandId,
                            'keterangan'  => $data['keterangan'],
                            'status'      => $data['status'] ?? true,
                        ]);
                        $newKodeSeen[$kodeUpper] = $barang->id;
                        $kodeMap[$kodeUpper]     = $barang->id;
                        $brgIns++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['sheet' => 'MASTER BARANG', 'row' => $lineNumber, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
                    $brgFail++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e;
        }

        $batch->update([
            'barang_processed' => $batch->barang_total,
            'barang_inserted'  => $brgIns,
            'barang_updated'   => $brgUpd,
            'barang_failed'    => $brgFail,
        ]);
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

    private function parseSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $headerFirstCol, int $maxCols): array
    {
        $rows        = [];
        $headerFound = false;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cellIter = $rowObj->getCellIterator();
            $cellIter->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIter as $cell) {
                $cells[] = $this->xlsxCellToString($cell);
            }
            $cells     = array_slice($cells, 0, $maxCols);
            $firstCell = trim($cells[0] ?? '');

            if (!$headerFound) {
                if (strtolower($firstCell) === strtolower($headerFirstCol)) {
                    $headerFound = true;
                    $rows[]      = $cells;
                }
                continue;
            }
            $rows[] = $cells;
        }

        return $rows;
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

    private function buildSummaryMessage(ImportMasterBatch $batch): string
    {
        $parts = [];
        if ($batch->master_total > 0) {
            $parts[] = sprintf(
                'MASTER DATA: Investor +%d ~%d ✗%d | Resto +%d ~%d ✗%d | Client +%d ~%d ✗%d',
                $batch->investor_inserted, $batch->investor_updated, $batch->investor_failed,
                $batch->resto_inserted,    $batch->resto_updated,    $batch->resto_failed,
                $batch->klien_inserted,    $batch->klien_updated,    $batch->klien_failed,
            );
        }
        if ($batch->barang_total > 0) {
            $parts[] = sprintf(
                'MASTER BARANG: Barang +%d ~%d ✗%d',
                $batch->barang_inserted, $batch->barang_updated, $batch->barang_failed,
            );
        }
        return implode(' | ', $parts) ?: 'Import selesai.';
    }

    private function xlsxCellToString(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null)    return '';
        if (is_bool($value))   return $value ? '1' : '0';
        if (is_int($value))    return (string) $value;
        if (is_float($value)) {
            if (PhpSpreadsheetDate::isDateTime($cell)) {
                return PhpSpreadsheetDate::excelToDateTimeObject($value)->format('d-m-Y');
            }
            return fmod($value, 1.0) === 0.0 ? sprintf('%.0f', $value) : (string) $value;
        }
        return trim((string) $value);
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
        return $s;
    }

    private function parseStatus(string $raw): bool
    {
        if ($raw === '') return true;
        return in_array(strtolower($raw), ['aktif', '1', 'true', 'yes', 'ya']);
    }
}
