<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class AbstractBankParser implements BankParserInterface
{
    // ── XLSX chunked reader ──────────────────────────────────────────
    //
    // Sengaja TIDAK memakai toArray() satu sheet penuh (lihat versi lama parser
    // ini) — untuk rekening koran 1 tahun dengan puluhan ribu baris, toArray()
    // membangun satu array PHP raksasa DI ATAS cell collection PhpSpreadsheet
    // yang sudah ada di memori (dobel). Di sini sheet dimuat sekali dengan
    // setReadDataOnly(true) (skip style), lalu dibaca per rentang $chunkSize
    // baris via rangeToArray(), dan baris yang sudah diproses langsung dilepas
    // dari cell collection lewat removeRow() supaya memori tidak menumpuk
    // seiring baris yang dibaca.

    /**
     * @param callable $onPreview  fn(array $previewRows): array{headerRowIdx:int, colMap:array}
     *                             — dipanggil sekali dengan baris-baris awal sheet (maks 50)
     *                             untuk deteksi header. Harus throw RuntimeException jika
     *                             header tidak ditemukan.
     * @param callable $onChunk    fn(array $rangeRows, int $startRow, array $colMap): void
     *                             — dipanggil per chunk dengan baris mentah (0-indexed kolom)
     *                             beserta nomor baris awal (1-indexed, sesuai file asli).
     */
    protected function chunkedXlsxRows(string $filePath, int $chunkSize, callable $onPreview, callable $onChunk): void
    {
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->runChunkedRows($sheet, $chunkSize, $onPreview, $onChunk);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    private function runChunkedRows(Worksheet $sheet, int $chunkSize, callable $onPreview, callable $onChunk): void
    {
        $highestRow    = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        $previewEnd = min(50, $highestRow);
        $preview    = $sheet->rangeToArray("A1:{$highestColumn}{$previewEnd}", null, true, false, false);

        $detected = $onPreview($preview);
        $dataStart = $detected['headerRowIdx'] + 2; // rowIdx 0-based -> baris data pertama (1-based, lewati header)
        $colMap    = $detected['colMap'];

        // removeRow() (dipakai untuk melepas memori per chunk) menggeser semua baris
        // di bawahnya naik seperti "Delete Row" — bukan sekadar mengosongkan nilainya.
        // Karena itu chunk berikutnya SELALU dibaca ulang dari posisi tetap $dataStart
        // (bukan $start += $chunkSize), sementara nomor baris asli (untuk pesan error)
        // dilacak terpisah lewat $originalRow yang berjalan maju sesuai baris yang
        // benar-benar sudah diproses.
        $originalRow = $dataStart;

        while (($currentHighest = $sheet->getHighestRow()) >= $dataStart) {
            $end   = min($dataStart + $chunkSize - 1, $currentHighest);
            $count = $end - $dataStart + 1;
            $rows  = $sheet->rangeToArray("A{$dataStart}:{$highestColumn}{$end}", null, true, false, false);

            $onChunk($rows, $originalRow, $colMap);

            $sheet->removeRow($dataStart, $count);
            $originalRow += $count;
        }
    }

    // ── Header detection (scoring) ────────────────────────────────────

    /**
     * Scan semua baris, beri skor berdasarkan berapa banyak canonical field
     * yang ditemukan di baris tersebut. Baris dengan skor tertinggi (min $minScore)
     * dipilih sebagai header row.
     *
     * Return: ['rowIdx' => int, 'colMap' => ['tanggal' => colIdx, ...]]
     * rowIdx = -1 jika tidak ditemukan.
     */
    protected function detectHeaderRow(array $data, int $minScore = 3): array
    {
        $keywords = BankStatementSchema::FIELD_KEYWORDS;

        $bestScore  = 0;
        $bestRowIdx = -1;
        $bestColMap = [];

        foreach ($data as $rowIdx => $row) {
            $colMap = [];
            foreach ($row as $colIdx => $cell) {
                $cell = strtolower(trim((string) $cell));
                if ($cell === '') continue;

                foreach ($keywords as $field => $variants) {
                    if (isset($colMap[$field])) continue;
                    foreach ($variants as $kw) {
                        if (str_contains($cell, $kw)) {
                            $colMap[$field] = $colIdx;
                            break;
                        }
                    }
                }
            }

            $score = count($colMap);
            if ($score > $bestScore) {
                $bestScore  = $score;
                $bestRowIdx = $rowIdx;
                $bestColMap = $colMap;
            }
        }

        if ($bestScore < $minScore) {
            return ['rowIdx' => -1, 'colMap' => []];
        }

        return ['rowIdx' => $bestRowIdx, 'colMap' => $bestColMap];
    }

    // ── Ekstraksi & validasi baris (dipakai oleh parser berbasis colMap) ──

    /**
     * Ekstrak satu baris mentah (array 0-indexed kolom) menjadi baris ternormalisasi,
     * atau tandai sebagai error jika baris tidak kosong tapi gagal divalidasi.
     * Baris yang benar-benar kosong (semua kolom relevan kosong) dilewati diam-diam
     * (row=null, error=null) — bukan error.
     *
     * @return array{row: ?array, error: ?array}
     */
    protected function extractOrValidateRow(int $rowNumber, array $row, array $colMap): array
    {
        $tanggalRaw = trim((string) ($row[$colMap['tanggal']    ?? -1] ?? ''));
        $ketRaw     = trim((string) ($row[$colMap['keterangan'] ?? -1] ?? ''));
        $debitRaw   = trim((string) ($row[$colMap['debit']      ?? -1] ?? ''));
        $kreditRaw  = trim((string) ($row[$colMap['kredit']     ?? -1] ?? ''));
        $saldoRaw   = trim((string) ($row[$colMap['saldo']      ?? -1] ?? ''));

        if ($tanggalRaw === '' && $ketRaw === '' && $debitRaw === '' && $kreditRaw === '' && $saldoRaw === '') {
            return ['row' => null, 'error' => null];
        }

        if (!$this->isValidNominalRaw($debitRaw) || !$this->isValidNominalRaw($kreditRaw) || !$this->isValidNominalRaw($saldoRaw)) {
            return ['row' => null, 'error' => ['row' => $rowNumber, 'message' => 'Nominal Debit/Kredit/Saldo tidak valid.']];
        }

        $debit  = $this->parseAngka($debitRaw !== '' ? $debitRaw : '0');
        $kredit = $this->parseAngka($kreditRaw !== '' ? $kreditRaw : '0');

        if ($debit > 0 && $kredit > 0) {
            return ['row' => null, 'error' => ['row' => $rowNumber, 'message' => 'Debit dan Kredit tidak boleh sama-sama terisi.']];
        }

        if ($tanggalRaw === '') {
            // Tanpa nominal maupun tanggal: kemungkinan baris catatan/footer → lewati diam-diam.
            if ($debit == 0 && $kredit == 0) {
                return ['row' => null, 'error' => null];
            }
            return ['row' => null, 'error' => ['row' => $rowNumber, 'message' => 'Tanggal kosong padahal ada nominal transaksi.']];
        }

        $tanggal = $this->parseTanggal($tanggalRaw);
        if ($tanggal === null) {
            return ['row' => null, 'error' => ['row' => $rowNumber, 'message' => "Tanggal tidak valid: \"{$tanggalRaw}\"."]];
        }

        if ($debit == 0 && $kredit == 0) {
            return ['row' => null, 'error' => null];
        }

        return [
            'row' => [
                'tanggal'         => $tanggal,
                'waktu_transaksi' => isset($colMap['waktu']) ? $this->parseWaktu(trim((string) ($row[$colMap['waktu']] ?? ''))) : null,
                'keterangan'      => $ketRaw,
                'no_referensi'    => isset($colMap['no_referensi']) ? (trim((string) ($row[$colMap['no_referensi']] ?? '')) ?: null) : null,
                'debit'           => $debit,
                'kredit'          => $kredit,
                'saldo'           => $this->parseAngka($saldoRaw !== '' ? $saldoRaw : '0'),
            ],
            'error' => null,
        ];
    }

    protected function isValidNominalRaw(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || is_numeric($raw)) return true;

        // Boleh mengandung simbol "Rp" dan spasi selain digit/pemisah ribuan-desimal/minus.
        $stripped = preg_replace('/(rp\.?|\s)/i', '', $raw);

        return (bool) preg_match('/^-?[\d.,]+$/', (string) $stripped);
    }

    // ── Shared helpers ────────────────────────────────────────────────

    protected function parseTanggal(string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Coba format teks DULU — termasuk DDMMYYYY (format template upload)
        // Excel serial realistis (2000–2050) berkisar 36.000–73.000 (5 digit),
        // tidak akan menabrak DDMMYYYY yang 8 digit.
        //
        // hasFormat() dipakai sebagai guard SEBELUM createFromFormat() karena
        // createFromFormat() menerima input yang jumlah digitnya tidak sesuai
        // spesifier (mis. 'd-m-Y' menerima tahun 2 digit "26" apa adanya sebagai
        // tahun 26 Masehi, bukan 2026, alih-alih melempar exception).
        foreach (['dmY', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y', 'd M Y', 'd-M-Y', 'd F Y', 'Y/m/d'] as $fmt) {
            if (!Carbon::hasFormat($raw, $fmt)) {
                continue;
            }
            try {
                return Carbon::createFromFormat($fmt, $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        // Excel serial number (float dari sel bertipe Date di XLSX)
        // Nilai valid: 1 (1 Jan 1900) s/d ~2.958.465 (31 Des 9999)
        if (is_numeric($raw) && (float) $raw > 1 && (float) $raw < 2_958_466) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Exception) {}
        }

        return null;
    }

    protected function parseWaktu(string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        foreach (['H:i:s', 'H:i', 'h:i:s A', 'h:i A'] as $fmt) {
            try {
                return \Carbon\Carbon::createFromFormat($fmt, $raw)->format('H:i:s');
            } catch (\Exception) {}
        }

        // Format HH:MM:SS tanpa separator
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $raw, $m)) {
            return "{$m[1]}:{$m[2]}:{$m[3]}";
        }

        return null;
    }

    protected function parseAngka(string $raw): float
    {
        $raw = trim((string) $raw);
        if ($raw === '') return 0.0;

        // Nilai numerik murni dari PhpSpreadsheet (formatData=false) atau CSV angka biasa.
        // Contoh: "1413590", "1413590.5", "-500.25" — langsung konversi.
        if (is_numeric($raw)) return (float) $raw;

        // Hapus semua karakter selain digit, titik, koma, dan tanda minus
        $clean = preg_replace('/[^\d,\.\-]/', '', $raw);
        if ($clean === '' || $clean === '-') return 0.0;

        // Format Indonesia: "1.234.567,89" — koma sebagai pemisah desimal
        // Ciri: diakhiri koma lalu 1-2 digit
        if (preg_match('/,\d{1,2}$/', $clean)) {
            $clean = str_replace('.', '', $clean);
            return (float) str_replace(',', '.', $clean);
        }

        // Format US/Internasional: "1,234,567.89" — titik sebagai pemisah desimal
        // Ciri: diakhiri titik lalu 1-2 digit DAN ada koma di bagian ribuan
        if (preg_match('/\.\d{1,2}$/', $clean) && str_contains($clean, ',')) {
            return (float) str_replace(',', '', $clean);
        }

        // Tanpa pemisah desimal eksplisit — hapus semua pemisah ribuan
        // Contoh: "1.413.590" (ID), "1,413,590" (US), "1413590"
        return (float) str_replace(['.', ','], '', $clean);
    }
}
