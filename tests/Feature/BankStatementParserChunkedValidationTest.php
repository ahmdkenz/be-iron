<?php

namespace Tests\Feature;

use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankColumnMapping;
use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\GenericBankParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Parser rekening koran sekarang membaca file secara chunk (bukan toArray() satu
 * sheet penuh) — lihat AbstractBankParser::chunkedXlsxRows(). Test ini murni file
 * I/O + logika ekstraksi/validasi, tanpa DB, memakai chunkSize kecil (2) supaya
 * batas antar-chunk benar-benar teruji (baris tidak boleh hilang/dobel di
 * perbatasan chunk).
 */
class BankStatementParserChunkedValidationTest extends TestCase
{
    private ?string $tmpFile = null;

    protected function tearDown(): void
    {
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    private function buildXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'TEMPLATE REKENING KORAN'); // baris judul, harus dilewati saat deteksi header
        $sheet->fromArray(
            ['Tanggal', 'Keterangan', 'No Referensi', 'Debit', 'Kredit', 'Saldo'],
            null,
            'A2'
        );

        $rowIdx = 3;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, "A{$rowIdx}");
            $rowIdx++;
        }

        $this->tmpFile = tempnam(sys_get_temp_dir(), 'bs_parser_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($this->tmpFile);

        return $this->tmpFile;
    }

    public function test_parse_in_chunks_extracts_valid_rows_skips_blank_and_reports_errors(): void
    {
        $file = $this->buildXlsx([
            ['01/01/2026', 'Transfer masuk', 'TRF001', '', '500000', '500000'],   // baris data 1 (row 3) — valid
            ['', '', '', '', '', ''],                                             // baris data 2 (row 4) — kosong, lewati diam-diam
            ['tanggal-ngawur', 'Test tanggal salah', '', '', '100000', '600000'],  // baris data 3 (row 5) — tanggal invalid -> error
            ['02/01/2026', 'Aneh dua-duanya terisi', '', '100000', '200000', '700000'], // baris data 4 (row 6) — debit & kredit terisi -> error
            ['03/01/2026', 'Transfer keluar', 'TRF002', '50000', '', '550000'],   // baris data 5 (row 7) — valid
            ['04/01/2026', 'Nominal rusak', '', 'abcxyz', '', '700000'],          // baris data 6 (row 8) — nominal tidak valid -> error
        ]);

        $parser = new GenericBankParser(BankColumnMapping::get('GENERAL'));

        $allRows    = [];
        $allErrors  = [];
        $totalScan  = 0;

        $parser->parseInChunks($file, function (array $rows, array $errors, int $scanned) use (&$allRows, &$allErrors, &$totalScan) {
            array_push($allRows, ...$rows);
            array_push($allErrors, ...$errors);
            $totalScan += $scanned;
        }, chunkSize: 2);

        $this->assertSame(6, $totalScan, 'Total baris yang discan harus sama dengan jumlah baris data di file (termasuk yang kosong/error).');

        $this->assertCount(2, $allRows);
        $this->assertSame('2026-01-01', $allRows[0]['tanggal']);
        $this->assertSame('TRF001', $allRows[0]['no_referensi']);
        $this->assertSame(0.0, $allRows[0]['debit']);
        $this->assertSame(500000.0, $allRows[0]['kredit']);
        $this->assertSame('2026-01-03', $allRows[1]['tanggal']);
        $this->assertSame('TRF002', $allRows[1]['no_referensi']);
        $this->assertSame(50000.0, $allRows[1]['debit']);
        $this->assertSame(0.0, $allRows[1]['kredit']);

        $this->assertCount(3, $allErrors);

        $errorsByRow = collect($allErrors)->keyBy('row');
        $this->assertStringContainsString('Tanggal tidak valid', $errorsByRow[5]['message']);
        $this->assertStringContainsString('Debit dan Kredit tidak boleh sama-sama terisi', $errorsByRow[6]['message']);
        $this->assertStringContainsString('Nominal', $errorsByRow[8]['message']);
    }

    public function test_parse_in_chunks_treats_two_digit_year_with_dash_as_year_2000_plus(): void
    {
        $file = $this->buildXlsx([
            ['10-12-26', 'Transfer masuk dash 2 digit tahun', 'TRF100', '', '500000', '500000'],
        ]);

        $parser = new GenericBankParser(BankColumnMapping::get('GENERAL'));

        $allRows   = [];
        $allErrors = [];
        $parser->parseInChunks($file, function (array $rows, array $errors, int $scanned) use (&$allRows, &$allErrors) {
            array_push($allRows, ...$rows);
            array_push($allErrors, ...$errors);
        }, chunkSize: 500);

        $this->assertSame([], $allErrors, 'Tidak boleh ada error untuk tanggal dd-mm-yy yang valid.');
        $this->assertCount(1, $allRows);
        $this->assertSame('2026-12-10', $allRows[0]['tanggal'], 'Tahun 2 digit "26" harus jadi 2026, bukan 0026.');
    }

    public function test_parse_in_chunks_supports_all_documented_date_formats_without_year_corruption(): void
    {
        // [raw tanggal di kolom sumber, tanggal ISO yang diharapkan]
        $cases = [
            ['10122026', '2026-12-10'],           // dmY
            ['10/12/2026', '2026-12-10'],          // d/m/Y
            ['10-12-2026', '2026-12-10'],          // d-m-Y (4 digit — regresi guard)
            ['2026-12-10', '2026-12-10'],          // Y-m-d
            ['10/12/26', '2026-12-10'],            // d/m/y
            ['10-12-26', '2026-12-10'],            // d-m-y (BARU — kasus bug utama)
            ['10 Dec 2026', '2026-12-10'],         // d M Y
            ['10-Dec-2026', '2026-12-10'],         // d-M-Y
            ['10 December 2026', '2026-12-10'],    // d F Y
            ['2026/12/10', '2026-12-10'],          // Y/m/d
        ];

        $rows = [];
        foreach ($cases as $i => [$raw, $expected]) {
            $rows[] = [$raw, "Baris {$i}", "REF{$i}", '', '100000', '100000'];
        }

        $file   = $this->buildXlsx($rows);
        $parser = new GenericBankParser(BankColumnMapping::get('GENERAL'));

        $allRows   = [];
        $allErrors = [];
        $parser->parseInChunks($file, function (array $r, array $e, int $s) use (&$allRows, &$allErrors) {
            array_push($allRows, ...$r);
            array_push($allErrors, ...$e);
        }, chunkSize: 3); // chunkSize kecil supaya lintas-batas chunk ikut teruji

        $this->assertSame([], $allErrors);
        $this->assertCount(count($cases), $allRows);

        foreach ($cases as $i => [$raw, $expected]) {
            $this->assertSame($expected, $allRows[$i]['tanggal'], "Format gagal untuk raw=\"{$raw}\"");
        }
    }

    public function test_parse_in_chunks_throws_when_header_not_detected(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Bukan file rekening koran sama sekali');
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'bs_parser_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($this->tmpFile);

        $parser = new GenericBankParser(BankColumnMapping::get('GENERAL'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/header tidak ditemukan/');

        $parser->parseInChunks($this->tmpFile, function () {}, chunkSize: 500);
    }
}
