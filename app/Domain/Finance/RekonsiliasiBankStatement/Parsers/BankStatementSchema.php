<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Parsers;

class BankStatementSchema
{
    const FIELDS = ['tanggal', 'waktu', 'keterangan', 'no_referensi', 'debit', 'kredit', 'saldo'];

    // Keyword per canonical field — dipakai oleh AbstractBankParser::detectHeaderRow()
    // untuk scoring baris header secara otomatis
    const FIELD_KEYWORDS = [
        'tanggal'      => ['tanggal transaksi', 'tanggal nilai', 'tanggal', 'tgl transaksi', 'tgl', 'date'],
        'waktu'        => ['jam transaksi', 'waktu transaksi', 'jam', 'waktu', 'trx time', 'time'],
        'keterangan'   => ['keterangan', 'deskripsi', 'uraian', 'description'],
        'no_referensi' => ['no referensi', 'nomor referensi', 'no. referensi', 'no ref', 'no. ref', 'nomor ref', 'reference number', 'ref no', 'no transaksi', 'transaction reference'],
        'debit'        => ['nominal debet', 'jumlah debet', 'mutasi debet', 'debet', 'debit'],
        'kredit'       => ['nominal kredit', 'jumlah kredit', 'mutasi kredit', 'kredit', 'credit'],
        'saldo'        => ['saldo akhir', 'saldo', 'balance'],
    ];
}
