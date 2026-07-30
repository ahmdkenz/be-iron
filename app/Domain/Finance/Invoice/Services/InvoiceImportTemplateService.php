<?php

namespace App\Domain\Finance\Invoice\Services;

/**
 * Template CSV untuk tab "Import Master Invoice" — native PHP (fputcsv), TANPA PhpSpreadsheet.
 *
 * Dipisah dari template Master Data karena sejak alur import aman diperkenalkan,
 * sheet MASTER INVOICE tidak lagi diproses oleh tab Import Master Data.
 */
class InvoiceImportTemplateService
{
    /** Header — urutan & nama HARUS identik dengan kolom yang dibaca InvoiceImportService::parse(). */
    private const HEADERS = [
        'nama_klien (*)', 'tanggal_invoice (*)', 'tanggal_jatuh_tempo', 'no_surat_jalan',
        'keterangan_invoice', 'no_invoice_resto', 'kode_resto (*)', 'nama_resto',
        'kode_barang', 'nama_barang (*)', 'qty (*)', 'satuan', 'harga_satuan (*)', 'tipe_invoice (*)',
    ];

    /**
     * Baris instruksi — diawali "#" supaya otomatis dilewati parser (lihat
     * InvoiceImportService::parse(), skip baris berawalan "#"). Menggantikan
     * sheet "Petunjuk Pengisian" terpisah yang tidak bisa ada di CSV (1 file = 1 tabel).
     */
    private const INSTRUCTIONS = [
        '# TEMPLATE IMPORT MASTER INVOICE (B2B & B2C)',
        '# 1 baris = 1 item invoice. Baris dengan tipe_invoice + klien (outlet) + tanggal_invoice yang sama digabung menjadi 1 invoice.',
        '# Kolom bertanda (*) wajib diisi di SETIAP baris, termasuk kode_resto.',
        '# kode_resto wajib diisi di setiap baris (B2B maupun B2C) - dipakai memvalidasi baris terhadap MASTER DATA & menentukan outlet tujuan.',
        '# tipe_invoice wajib konsisten dengan segmen outlet di MASTER DATA: outlet PT -> B2B, outlet RESTO -> B2C. Kalau tidak cocok, baris DITOLAK.',
        '# nama_klien wajib sama persis dengan nama Client AR aktif untuk kode_resto tersebut. Format tanggal: DD-MM-YYYY. qty harus lebih dari 0.',
        '# PENTING: import ini TIDAK menimpa invoice yang sudah dibayar / sudah cocok rekening koran / periodenya terkunci - perubahan seperti itu ditampilkan sebagai kandidat Credit Note / Debit Note untuk ditinjau dulu.',
        '# Upload hanya bisa dilakukan oleh role ADMIN, MANAGER, atau SUPERVISOR. Baris [CONTOH] & baris diawali "#" otomatis diabaikan saat import.',
    ];

    /** Baris contoh — 2 item B2C (1 invoice) + 1 item B2B, sama seperti template lama. */
    private const EXAMPLES = [
        ['Nama Klien B2C', '01-06-2026', '30-06-2026', 'SJ-001', 'Keterangan invoice', '', 'KD-001', 'Nama Resto', 'BRG-001', 'Nama Barang A', '10', 'pcs', '50000', '[CONTOH] B2C'],
        ['Nama Klien B2C', '01-06-2026', '30-06-2026', 'SJ-001', 'Keterangan invoice', '', 'KD-001', 'Nama Resto', 'BRG-002', 'Nama Barang B', '5', 'kg', '20000', '[CONTOH] B2C'],
        ['Nama Klien B2B', '01-06-2026', '30-06-2026', '', '', 'SI-RESTO-001', 'KD-RESTO-01', 'Nama Resto Asal', 'BRG-001', 'Nama Barang A', '20', 'pcs', '50000', '[CONTOH] B2B'],
    ];

    /** @return array<int, array<int, string>> Baris-baris siap ditulis via fputcsv, urut dari atas. */
    public function buildRows(): array
    {
        $rows = [];

        foreach (self::INSTRUCTIONS as $line) {
            $rows[] = [$line];
        }

        $rows[] = self::HEADERS;

        foreach (self::EXAMPLES as $example) {
            $rows[] = $example;
        }

        return $rows;
    }
}
