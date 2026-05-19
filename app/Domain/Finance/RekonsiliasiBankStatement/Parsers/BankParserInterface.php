<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

interface BankParserInterface
{
    /**
     * Parse bank statement file and return normalized rows.
     *
     * Each row:
     * [
     *   'tanggal'    => 'YYYY-MM-DD',
     *   'keterangan' => string,
     *   'debit'      => float,
     *   'kredit'     => float,
     *   'saldo'      => float,
     * ]
     */
    public function parse(string $filePath): array;
}
