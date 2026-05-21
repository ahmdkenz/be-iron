<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

class BankColumnMapping
{
    // Config mapping: nama kolom bank (lowercase) → canonical field
    // Urutan array penting: keyword pertama = prioritas tertinggi
    const MAPS = [
        'MANDIRI' => [
            'tanggal'      => ['tanggal transaksi', 'tanggal'],
            'keterangan'   => ['deskripsi', 'keterangan', 'uraian'],
            'no_referensi' => ['no referensi', 'nomor referensi', 'no. referensi', 'no ref', 'no transaksi'],
            'debit'        => ['debet', 'debit'],
            'kredit'       => ['kredit'],
            'saldo'        => ['saldo'],
        ],
        'CIMB' => [
            'tanggal'      => ['tanggal transaksi', 'tgl transaksi', 'tanggal'],
            'keterangan'   => ['keterangan', 'deskripsi'],
            'no_referensi' => ['no referensi', 'nomor referensi', 'no. referensi', 'no ref', 'no transaksi'],
            'debit'        => ['mutasi debet', 'debet', 'debit'],
            'kredit'       => ['mutasi kredit', 'kredit'],
            'saldo'        => ['saldo akhir', 'saldo'],
        ],
    ];

    public static function get(string $bankType): array
    {
        return self::MAPS[strtoupper($bankType)]
            ?? throw new \InvalidArgumentException("Mapping kolom tidak tersedia untuk bank: {$bankType}");
    }

    public static function has(string $bankType): bool
    {
        return isset(self::MAPS[strtoupper($bankType)]);
    }
}
