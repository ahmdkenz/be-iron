<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Services;

use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankParserFactory;
use App\Models\BankStatement;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementImportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Orkestrasi import rekening koran secara async (dipanggil dari ImportBankStatementJob).
 * Alur: parsing → staging (tb_bank_statement_import_rows) → validating → checking_overlap
 * → (needs_confirmation, berhenti di sini jika ada tumpang tindih & belum force_replace)
 * → saving + auto_matching → completed.
 *
 * Job ini tries=1 (tidak retry otomatis) dan confirm-replace selalu mendispatch job
 * baru dari awal, jadi setiap run() dimulai dari staging kosong — tidak ada asumsi
 * kelanjutan dari staging rows sebelumnya.
 */
class BankStatementImportService
{
    private const CHUNK = 750;

    /** Maks baris error yang disimpan ke kolom errors (JSON) — bukan seluruh error jika ribuan. */
    private const MAX_STORED_ERRORS = 200;

    public function __construct(private readonly BankStatementService $bankStatementService) {}

    public function run(BankStatementImportBatch $batch): void
    {
        $batch->update([
            'status'      => 'processing',
            'phase'       => 'parsing',
            'started_at'  => $batch->started_at ?? now(),
            'message'     => null,
        ]);

        // Job tidak retry-safe (tries=1) dan confirm-replace mendispatch ulang dari
        // awal — selalu mulai dari staging kosong untuk batch ini.
        BankStatementImportRow::where('batch_id', $batch->id)->delete();

        if (!$batch->file_path || !Storage::disk('local')->exists($batch->file_path)) {
            $batch->update([
                'status' => 'failed', 'phase' => 'failed',
                'message' => 'File upload tidak ditemukan (mungkin sudah dibersihkan sebelumnya).',
                'finished_at' => now(),
            ]);
            return;
        }

        $fullPath = Storage::disk('local')->path($batch->file_path);
        $parser   = BankParserFactory::make($batch->bank_type);

        $rowNumber = 0;
        $errors    = [];
        $errorCount = 0;
        $totalKredit = 0.0;

        try {
            $parser->parseInChunks(
                $fullPath,
                function (array $rows, array $chunkErrors, int $scanned) use ($batch, &$rowNumber, &$errors, &$errorCount, &$totalKredit) {
                    $errorCount += count($chunkErrors);
                    if (count($errors) < self::MAX_STORED_ERRORS) {
                        array_push($errors, ...array_slice($chunkErrors, 0, self::MAX_STORED_ERRORS - count($errors)));
                    }

                    if (!empty($rows)) {
                        $insert = [];
                        foreach ($rows as $row) {
                            $rowNumber++;
                            $insert[] = [
                                'batch_id'        => $batch->id,
                                'row_number'      => $rowNumber,
                                'tanggal'         => $row['tanggal'],
                                'waktu_transaksi' => $row['waktu_transaksi'] ?? null,
                                'keterangan'      => $row['keterangan'],
                                'no_referensi'    => $row['no_referensi'] ?? null,
                                'debit'           => $row['debit'],
                                'kredit'          => $row['kredit'],
                                'saldo'           => $row['saldo'],
                            ];
                            $totalKredit += (float) $row['kredit'];
                        }
                        BankStatementImportRow::insert($insert);
                    }

                    $batch->increment('processed_rows', $scanned);
                },
                self::CHUNK,
            );
        } catch (\RuntimeException $e) {
            $batch->update([
                'status' => 'failed', 'phase' => 'failed',
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            return;
        }

        if ($errorCount > 0) {
            $batch->update([
                'status' => 'failed', 'phase' => 'failed',
                'error_rows' => $errorCount,
                'errors'     => $errors,
                'message'    => "{$errorCount} baris gagal divalidasi. Perbaiki file lalu upload ulang.",
                'finished_at' => now(),
            ]);
            return;
        }

        if ($rowNumber === 0) {
            $batch->update([
                'status' => 'failed', 'phase' => 'failed',
                'message' => 'File tidak mengandung transaksi yang dapat dibaca. Pastikan format file sesuai dengan bank yang dipilih.',
                'finished_at' => now(),
            ]);
            return;
        }

        $batch->update(['phase' => 'validating', 'total_rows' => $rowNumber, 'total_kredit' => $totalKredit]);

        $periode = BankStatementImportRow::where('batch_id', $batch->id)
            ->selectRaw('MIN(tanggal) as periode_awal, MAX(tanggal) as periode_akhir')
            ->first();

        $batch->update([
            'phase'         => 'checking_overlap',
            'periode_awal'  => $periode->periode_awal,
            'periode_akhir' => $periode->periode_akhir,
        ]);

        $overlaps = BankStatement::where('bank_type', $batch->bank_type)
            ->where('periode_awal', '<=', $periode->periode_akhir)
            ->where('periode_akhir', '>=', $periode->periode_awal)
            ->get();

        if ($overlaps->isNotEmpty() && !$batch->force_replace) {
            $batch->update([
                'status'   => 'needs_confirmation',
                'phase'    => 'needs_confirmation',
                'overlaps' => $overlaps->map(fn($s) => [
                    'id'               => $s->id,
                    'nama_file'        => $s->nama_file,
                    'periode_awal'     => $s->periode_awal?->toDateString(),
                    'periode_akhir'    => $s->periode_akhir?->toDateString(),
                    'total_transaksi'  => $s->total_transaksi,
                    'jumlah_matched'   => $s->jumlah_matched,
                    'jumlah_unmatched' => $s->jumlah_unmatched,
                ])->all(),
                'message' => "Periode rekening koran ini bertumpang tindih dengan {$overlaps->count()} data yang sudah ada.",
            ]);
            // File & staging TETAP disimpan — dibutuhkan lagi jika user konfirmasi ganti.
            return;
        }

        $this->finalize($batch, $overlaps);
    }

    private function finalize(BankStatementImportBatch $batch, Collection $overlaps): void
    {
        $batch->update(['phase' => 'saving']);

        // Seluruh alur create+insert+auto_matching+penandaan batch "completed"
        // dibungkus SATU transaksi supaya atomic end-to-end: kalau worker mati di
        // titik manapun sebelum commit, transaksi rollback total (tidak ada
        // statement "setengah jadi" yang bocor), dan batch tetap di status lama
        // sehingga BankStatementImportBatch::failStale() akan menandainya failed.
        // is_committed=false→true di dalam transaksi yang sama juga jadi guardrail
        // eksplisit tambahan (lihat global scope di model BankStatement) untuk
        // seluruh query list/detail/laporan lain di luar modul ini.
        DB::transaction(function () use ($batch, $overlaps) {
            if ($batch->force_replace) {
                foreach ($overlaps as $existing) {
                    // Lepas tautan pembayaran/PDM/voucher AP yang sudah MATCHED lewat
                    // domain logic unmatch() (recalculate invoice/tagihan, hapus payment
                    // hasil "Catat Bayar/PDM/Voucher AP") sebelum statement lama dihapus —
                    // menghindari data pembayaran menggantung tanpa tautan detail bank.
                    $existing->details()->where('status_cocok', 'MATCHED')->get()
                        ->each(fn($d) => $this->bankStatementService->unmatch($d));

                    $existing->details()->delete();
                    $existing->delete();
                }
            }

            $statement = BankStatement::create([
                'bank_type'        => $batch->bank_type,
                'nama_file'        => $batch->original_filename,
                'periode_awal'     => $batch->periode_awal,
                'periode_akhir'    => $batch->periode_akhir,
                'total_transaksi'  => $batch->total_rows,
                'total_kredit'     => $batch->total_kredit,
                'jumlah_matched'   => 0,
                'jumlah_unmatched' => 0,
                'uploaded_by'      => $batch->user_id,
                'import_batch_id'  => $batch->id,
                'is_committed'     => false,
            ]);

            $now = now();
            BankStatementImportRow::where('batch_id', $batch->id)
                ->orderBy('row_number')
                ->chunk(self::CHUNK, function ($rows) use ($statement, $now) {
                    $insert = $rows->map(fn($r) => [
                        'bank_statement_id' => $statement->id,
                        'tanggal'           => $r->tanggal,
                        'waktu_transaksi'   => $r->waktu_transaksi,
                        'keterangan'        => $r->keterangan,
                        'no_referensi'      => $r->no_referensi,
                        'debit'             => $r->debit,
                        'kredit'            => $r->kredit,
                        'saldo'             => $r->saldo,
                        'status_cocok'      => ($r->debit == 0 && $r->kredit == 0) ? 'DIABAIKAN' : 'UNMATCHED',
                        'pembayaran_ar_id'  => null,
                        'pembayaran_ap_id'  => null,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ])->all();

                    \App\Models\BankStatementDetail::insert($insert);
                });

            $batch->update(['bank_statement_id' => $statement->id, 'phase' => 'auto_matching']);

            $this->bankStatementService->autoMatch($statement);

            $statement->refresh();
            $statement->update(['is_committed' => true]);

            $batch->update([
                'status'         => 'completed',
                'phase'          => 'completed',
                'inserted_rows'  => $statement->total_transaksi,
                'matched_rows'   => $statement->jumlah_matched,
                'unmatched_rows' => $statement->jumlah_unmatched,
                'ignored_rows'   => max(0, $statement->total_transaksi - $statement->jumlah_matched - $statement->jumlah_unmatched),
                'message'        => "Import berhasil: {$statement->total_transaksi} transaksi diproses.",
                'finished_at'    => now(),
            ]);
        });
    }
}
