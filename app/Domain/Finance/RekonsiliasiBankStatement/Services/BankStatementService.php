<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Services;

use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankParserFactory;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BankStatementService
{
    public function upload(UploadedFile $file, string $bankType, int $userId): BankStatement
    {
        $parser = BankParserFactory::make($bankType);
        $tempPath = $file->getPathname();
        $rows = $parser->parse($tempPath);

        if (empty($rows)) {
            throw new \RuntimeException('File tidak mengandung transaksi yang dapat dibaca. Pastikan format file sesuai dengan bank yang dipilih.');
        }

        return DB::transaction(function () use ($rows, $bankType, $file, $userId) {
            $tanggalList = array_column($rows, 'tanggal');
            sort($tanggalList);

            $statement = BankStatement::create([
                'bank_type'       => $bankType,
                'nama_file'       => $file->getClientOriginalName(),
                'periode_awal'    => $tanggalList[0] ?? null,
                'periode_akhir'   => end($tanggalList) ?: null,
                'total_transaksi' => count($rows),
                'total_kredit'    => array_sum(array_column($rows, 'kredit')),
                'jumlah_matched'  => 0,
                'jumlah_unmatched'=> 0,
                'uploaded_by'     => $userId,
            ]);

            foreach ($rows as $row) {
                BankStatementDetail::create([
                    'bank_statement_id' => $statement->id,
                    'tanggal'           => $row['tanggal'],
                    'keterangan'        => $row['keterangan'],
                    'debit'             => $row['debit'],
                    'kredit'            => $row['kredit'],
                    'saldo'             => $row['saldo'],
                    'status_cocok'      => $row['kredit'] == 0 ? 'DIABAIKAN' : 'UNMATCHED',
                    'pembayaran_ar_id'  => null,
                ]);
            }

            $this->autoMatch($statement);

            return $statement->fresh();
        });
    }

    public function autoMatch(BankStatement $statement, bool $onlyUnmatched = false): void
    {
        $usedPembayaranIds = BankStatementDetail::where('bank_statement_id', $statement->id)
            ->where('status_cocok', 'MATCHED')
            ->whereNotNull('pembayaran_ar_id')
            ->pluck('pembayaran_ar_id')
            ->toArray();

        $query = BankStatementDetail::where('bank_statement_id', $statement->id)
            ->where('kredit', '>', 0)
            ->orderBy('tanggal');

        if ($onlyUnmatched) {
            $query->where('status_cocok', 'UNMATCHED');
        }

        $details = $query->get();

        foreach ($details as $detail) {
            $tanggal = Carbon::parse($detail->tanggal);
            $from    = $tanggal->copy()->subDays(7)->toDateString();
            $to      = $tanggal->copy()->addDays(7)->toDateString();

            $candidates = PembayaranAr::with(['invoice.klienAr'])
                ->whereBetween('tanggal_pembayaran', [$from, $to])
                ->where('jumlah_pembayaran', $detail->kredit)
                ->where('metode_pembayaran', 'TRANSFER')
                ->whereNotIn('id', $usedPembayaranIds)
                ->get();

            if ($candidates->isEmpty()) {
                $detail->status_cocok     = 'UNMATCHED';
                $detail->pembayaran_ar_id = null;
                $detail->save();
                continue;
            }

            $scored = $candidates
                ->map(fn($c) => ['candidate' => $c, 'score' => $this->scoreCandidate($c, $detail->keterangan ?? '', $tanggal)])
                ->sortByDesc('score')
                ->values();

            $top    = $scored[0];
            $second = $scored[1] ?? null;

            if ($candidates->count() === 1) {
                $status = 'MATCHED';
            } elseif ($top['score'] >= 200) {
                // Referensi ditemukan di keterangan bank — kepercayaan sangat tinggi
                $status = 'MATCHED';
            } elseif ($top['score'] >= 50 && ($second === null || $top['score'] > $second['score'] * 1.75)) {
                // Kandidat terbaik jauh dominan dibanding kandidat lainnya
                $status = 'MATCHED';
            } else {
                $status = 'POSSIBLE';
            }

            $detail->status_cocok     = $status;
            $detail->pembayaran_ar_id = $top['candidate']->id;

            if ($status === 'MATCHED') {
                $usedPembayaranIds[] = $top['candidate']->id;
            }

            $detail->save();
        }

        $this->refreshCounter($statement->id);
        $statement->refresh();
    }

    private function scoreCandidate(PembayaranAr $candidate, string $bankKeterangan, Carbon $bankTanggal): int
    {
        $score        = 0;
        $bankKetLower = mb_strtolower($bankKeterangan);

        $diffDays = abs($bankTanggal->diffInDays(Carbon::parse($candidate->tanggal_pembayaran)));
        $score   += match (true) {
            $diffDays === 0 => 50,
            $diffDays === 1 => 30,
            $diffDays === 2 => 15,
            default         => 5,
        };

        $noRef = trim((string) ($candidate->no_referensi ?? ''));
        if ($noRef !== '' && str_contains($bankKetLower, mb_strtolower($noRef))) {
            $score += 200;
        }

        $namaKlien = trim((string) ($candidate->invoice?->klienAr?->nama_klien ?? ''));
        if ($namaKlien !== '' && str_contains($bankKetLower, mb_strtolower($namaKlien))) {
            $score += 80;
        }

        return $score;
    }

    public function getDetail(int $bankStatementId): array
    {
        $statement = BankStatement::with('uploader')->findOrFail($bankStatementId);

        $details = BankStatementDetail::with(['pembayaranAr.invoice.klienAr'])
            ->where('bank_statement_id', $bankStatementId)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get()
            ->map(fn($d) => [
                'id'          => $d->id,
                'tanggal'     => $d->tanggal?->toDateString(),
                'keterangan'  => $d->keterangan,
                'debit'       => $d->debit,
                'kredit'      => $d->kredit,
                'saldo'       => $d->saldo,
                'status_cocok'=> $d->status_cocok,
                'pembayaran'  => $d->pembayaranAr ? [
                    'id'                 => $d->pembayaranAr->id,
                    'no_referensi'       => $d->pembayaranAr->no_referensi,
                    'tanggal_pembayaran' => $d->pembayaranAr->tanggal_pembayaran?->toDateString(),
                    'jumlah_pembayaran'  => $d->pembayaranAr->jumlah_pembayaran,
                    'metode_pembayaran'  => $d->pembayaranAr->metode_pembayaran,
                    'klien'              => $d->pembayaranAr->invoice?->klienAr?->nama_klien,
                ] : null,
            ])
            ->all();

        return [
            'id'               => $statement->id,
            'bank_type'        => $statement->bank_type,
            'nama_file'        => $statement->nama_file,
            'periode_awal'     => $statement->periode_awal?->toDateString(),
            'periode_akhir'    => $statement->periode_akhir?->toDateString(),
            'total_transaksi'  => $statement->total_transaksi,
            'total_kredit'     => $statement->total_kredit,
            'jumlah_matched'   => $statement->jumlah_matched,
            'jumlah_unmatched' => $statement->jumlah_unmatched,
            'uploaded_by'      => $statement->uploader?->name,
            'created_at'       => $statement->created_at?->toDateTimeString(),
            'details'          => $details,
        ];
    }

    public function getKandidat(BankStatementDetail $detail): \Illuminate\Support\Collection
    {
        $tanggal = Carbon::parse($detail->tanggal);
        $from    = $tanggal->copy()->subDays(14)->toDateString();
        $to      = $tanggal->copy()->addDays(14)->toDateString();

        $lockedIds = BankStatementDetail::where('bank_statement_id', $detail->bank_statement_id)
            ->where('status_cocok', 'MATCHED')
            ->where('id', '!=', $detail->id)
            ->whereNotNull('pembayaran_ar_id')
            ->pluck('pembayaran_ar_id');

        return PembayaranAr::with(['invoice.klienAr'])
            ->whereBetween('tanggal_pembayaran', [$from, $to])
            ->where('jumlah_pembayaran', $detail->kredit)
            ->where('metode_pembayaran', 'TRANSFER')
            ->whereNotIn('id', $lockedIds)
            ->orderByRaw('ABS(DATEDIFF(tanggal_pembayaran, ?))', [$detail->tanggal])
            ->get()
            ->map(fn($p) => [
                'id'                 => $p->id,
                'no_referensi'       => $p->no_referensi,
                'tanggal_pembayaran' => $p->tanggal_pembayaran?->toDateString(),
                'jumlah_pembayaran'  => $p->jumlah_pembayaran,
                'metode_pembayaran'  => $p->metode_pembayaran,
                'klien'              => $p->invoice?->klienAr?->nama_klien,
                'no_invoice'         => $p->invoice?->no_invoice,
                'keterangan'         => $p->keterangan,
            ]);
    }

    public function manualMatch(BankStatementDetail $detail, int $pembayaranArId): BankStatementDetail
    {
        $alreadyUsed = BankStatementDetail::where('bank_statement_id', $detail->bank_statement_id)
            ->where('status_cocok', 'MATCHED')
            ->where('pembayaran_ar_id', $pembayaranArId)
            ->where('id', '!=', $detail->id)
            ->exists();

        abort_if($alreadyUsed, 422, 'Pembayaran ini sudah dicocokkan ke transaksi lain.');

        $detail->update([
            'status_cocok'    => 'MATCHED',
            'pembayaran_ar_id'=> $pembayaranArId,
        ]);

        $this->refreshCounter($detail->bank_statement_id);

        return $detail->fresh()->load('pembayaranAr.invoice.klienAr');
    }

    public function unmatch(BankStatementDetail $detail): BankStatementDetail
    {
        abort_if($detail->status_cocok !== 'MATCHED', 422, 'Hanya transaksi MATCHED yang dapat dibatalkan.');

        $detail->update([
            'status_cocok'    => 'UNMATCHED',
            'pembayaran_ar_id'=> null,
        ]);

        $this->refreshCounter($detail->bank_statement_id);

        return $detail->fresh();
    }

    public function markDiabaikan(BankStatementDetail $detail): void
    {
        $detail->update([
            'status_cocok'    => 'DIABAIKAN',
            'pembayaran_ar_id'=> null,
        ]);

        $this->refreshCounter($detail->bank_statement_id);
    }

    private function refreshCounter(int $statementId): void
    {
        $matched   = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'MATCHED')->count();
        $unmatched = BankStatementDetail::where('bank_statement_id', $statementId)->where('status_cocok', 'UNMATCHED')->count();

        BankStatement::where('id', $statementId)->update([
            'jumlah_matched'   => $matched,
            'jumlah_unmatched' => $unmatched,
        ]);
    }
}
