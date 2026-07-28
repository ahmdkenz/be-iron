<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

interface BankParserInterface
{
    /**
     * Parse rows secara chunk (bukan toArray() satu sheet penuh) agar file besar
     * (rekening koran 1 tahun) tidak membengkakkan memori. $onChunk dipanggil
     * berkali-kali selama proses parsing dengan:
     *   - array $rows: baris valid ternormalisasi, maksimal $chunkSize per panggilan
     *       ['tanggal' => 'YYYY-MM-DD', 'waktu_transaksi' => ?string, 'keterangan' => string,
     *        'no_referensi' => ?string, 'debit' => float, 'kredit' => float, 'saldo' => float]
     *   - array $errors: ['row' => int, 'message' => string] untuk baris yang tidak
     *       kosong tapi gagal divalidasi (tanggal tidak valid, debit & kredit sama-sama
     *       terisi, atau nominal tidak valid)
     *   - int $scanned: jumlah baris file yang discan pada chunk ini (dipakai untuk
     *       progress processed_rows, termasuk baris kosong yang dilewati diam-diam)
     *
     * @throws \RuntimeException jika baris header tidak dapat dideteksi sama sekali
     */
    public function parseInChunks(string $filePath, callable $onChunk, int $chunkSize = 750): void;
}
