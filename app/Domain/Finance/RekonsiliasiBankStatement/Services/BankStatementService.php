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

    public function autoMatch(BankStatement $statement): void
    {
        // Kumpulkan pembayaran_ar_id yang sudah dipakai dalam upload ini
        $usedPembayaranIds = collect();

        $details = BankStatementDetail::where('bank_statement_id', $statement->id)
            ->where('kredit', '>', 0)
            ->orderBy('tanggal')
            ->get();

        foreach ($details as $detail) {
            $tanggal = Carbon::parse($detail->tanggal);
            $from    = $tanggal->copy()->subDays(3)->toDateString();
            $to      = $tanggal->copy()->addDays(3)->toDateString();

            $candidates = PembayaranAr::whereBetween('tanggal_pembayaran', [$from, $to])
                ->where('jumlah_pembayaran', $detail->kredit)
                ->whereNotIn('id', $usedPembayaranIds->toArray())
                ->orderByRaw('ABS(DATEDIFF(tanggal_pembayaran, ?))', [$detail->tanggal])
                ->get();

            if ($candidates->count() === 1) {
                $detail->status_cocok     = 'MATCHED';
                $detail->pembayaran_ar_id = $candidates->first()->id;
                $usedPembayaranIds->push($candidates->first()->id);
            } elseif ($candidates->count() > 1) {
                $detail->status_cocok     = 'POSSIBLE';
                $detail->pembayaran_ar_id = $candidates->first()->id;
                // Tidak lock pembayaran_ar_id agar POSSIBLE tidak menghalangi MATCHED lainnya
            } else {
                $detail->status_cocok     = 'UNMATCHED';
                $detail->pembayaran_ar_id = null;
            }

            $detail->save();
        }

        // Update summary counter
        $matched   = BankStatementDetail::where('bank_statement_id', $statement->id)->where('status_cocok', 'MATCHED')->count();
        $unmatched = BankStatementDetail::where('bank_statement_id', $statement->id)->where('status_cocok', 'UNMATCHED')->count();

        $statement->update([
            'jumlah_matched'   => $matched,
            'jumlah_unmatched' => $unmatched,
        ]);
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
