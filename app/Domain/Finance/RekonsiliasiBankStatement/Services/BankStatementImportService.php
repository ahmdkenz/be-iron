<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Services;

use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankParserFactory;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
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
        // Reset penuh counter batch di setiap run() — termasuk saat confirm-replace
        // mendispatch job ulang untuk batch yang sama. Tanpa ini, sisa counter dari
        // proses sebelumnya (mis. processed_rows dari scan file, total_rows dari
        // parse lama) ikut terbaca FE sebelum job baru sempat menimpanya, sehingga
        // muncul angka tidak masuk akal seperti "24020 / 15770".
        $batch->update([
            'status'            => 'processing',
            'phase'             => 'parsing',
            'started_at'        => now(),
            'finished_at'       => null,
            'message'           => null,
            'total_rows'        => 0,
            'processed_rows'    => 0,
            'inserted_rows'     => 0,
            'matched_rows'      => 0,
            'unmatched_rows'    => 0,
            'ignored_rows'      => 0,
            'error_rows'        => 0,
            'total_kredit'      => 0,
            'bank_statement_id' => null,
            'overlaps'          => null,
            'errors'            => null,
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

        // processed_rows selama parsing menghitung baris FILE yang di-scan (termasuk
        // baris kosong/footer yang bukan transaksi), sedangkan total_rows di sini
        // adalah jumlah transaksi VALID. Begitu parsing selesai dan jumlah valid
        // diketahui, samakan processed_rows = total_rows supaya fase-fase berikutnya
        // (validating/checking_overlap/saving/auto_matching) tidak menampilkan sisa
        // angka scan yang lebih besar dari total transaksi.
        $batch->update([
            'phase'          => 'validating',
            'total_rows'     => $rowNumber,
            'processed_rows' => $rowNumber,
            'total_kredit'   => $totalKredit,
        ]);

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
            // Baris lama yang sudah dibayar/dicocokkan (atau ditandai DIABAIKAN)
            // SELALU dipindahkan (reparent) ke statement baru apa adanya — tanpa
            // syarat, terlepas dari apakah transaksinya masih ada di file baru
            // atau tidak (mis. baris koreksi nominal, atau baris yang memang
            // sudah tidak muncul lagi di file terbaru). Baris ini tidak pernah
            // di-unmatch atau dihapus saat reupload.
            //
            // Kunci komposit (no_referensi + tanggal + nominal) HANYA dipakai
            // untuk mencegah baris file baru yang mewakili transaksi persis sama
            // ikut di-insert sebagai duplikat — bukan untuk memutuskan apakah
            // baris lama boleh bertahan. no_referensi saja tidak cukup sebagai
            // kunci karena TIDAK unik/tidak diindeks pada tb_bank_statement_detail.
            $reparentIds = [];
            $buckets     = [];
            if ($batch->force_replace && $overlaps->isNotEmpty()) {
                BankStatementDetail::whereIn('bank_statement_id', $overlaps->pluck('id'))
                    ->carryOverEligible()
                    ->get(['id', 'no_referensi', 'tanggal', 'debit', 'kredit'])
                    ->each(function (BankStatementDetail $oldRow) use (&$buckets, &$reparentIds) {
                        $reparentIds[] = $oldRow->id;
                        $key = $this->compositeKey($oldRow->no_referensi, $oldRow->tanggal, $oldRow->debit, $oldRow->kredit);
                        $buckets[$key] = ($buckets[$key] ?? 0) + 1;
                    });
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
            $freshInsertedCount = 0;

            BankStatementImportRow::where('batch_id', $batch->id)
                ->orderBy('row_number')
                ->chunk(self::CHUNK, function ($rows) use ($statement, $now, &$buckets, &$freshInsertedCount) {
                    $insert = [];
                    foreach ($rows as $r) {
                        // Baris file baru yang kuncinya persis sama dengan baris lama
                        // yang sudah dipertahankan: JANGAN insert baris ini supaya
                        // transaksi yang sama tidak tampil dobel — baris lamanya
                        // sudah di-reparent apa adanya di bawah.
                        $key = $this->compositeKey($r->no_referensi, $r->tanggal, $r->debit, $r->kredit);
                        if (!empty($buckets[$key])) {
                            $buckets[$key]--;
                            continue;
                        }

                        $freshInsertedCount++;
                        $insert[] = [
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
                        ];
                    }

                    if (!empty($insert)) {
                        BankStatementDetail::insert($insert);
                    }
                });

            // Pindahkan seluruh baris protected/DIABAIKAN ke statement baru.
            // Tidak ada kolom lain yang disentuh (status_cocok, pembayaran_ar_id,
            // pembayaran_ap_id, matched_by tetap sama persis) — bukan unmatch,
            // bukan recreate.
            foreach (array_chunk($reparentIds, 500) as $chunk) {
                BankStatementDetail::whereIn('id', $chunk)->update(['bank_statement_id' => $statement->id]);
            }

            if ($batch->force_replace) {
                foreach ($overlaps as $existing) {
                    // Sisa baris di statement lama sekarang pasti UNMATCHED tanpa
                    // pembayaran (carryOverEligible di atas sudah memindahkan semua
                    // yang MATCHED/DIABAIKAN) — aman dihapus langsung tanpa unmatch().
                    $existing->details()->delete();
                    $existing->delete();
                }
            }

            // Agregat header dihitung ulang dari data riil (bukan disalin dari
            // $batch) karena baris hasil reparent bisa memperluas rentang periode
            // di luar file yang baru diupload.
            $agg = BankStatementDetail::where('bank_statement_id', $statement->id)
                ->selectRaw('COUNT(*) as total, COALESCE(SUM(kredit),0) as total_kredit, MIN(tanggal) as awal, MAX(tanggal) as akhir')
                ->first();

            $statement->update([
                'total_transaksi' => $agg->total,
                'total_kredit'    => $agg->total_kredit,
                'periode_awal'    => $agg->awal,
                'periode_akhir'   => $agg->akhir,
            ]);

            $batch->update(['bank_statement_id' => $statement->id, 'phase' => 'auto_matching']);

            // onlyUnmatched=true WAJIB: statement ini bisa berisi baris pre-matched
            // hasil reparent. autoMatch() tanpa flag ini akan mengevaluasi ulang
            // baris tsb, gagal menemukan kandidat (karena PembayaranAr-nya sendiri
            // sudah dikecualikan dari daftar kandidat sebagai "sudah dipakai"), dan
            // secara diam-diam meng-UNMATCH baris yang justru ingin dilindungi.
            $this->bankStatementService->autoMatch($statement, true);

            $statement->refresh();
            $statement->update(['is_committed' => true]);

            $preservedCount = count($reparentIds);
            $message = $preservedCount > 0
                ? "Import berhasil: {$freshInsertedCount} transaksi baru, {$preservedCount} transaksi lama (sudah dibayar/dicocokkan) dipertahankan."
                : "Import berhasil: {$statement->total_transaksi} transaksi diproses.";

            $batch->update([
                'status'         => 'completed',
                'phase'          => 'completed',
                'inserted_rows'  => $statement->total_transaksi,
                'matched_rows'   => $statement->jumlah_matched,
                'unmatched_rows' => $statement->jumlah_unmatched,
                'ignored_rows'   => max(0, $statement->total_transaksi - $statement->jumlah_matched - $statement->jumlah_unmatched),
                'message'        => $message,
                'finished_at'    => now(),
            ]);
        });
    }

    /**
     * Kunci komposit untuk mendeteksi "baris yang sama" antara file lama & baru
     * saat reupload. no_referensi saja tidak cukup (tidak unik/tidak diindeks di
     * tb_bank_statement_detail, bisa kosong/berulang) — digabung dengan tanggal
     * & nominal (dikonversi ke sen integer untuk menghindari isu perbandingan
     * float pada kolom decimal(15,2)).
     */
    private function compositeKey(?string $noReferensi, $tanggal, $debit, $kredit): string
    {
        $ref  = trim((string) $noReferensi);
        $date = $tanggal instanceof \DateTimeInterface ? $tanggal->format('Y-m-d') : (string) $tanggal;

        return $ref . '|' . $date . '|' . (int) round(((float) $debit) * 100) . '|' . (int) round(((float) $kredit) * 100);
    }
}
