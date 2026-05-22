<?php

namespace App\Domain\Finance\RekonsiliasiBankStatement\Services;

use App\Domain\Finance\Invoice\Services\InvoiceService;
use App\Domain\Finance\RekonsiliasiBankStatement\Exceptions\DuplicateStatementException;
use App\Domain\Finance\RekonsiliasiBankStatement\Parsers\BankParserFactory;
use App\Models\BankStatement;
use App\Models\BankStatementDetail;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BankStatementService
{
    public function upload(UploadedFile $file, string $bankType, int $userId, bool $force = false): BankStatement
    {
        $parser = BankParserFactory::make($bankType);
        $rows   = $parser->parse($file->getPathname());

        if (empty($rows)) {
            throw new \RuntimeException('File tidak mengandung transaksi yang dapat dibaca. Pastikan format file sesuai dengan bank yang dipilih.');
        }

        $tanggalList  = array_column($rows, 'tanggal');
        sort($tanggalList);
        $periodeAwal  = $tanggalList[0];
        $periodeAkhir = end($tanggalList);

        $existing = BankStatement::where('bank_type', $bankType)
            ->where('periode_awal', '<=', $periodeAkhir)
            ->where('periode_akhir', '>=', $periodeAwal)
            ->first();

        if ($existing && !$force) {
            throw new DuplicateStatementException($existing);
        }

        return DB::transaction(function () use ($rows, $bankType, $file, $userId, $existing, $periodeAwal, $periodeAkhir) {
            if ($existing) {
                $existing->details()->delete();
                $existing->delete();
            }

            $statement = BankStatement::create([
                'bank_type'       => $bankType,
                'nama_file'       => $file->getClientOriginalName(),
                'periode_awal'    => $periodeAwal,
                'periode_akhir'   => $periodeAkhir,
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
                    'no_referensi'      => $row['no_referensi'] ?? null,
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

        $details = BankStatementDetail::with([
                'pembayaranAr.invoice.klienAr',
                'pembayaranAr.alokasiKelebihan.invoice.klienAr',
                'pembayaranAr.alokasiKelebihan.createdBy',
                'matchedBy',
            ])
            ->where('bank_statement_id', $bankStatementId)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get()
            ->map(function ($d) {
                $pembayaran    = $d->pembayaranAr;
                $invoice       = $pembayaran?->invoice;
                $selisihBank   = $pembayaran
                    ? round($d->kredit - (float) $pembayaran->jumlah_pembayaran, 2)
                    : null;

                $kelebihanBayar = null;
                if ($invoice) {
                    $total = max(0, round((float) $invoice->total_pembayaran - (float) $invoice->total_tagihan, 2));
                    if ($total > 0) {
                        // Hitung berapa dari kelebihan yang benar-benar mengurangi utang
                        // invoice tujuan: min(yang dikirim, sisa tagihan B sebelum P1 masuk).
                        $dialokasi = $pembayaran->alokasiKelebihan
                            ->groupBy('invoice_id')
                            ->sum(function ($alokasis) {
                                $target = $alokasis->first()->invoice;
                                if (!$target) return (float) $alokasis->sum('jumlah_pembayaran');
                                $groupTotal  = (float) $alokasis->sum('jumlah_pembayaran');
                                $sisaSebelum = max(0,
                                    (float) $target->total_tagihan
                                    - ((float) $target->total_pembayaran - $groupTotal)
                                );
                                return min($groupTotal, $sisaSebelum);
                            });
                        $kelebihanBayar = [
                            'total'           => $total,
                            'sudah_dialokasi' => round($dialokasi, 2),
                            'sisa'            => max(0, round($total - $dialokasi, 2)),
                            'riwayat'         => $pembayaran->alokasiKelebihan->map(fn($p) => [
                                'id'         => $p->id,
                                'jumlah'     => $p->jumlah_pembayaran,
                                'no_invoice' => $p->invoice?->no_invoice,
                                'klien'      => $p->invoice?->klienAr?->nama_klien,
                                'keterangan' => $p->keterangan,
                                'created_by' => $p->createdBy?->name,
                                'tanggal'    => $p->tanggal_pembayaran?->toDateString(),
                            ])->values(),
                        ];
                    }
                }

                return [
                    'id'            => $d->id,
                    'tanggal'       => $d->tanggal?->toDateString(),
                    'keterangan'    => $d->keterangan,
                    'no_referensi'  => $d->no_referensi,
                    'debit'         => $d->debit,
                    'kredit'        => $d->kredit,
                    'saldo'         => $d->saldo,
                    'status_cocok'  => $d->status_cocok,
                    'selisih_bank'  => $selisihBank,
                    'matched_by'    => $d->matchedBy?->name,
                    'kelebihan_bayar' => $kelebihanBayar,
                    'pembayaran'    => $pembayaran ? [
                        'id'                 => $pembayaran->id,
                        'no_referensi'       => $pembayaran->no_referensi,
                        'tanggal_pembayaran' => $pembayaran->tanggal_pembayaran?->toDateString(),
                        'jumlah_pembayaran'  => $pembayaran->jumlah_pembayaran,
                        'metode_pembayaran'  => $pembayaran->metode_pembayaran,
                        'klien'              => $invoice?->klienAr?->nama_klien,
                    ] : null,
                ];
            })
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
            'created_at'       => $statement->created_at?->setTimezone('Asia/Jakarta')->format('d-m-Y H:i'),
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
            'matched_by'      => auth()->id(),
        ]);

        $this->refreshCounter($detail->bank_statement_id);

        return $detail->fresh()->load('pembayaranAr.invoice.klienAr', 'matchedBy');
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

    public function getInvoiceB2CKlien(BankStatementDetail $detail): \Illuminate\Support\Collection
    {
        $klienArId = $detail->pembayaranAr?->invoice?->klien_ar_id;
        abort_if(!$klienArId, 422, 'Belum ada pembayaran yang dicocokkan.');

        return Invoice::with('klienAr')
            ->where('klien_ar_id', $klienArId)
            ->whereNotIn('status', ['LUNAS'])
            ->whereHas('klienAr', fn($q) => $q->whereIn('tipe_klien', ['RESTO', 'MITRA']))
            ->orderByDesc('tanggal_invoice')
            ->get()
            ->map(fn($inv) => [
                'id'            => $inv->id,
                'no_invoice'    => $inv->no_invoice,
                'tanggal'       => $inv->tanggal_invoice?->toDateString(),
                'total_tagihan' => $inv->total_tagihan,
                'sisa_tagihan'  => $inv->sisa_tagihan,
                'status'        => $inv->status,
            ]);
    }

    public function applyKelebihan(
        BankStatementDetail $detail,
        int $invoiceId,
        float $jumlah,
        ?string $keterangan
    ): void {
        $pembayaran = $detail->pembayaranAr;
        abort_if(!$pembayaran, 422, 'Transaksi belum memiliki pembayaran yang dicocokkan.');

        $inv       = $pembayaran->invoice;
        $total     = max(0, (float) $inv->total_pembayaran - (float) $inv->total_tagihan);
        $alokasiList = $pembayaran->alokasiKelebihan()->with('invoice')->get();
        $sudah = (float) $alokasiList
            ->groupBy('invoice_id')
            ->sum(function ($alokasis) {
                $targetInvoice = $alokasis->first()->invoice;
                if (!$targetInvoice) return (float) $alokasis->sum('jumlah_pembayaran');
                $groupTotal  = (float) $alokasis->sum('jumlah_pembayaran');
                $sisaSebelum = max(0,
                    (float) $targetInvoice->total_tagihan
                    - ((float) $targetInvoice->total_pembayaran - $groupTotal)
                );
                return min($groupTotal, $sisaSebelum);
            });
        $sisa = max(0, round($total - $sudah, 2));

        abort_if($jumlah <= 0, 422, 'Jumlah harus lebih dari 0.');
        abort_if(
            $jumlah > $sisa + 0.01,
            422,
            'Jumlah melebihi sisa kelebihan (Rp ' . number_format($sisa, 0, ',', '.') . ').'
        );

        $target = Invoice::with('klienAr')->findOrFail($invoiceId);
        abort_if(
            $target->klien_ar_id !== $inv->klien_ar_id,
            422,
            'Invoice tujuan harus milik klien yang sama.'
        );
        abort_if($target->status === 'LUNAS', 422, 'Invoice ini sudah LUNAS.');

        PembayaranAr::create([
            'invoice_id'               => $invoiceId,
            'tanggal_pembayaran'       => $pembayaran->tanggal_pembayaran,
            'jumlah_pembayaran'        => $jumlah,
            'metode_pembayaran'        => $pembayaran->metode_pembayaran,
            'no_referensi'             => $pembayaran->no_referensi
                                            ? $pembayaran->no_referensi . '/ALO-' . ($this->nextAlokasiSuffix($pembayaran))
                                            : null,
            'keterangan'               => $keterangan ?? 'Alokasi kelebihan dari ' . $inv->no_invoice,
            'sumber_pembayaran_ar_id'  => $pembayaran->id,
            'created_by'               => auth()->id(),
        ]);

        app(InvoiceService::class)->recalculate($target->fresh());
    }

    private function nextAlokasiSuffix(PembayaranAr $pembayaran): int
    {
        $prefix = $pembayaran->no_referensi . '/ALO-';
        $max = $pembayaran->alokasiKelebihan()
            ->where('no_referensi', 'LIKE', $prefix . '%')
            ->get(['no_referensi'])
            ->map(function ($a) use ($prefix) {
                $n = substr($a->no_referensi ?? '', strlen($prefix));
                return is_numeric($n) ? (int) $n : 0;
            })
            ->max() ?? 0;
        return $max + 1;
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
