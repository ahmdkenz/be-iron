<?php

namespace App\Support\Exceptions;

/**
 * Dilempar dari dalam callback pembaca baris (chunkXlsxRows/chunkCsvRows/chunkMasterRows)
 * begitu flag "batal diminta" (HasCancelableImport::isCancelRequested()) terdeteksi, supaya
 * pembacaan file berhenti SEGERA tanpa perlu mengubah pembaca chunk itu sendiri (readernya
 * stateful — lihat removeRow() di chunkXlsxRows/chunkMasterRows — jadi lebih aman dihentikan
 * dari luar lewat exception daripada ditambah mekanisme "return false to stop").
 *
 * Ditangkap oleh method fase (parse()/classify()/validate*Sheet() dst.) yang memanggilnya,
 * BUKAN oleh job (supaya job tidak salah mengira ini error asli dan menandai batch "failed"
 * dengan pesan teknis) — method fase yang menangkap bertanggung jawab memanggil
 * $batch->cancel(...) untuk bersih-bersih.
 */
class ImportCancelledException extends \RuntimeException
{
}
