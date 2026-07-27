<?php

namespace App\Domain\Master\Unified\Services;

use App\Domain\Finance\Invoice\Services\InvoiceGroupProcessor;
use App\Domain\Finance\Invoice\Services\ProcessGroupResult;
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
use Illuminate\Support\Facades\Log;
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
        private readonly InvestorService      $investorService,
        private readonly RestoService         $restoService,
        private readonly KlienArService       $klienArService,
        private readonly InvoiceGroupProcessor $invoiceGroupProcessor,
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
        $this->processMasterInvoiceSheet($spreadsheet, $batch, $errors);

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

        $headerRow = array_map(fn($c) => $this->normalizeHeaderName((string) $c), $rows[0] ?? []);
        if (in_array('nama_klien', $headerRow)) {
            $errors[] = ['sheet' => 'MASTER DATA', 'row' => 0, 'message' => 'Template lama masih memiliki kolom nama_klien. Download template terbaru.'];
            return;
        }

        // Header-based column lookup — toleran terhadap perubahan urutan kolom dan template lama/baru
        $headerIdxMap = array_flip($headerRow);
        $col = static fn(array $row, string $name): mixed =>
            $row[$headerIdxMap[$name] ?? -1] ?? '';

        $batch->update(['master_total' => count($rows)]);

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

        $invIns  = $invUpd  = $invFail  = 0;
        $resIns  = $resUpd  = $resFail  = 0;
        $kliIns  = $kliUpd  = $kliFail  = $kliSkip = 0;
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
                        'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,  'klien_skipped' => $kliSkip,
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
                $namaCabang    = trim((string) $col($row, 'nama_cabang'));
                $tipeKlien     = strtoupper(trim((string) $col($row, 'tipe_klien')));
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
                                if ($this->investorHasChanged($existing, $invData)) {
                                    $existing->updated_by = $actingUserId;
                                    $investor = $this->investorService->update($existing, InvestorDTO::fromRequest($invData));
                                    $invUpd++;
                                } else {
                                    $investor = $existing;
                                }
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
                        $existingResto = Resto::where('kode_resto', $kodeResto)->latest()->first();
                    } else {
                        $existingResto = Resto::where('nama_resto', $namaCabang)->latest()->first();
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
                                    if ($this->restoHasChanged($existingResto, $resData)) {
                                        $existingResto->updated_by = $actingUserId;
                                        $resto = $this->restoService->update($existingResto, RestoDTO::fromRequest($resData));
                                        $resUpd++;
                                    } else {
                                        $resto = $existingResto;
                                    }
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
                                    if ($this->klienArHasChanged($existingKlien, $kliData)) {
                                        $existingKlien->updated_by = $actingUserId;
                                        $this->klienArService->update($existingKlien, KlienArDTO::fromRequest($kliData));
                                        $kliUpd++;
                                    } else {
                                        $kliSkip++;
                                    }
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
            'klien_inserted'    => $kliIns,  'klien_updated'    => $kliUpd,  'klien_failed'    => $kliFail,  'klien_skipped' => $kliSkip,
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

        // Header-based column lookup — toleran terhadap template lama yang masih memiliki kolom nama_brand
        $headerRow    = array_map(fn($c) => $this->normalizeHeaderName((string) $c), $rows[0] ?? []);
        $headerIdxMap = array_flip($headerRow);
        $col = static fn(array $row, string $name): mixed =>
            $row[$headerIdxMap[$name] ?? -1] ?? '';

        $batch->update(['barang_total' => count($rows)]);

        $actingUserId = $batch->user_id;

        $brgIns = $brgUpd = $brgSkip = $brgFail = 0;
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
                if (str_starts_with($firstCell, '#')) continue;
                if (!$headerSkipped) { $headerSkipped = true; continue; }
                if (str_starts_with($firstCell, '[CONTOH]')) continue;
                if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

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
                    continue;
                }

                // kode_barang adalah identitas unik barang — nama_barang bisa sama untuk produk berbeda (varian/kategori berbeda)
                $existing = Barang::where('kode_barang', $rawKode)->first();

                try {
                    if ($existing) {
                        if ($this->barangHasChanged($existing, $data)) {
                            $existing->update([
                                'nama_barang' => $data['nama_barang'],
                                'spesifikasi' => $data['spesifikasi'],
                                'keterangan'  => $data['keterangan'],
                                'status'      => $data['status'] ?? true,
                                'updated_by'  => $actingUserId,
                            ]);
                            $brgUpd++;
                        } else {
                            $brgSkip++;
                        }
                    } else {
                        Barang::create([
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
    //  Sheet 3: MASTER INVOICE (Invoice B2B + B2C)
    // ──────────────────────────────────────────────────────────────

    private function processMasterInvoiceSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ImportMasterBatch $batch, array &$errors): void
    {
        // Support nama sheet lama (MASTER INVOICE) dan nama baru
        $sheetIndex = $this->findSheetIndex($spreadsheet, 'MASTER INVOICE')
            ?? $this->findSheetIndex($spreadsheet, 'Master Invoice');

        if ($sheetIndex === null) {
            return; // Sheet tidak ada → tidak dianggap error (file lama tetap aman)
        }

        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows  = $this->parseSheet($sheet, 'nama_klien', 14);

        if (empty($rows)) {
            $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => 0, 'message' => 'Header "nama_klien" tidak ditemukan di sheet MASTER INVOICE.'];
            return;
        }

        // Preload klien (nama + resto/outlet) dan barang map
        [$namaMap, $restoMap, $restoNameMap, $restoNameCount] = $this->buildKlienMapsForInvoice();
        $barangMap = $this->buildBarangMapForInvoice();

        // Preload acuan MASTER DATA per kode_resto — dipakai memvalidasi tiap baris
        // MASTER INVOICE sebelum di-resolve (lihat validateInvoiceRowAgainstMasterData()).
        $restoMasterMap = $this->buildRestoMasterMapForInvoice();

        // Preload EB locked
        $lockedEbMap = $this->invoiceGroupProcessor->buildLockedEbMap();

        // ── Group rows by (tipe_invoice + klien_ar_id + tanggal_invoice) ──
        // Klien di-resolve DI SINI (per baris, sebelum grouping) supaya B2C dari
        // outlet berbeda milik investor yang sama tidak ikut tergabung jadi 1 invoice.
        $groups      = [];   // key => ['tipe_invoice', 'klien', 'header', 'first_line', 'items']
        $lineNumber  = 0;
        $headerSkipped = false;
        $invFail     = 0;

        foreach ($rows as $row) {
            $lineNumber++;
            $firstCell = trim((string) ($row[0] ?? ''));
            $tipeCell  = trim((string) ($row[13] ?? ''));
            if (!$headerSkipped) { $headerSkipped = true; continue; }
            if (str_starts_with($firstCell, '#')) continue;
            if (str_starts_with($tipeCell, '[CONTOH]')) continue;
            if ($firstCell === '' && count(array_filter(array_map('strval', $row))) === 0) continue;

            $tipeInvoice = strtoupper(trim((string) ($row[13] ?? '')));
            $namaKlien   = $this->importValue($row[0] ?? '');
            $tanggal     = $this->importDate($row[1] ?? '');
            $kodeResto   = $this->importValue($row[6] ?? '');

            if (!$tipeInvoice || !$namaKlien || !$tanggal) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $lineNumber, 'message' => 'tipe_invoice, nama_klien, dan tanggal_invoice wajib diisi.'];
                $invFail++;
                continue;
            }

            if (!in_array($tipeInvoice, ['B2B', 'B2C'])) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $lineNumber, 'message' => "tipe_invoice '{$tipeInvoice}' tidak valid. Harus 'B2B' atau 'B2C'."];
                $invFail++;
                continue;
            }

            $masterValidationError = $this->validateInvoiceRowAgainstMasterData($tipeInvoice, $namaKlien, $kodeResto, $restoMasterMap);
            if ($masterValidationError) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $lineNumber, 'message' => $masterValidationError];
                $invFail++;
                continue;
            }

            [$klien, $klienError] = $this->resolveKlienForInvoiceRow(
                $tipeInvoice, $namaKlien, $kodeResto,
                $namaMap, $restoMap, $restoNameMap, $restoNameCount,
            );

            if (!$klien) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $lineNumber, 'message' => $klienError];
                $invFail++;
                continue;
            }

            $key = $tipeInvoice . '||' . $klien->id . '||' . $tanggal;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'tipe_invoice' => $tipeInvoice,
                    'klien'        => $klien,
                    'header'       => $row,
                    'first_line'   => $lineNumber,
                    'items'        => [],
                ];
            }

            // Tambah item dari baris ini
            $groups[$key]['items'][] = [
                'row'              => $lineNumber,
                'no_invoice_resto' => $this->importValue($row[5] ?? ''),
                'kode_resto'       => $kodeResto,
                'nama_resto'       => $this->importValue($row[7] ?? ''),
                'kode_barang'      => $this->importValue($row[8] ?? ''),
                'nama_barang'      => $this->importValue($row[9] ?? ''),
                'qty'              => $this->importNum($row[10] ?? ''),
                'satuan'           => $this->importValue($row[11] ?? ''),
                'harga_satuan'     => $this->importNum($row[12] ?? ''),
            ];
        }

        $totalGrup = count($groups);
        $batch->update(['invoice_total' => $totalGrup]);

        $invIns  = 0;
        $invUpd  = 0;
        $invSkip = 0;
        $processed   = 0;
        $newInvoices = [];

        foreach ($groups as $key => $group) {
            $processed++;
            $tipeInvoice = $group['tipe_invoice'];
            $headerRow   = $group['header'];
            $firstLine   = $group['first_line'];
            $klien       = $group['klien'];
            $namaKlien   = $this->importValue($headerRow[0] ?? '');
            $tanggal     = $this->importDate($headerRow[1] ?? '');

            // Validasi item
            $items     = [];
            $itemError = null;
            foreach ($group['items'] as $itemRaw) {
                if (!$itemRaw['nama_barang']) {
                    $itemError = "Baris {$itemRaw['row']}: nama_barang wajib diisi.";
                    break;
                }
                if ($itemRaw['qty'] <= 0) {
                    $itemError = "Baris {$itemRaw['row']}: qty harus lebih dari 0.";
                    break;
                }

                $barangId = null;
                if ($itemRaw['kode_barang']) {
                    $barangId = $barangMap[strtoupper($itemRaw['kode_barang'])] ?? null;
                }

                $items[] = [
                    'barang_id'        => $barangId,
                    'kode_barang'      => $itemRaw['kode_barang'],
                    'nama_barang'      => $itemRaw['nama_barang'],
                    'qty'              => $itemRaw['qty'],
                    'satuan'           => $itemRaw['satuan'],
                    'harga_satuan'     => $itemRaw['harga_satuan'],
                    'no_invoice_resto' => $itemRaw['no_invoice_resto'],
                    'kode_resto'       => $itemRaw['kode_resto'],
                    'nama_resto'       => $itemRaw['nama_resto'],
                ];
            }

            if ($itemError) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $firstLine, 'message' => $itemError];
                $invFail++;
                continue;
            }

            if (empty($items)) {
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $firstLine, 'message' => "Invoice '{$namaKlien}' ({$tanggal}) tidak memiliki item."];
                $invFail++;
                continue;
            }

            $headerData = [
                'klien_ar_id'        => $klien->id,
                'tanggal_invoice'    => $tanggal,
                'tanggal_jatuh_tempo'=> $this->importDate($headerRow[2] ?? ''),
                'no_surat_jalan'     => $this->importValue($headerRow[3] ?? ''),
                'keterangan'         => $this->importValue($headerRow[4] ?? ''),
            ];

            try {
                $result = $this->invoiceGroupProcessor->processGroup(
                    $tipeInvoice,
                    $headerData,
                    $items,
                    $lockedEbMap,
                );

                match (true) {
                    $result->isInserted() => (function () use (&$invIns, &$newInvoices, $result) {
                        $invIns++;
                        $newInvoices[] = $result->invoice;
                    })(),
                    $result->isUpdated()  => $invUpd++,
                    $result->isSkipped()  => (function () use (&$invSkip, &$errors, $firstLine, $result) {
                        $invSkip++;
                        $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $firstLine, 'message' => 'Dilewati: ' . $result->error];
                    })(),
                    default               => (function () use (&$invFail, &$errors, $firstLine, $result) {
                        $invFail++;
                        $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $firstLine, 'message' => $result->error];
                    })(),
                };
            } catch (\Throwable $e) {
                $invFail++;
                $errors[] = ['sheet' => 'MASTER INVOICE', 'row' => $firstLine, 'message' => 'Error tak terduga: ' . $e->getMessage()];
                Log::error('MasterImportService: processMasterInvoiceSheet error', ['key' => $key, 'error' => $e->getMessage()]);
            }

            // Update progress setiap CHUNK grup
            if ($processed % self::CHUNK === 0) {
                $batch->update([
                    'invoice_processed' => $processed,
                    'invoice_inserted'  => $invIns,
                    'invoice_updated'   => $invUpd,
                    'invoice_skipped'   => $invSkip,
                    'invoice_failed'    => $invFail,
                ]);
            }
        }

        // Propagasi carryover untuk invoice baru setelah semua grup selesai
        if (!empty($newInvoices)) {
            $this->invoiceGroupProcessor->propagateCarryoverForNew($newInvoices);
        }

        $batch->update([
            'invoice_processed' => $totalGrup,
            'invoice_inserted'  => $invIns,
            'invoice_updated'   => $invUpd,
            'invoice_skipped'   => $invSkip,
            'invoice_failed'    => $invFail,
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
                if ($this->normalizeHeaderName($firstCell) === strtolower($headerFirstCol)) {
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
     * NIK dikenali sebagai token 6-30 digit angka murni (NIK Indonesia = 16 digit; rentang
     * dilebarkan sedikit untuk toleransi data lama). Kalau tidak ada token NIK yang cocok,
     * seluruh identifier dianggap nama saja (perilaku lama tidak berubah).
     *
     * @return array{nama: ?string, nik: ?string}
     */
    private function parsePicIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return ['nama' => null, 'nik' => null];
        }

        if (preg_match('/^\d{6,30}$/', $identifier)) {
            return ['nama' => null, 'nik' => $identifier];
        }

        if (preg_match('/^(.+?)\s*\(\s*(\d{6,30})\s*\)$/', $identifier, $m)) {
            return ['nama' => trim($m[1]) !== '' ? trim($m[1]) : null, 'nik' => $m[2]];
        }

        if (preg_match('/^(.+?)\s*[\/\-]\s*(\d{6,30})$/', $identifier, $m)) {
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
            return $karyawanNikMap[$parsed['nik']] ?? null;
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

    private function buildSummaryMessage(ImportMasterBatch $batch): string
    {
        $parts = [];
        if ($batch->master_total > 0) {
            $parts[] = sprintf(
                'MASTER DATA: Investor +%d ~%d ✗%d | Resto +%d ~%d ✗%d | Client +%d ~%d ⊘%d ✗%d',
                $batch->investor_inserted, $batch->investor_updated, $batch->investor_failed,
                $batch->resto_inserted,    $batch->resto_updated,    $batch->resto_failed,
                $batch->klien_inserted,    $batch->klien_updated,    $batch->klien_skipped, $batch->klien_failed,
            );
        }
        if ($batch->barang_total > 0) {
            $parts[] = sprintf(
                'MASTER BARANG: Barang +%d ~%d ⊘%d ✗%d',
                $batch->barang_inserted, $batch->barang_updated, $batch->barang_skipped, $batch->barang_failed,
            );
        }
        if ($batch->invoice_total > 0) {
            $parts[] = sprintf(
                'MASTER INVOICE: Invoice +%d ~%d ⊘%d ✗%d',
                $batch->invoice_inserted, $batch->invoice_updated,
                $batch->invoice_skipped,  $batch->invoice_failed,
            );
        }
        return implode(' | ', $parts) ?: 'Import selesai.';
    }

    /**
     * @return array{0: array<string, KlienAr>, 1: array<string, KlienAr>, 2: array<string, KlienAr>, 3: array<string, int>}
     *   [0] namaMap        lower(nama_klien) => KlienAr AKTIF (semua tipe, first match) — dipakai B2B.
     *   [1] restoMap       upper(kode_resto) => KlienAr tipe RESTO AKTIF — jalur utama B2C.
     *   [2] restoNameMap   lower(nama_klien) => KlienAr tipe RESTO AKTIF — fallback B2C saat kode_resto kosong.
     *   [3] restoNameCount lower(nama_klien) => jumlah KlienAr tipe RESTO AKTIF dengan nama tsb (guard ambiguitas).
     *
     * Hanya KlienAr berstatus aktif yang disertakan — supaya Client AR yang sudah
     * dinonaktifkan (mis. karena outlet-nya beralih segmen PT/RESTO) tidak lagi
     * dipakai untuk resolusi invoice baru.
     */
    private function buildKlienMapsForInvoice(): array
    {
        $namaMap        = [];
        $restoMap       = [];
        $restoNameMap   = [];
        $restoNameCount = [];

        $klienList = KlienAr::with('resto:id,kode_resto')
            ->where('status', true)
            ->get(['id', 'nama_klien', 'tipe_klien', 'resto_id', 'perusahaan_id', 'karyawan_ar_id']);

        foreach ($klienList as $klien) {
            if ($klien->nama_klien === null) {
                continue;
            }
            $namaKey = strtolower($klien->nama_klien);

            if (!isset($namaMap[$namaKey])) {
                $namaMap[$namaKey] = $klien;
            }

            if ($klien->tipe_klien === 'RESTO') {
                $restoNameCount[$namaKey] = ($restoNameCount[$namaKey] ?? 0) + 1;
                if (!isset($restoNameMap[$namaKey])) {
                    $restoNameMap[$namaKey] = $klien;
                }

                $kodeResto = $klien->resto?->kode_resto;
                if ($kodeResto !== null && $kodeResto !== '') {
                    $restoKey = strtoupper($kodeResto);
                    if (!isset($restoMap[$restoKey])) {
                        $restoMap[$restoKey] = $klien;
                    }
                }
            }
        }

        return [$namaMap, $restoMap, $restoNameMap, $restoNameCount];
    }

    /**
     * Bangun peta acuan MASTER DATA per kode_resto — dipakai untuk memvalidasi baris
     * MASTER INVOICE (lihat validateInvoiceRowAgainstMasterData()) sebelum di-resolve.
     *
     * Untuk tiap outlet (kode_resto), tentukan segmen yang SEDANG AKTIF saat ini:
     *   - RESTO, jika outlet itu punya KlienAr tipe RESTO aktif (resto_id).
     *   - PT,    jika tidak, tapi entitas (perusahaan_id) outlet itu punya KlienAr tipe PT aktif.
     * Outlet tanpa keduanya (belum onboarding AR / gagal saat MASTER DATA) tidak masuk peta,
     * sehingga baris invoice untuk kode_resto tsb akan gagal validasi dengan pesan jelas.
     *
     * @return array<string, array{tipe_klien: string, nama_klien: string, klien_id: int}>
     *   upper(kode_resto) => info Client AR aktif yang berlaku untuk outlet tsb.
     */
    private function buildRestoMasterMapForInvoice(): array
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
            KlienAr::where('tipe_klien', 'RESTO')
                ->where('status', true)
                ->whereIn('resto_id', $restos->pluck('id'))
                ->get(['id', 'resto_id', 'nama_klien']) as $klien
        ) {
            if (!isset($restoKlienByRestoId[$klien->resto_id])) {
                $restoKlienByRestoId[$klien->resto_id] = $klien;
            }
        }

        $ptKlienByPerusahaanId = [];
        foreach (
            KlienAr::where('tipe_klien', 'PT')
                ->where('status', true)
                ->whereIn('perusahaan_id', $restos->pluck('perusahaan_id')->filter()->unique())
                ->get(['id', 'perusahaan_id', 'nama_klien']) as $klien
        ) {
            if (!isset($ptKlienByPerusahaanId[$klien->perusahaan_id])) {
                $ptKlienByPerusahaanId[$klien->perusahaan_id] = $klien;
            }
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

    /**
     * Validasi 1 baris MASTER INVOICE terhadap MASTER DATA (via $restoMasterMap).
     * kode_resto adalah acuan utama — tipe_invoice & nama_klien pada baris invoice
     * wajib konsisten dengan segmen yang tercatat di MASTER DATA untuk outlet tsb.
     *
     * @param array<string, array{tipe_klien: string, nama_klien: string, klien_id: int}> $restoMasterMap
     * @return string|null pesan error, atau null jika valid.
     */
    private function validateInvoiceRowAgainstMasterData(
        string $tipeInvoice,
        string $namaKlien,
        ?string $kodeResto,
        array $restoMasterMap,
    ): ?string {
        if (!$kodeResto) {
            return 'kode_resto wajib diisi.';
        }

        $entry = $restoMasterMap[strtoupper($kodeResto)] ?? null;
        if (!$entry) {
            return "kode_resto '{$kodeResto}' tidak ditemukan di MASTER DATA atau belum memiliki Client AR aktif.";
        }

        $expectedTipeInvoice = $entry['tipe_klien'] === 'PT' ? 'B2B' : 'B2C';
        if ($tipeInvoice !== $expectedTipeInvoice) {
            return "kode_resto '{$kodeResto}' terdaftar sebagai {$entry['tipe_klien']} di MASTER DATA — tipe_invoice seharusnya '{$expectedTipeInvoice}', bukan '{$tipeInvoice}'.";
        }

        if (strtolower($namaKlien) !== strtolower($entry['nama_klien'])) {
            return "nama_klien '{$namaKlien}' tidak sesuai MASTER DATA untuk kode_resto '{$kodeResto}' (seharusnya '{$entry['nama_klien']}').";
        }

        return null;
    }

    /**
     * Resolve KlienAr untuk satu baris import MASTER INVOICE.
     *
     * B2B selalu resolve via nama_klien (perilaku konsolidasi multi-outlet tidak berubah).
     * B2C dengan kode_resto terisi resolve via outlet — tidak fallback ke nama, supaya
     * kode_resto yang salah ketik tidak diam-diam nyasar ke klien/outlet lain.
     * B2C dengan kode_resto kosong resolve via nama HANYA jika investor itu memiliki
     * tepat satu outlet; jika ambigu (>1 outlet), baris wajib diisi kode_resto.
     *
     * @return array{0: ?KlienAr, 1: ?string} [klien, pesan error]
     */
    private function resolveKlienForInvoiceRow(
        string $tipeInvoice,
        string $namaKlien,
        ?string $kodeResto,
        array $namaMap,
        array $restoMap,
        array $restoNameMap,
        array $restoNameCount,
    ): array {
        $namaKey = strtolower($namaKlien);

        if ($tipeInvoice === 'B2B') {
            $klien = $namaMap[$namaKey] ?? null;
            return $klien ? [$klien, null] : [null, "Klien '{$namaKlien}' tidak ditemukan di sistem."];
        }

        // B2C
        if ($kodeResto) {
            $klien = $restoMap[strtoupper($kodeResto)] ?? null;
            if (!$klien) {
                return [null, "kode_resto '{$kodeResto}' tidak ditemukan atau belum terhubung ke Client AR tipe RESTO."];
            }
            return [$klien, null];
        }

        $count = $restoNameCount[$namaKey] ?? 0;
        if ($count === 0) {
            return [null, "Klien '{$namaKlien}' tidak ditemukan di sistem."];
        }
        if ($count > 1) {
            return [null, "Klien '{$namaKlien}' memiliki {$count} outlet berbeda. Isi kolom kode_resto pada baris ini untuk menentukan outlet yang dituju."];
        }

        return [$restoNameMap[$namaKey], null];
    }

    /** @return array<string, int> upper(kode_barang) => id */
    private function buildBarangMapForInvoice(): array
    {
        $map = [];
        foreach (Barang::all(['id', 'kode_barang']) as $barang) {
            if ($barang->kode_barang !== null && !isset($map[strtoupper($barang->kode_barang)])) {
                $map[strtoupper($barang->kode_barang)] = $barang->id;
            }
        }
        return $map;
    }

    private function importNum(mixed $val): float
    {
        $s = trim((string) $val);
        $s = str_replace(['.', ','], ['', '.'], $s);
        return is_numeric($s) ? (float) $s : 0.0;
    }

    private function xlsxCellToString(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null)    return '';
        if (is_bool($value))   return $value ? '1' : '0';

        if ((is_int($value) || is_float($value)) && PhpSpreadsheetDate::isDateTime($cell)) {
            return PhpSpreadsheetDate::excelToDateTimeObject($value)->format('Y-m-d');
        }

        if (is_int($value))    return (string) $value;
        if (is_float($value)) {
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

    private function investorHasChanged(Investor $existing, array $import): bool
    {
        foreach (['nama_investor', 'ktp', 'npwp', 'no_hp', 'pengelola', 'no_hp_pengelola', 'kode_cabang', 'id_cabang'] as $f) {
            if ($this->normalizeStr($existing->{$f}) !== $this->normalizeStr($import[$f])) {
                return true;
            }
        }
        return (bool) $existing->status !== (bool) ($import['status'] ?? true);
    }

    private function restoHasChanged(Resto $existing, array $import): bool
    {
        foreach (['nama_resto', 'supervisor', 'no_hp_supervisor', 'stokis', 'area', 'kota', 'alamat', 'no_telp', 'keterangan'] as $f) {
            if ($this->normalizeStr($existing->{$f}) !== $this->normalizeStr($import[$f])) {
                return true;
            }
        }
        foreach (['perusahaan_id', 'brand_id', 'investor_id', 'karyawan_id'] as $f) {
            if ($this->normalizeId($existing->{$f}) !== $this->normalizeId($import[$f])) {
                return true;
            }
        }
        // tgl_aktif: bandingkan sebagai Y-m-d
        $existingDate = $existing->tgl_aktif
            ? (is_string($existing->tgl_aktif) ? substr($existing->tgl_aktif, 0, 10) : $existing->tgl_aktif->format('Y-m-d'))
            : null;
        if ($existingDate !== $import['tgl_aktif']) {
            return true;
        }
        return (bool) $existing->status !== (bool) ($import['status'] ?? true);
    }

    private function barangHasChanged(Barang $existing, array $import): bool
    {
        foreach (['nama_barang', 'spesifikasi', 'keterangan'] as $f) {
            if ($this->normalizeStr($existing->{$f}) !== $this->normalizeStr($import[$f])) {
                return true;
            }
        }
        return (bool) $existing->status !== (bool) ($import['status'] ?? true);
    }

    private function klienArHasChanged(KlienAr $existing, array $import): bool
    {
        foreach (['nama_klien', 'tipe_klien', 'no_npwp', 'no_wa'] as $f) {
            if ($this->normalizeStr($existing->{$f}) !== $this->normalizeStr($import[$f])) {
                return true;
            }
        }
        foreach (['perusahaan_id', 'karyawan_ar_id', 'resto_id'] as $f) {
            if ($this->normalizeId($existing->{$f}) !== $this->normalizeId($import[$f])) {
                return true;
            }
        }
        return (bool) $existing->status !== (bool) ($import['status'] ?? true);
    }
}
