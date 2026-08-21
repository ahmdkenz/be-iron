<?php

namespace App\Domain\Finance\Invoice\Controllers;

use App\Domain\Finance\Invoice\Jobs\ParseInvoiceImportJob;
use App\Domain\Finance\Invoice\Jobs\ProcessInvoiceImportChunkJob;
use App\Domain\Finance\Invoice\Services\InvoiceImportService;
use App\Domain\Finance\Invoice\Services\InvoiceImportTemplateService;
use App\Http\Controllers\Controller;
use App\Models\InvoiceImportBatch;
use App\Models\InvoiceImportGroup;
use App\Support\Helpers\ImportEtaCalculator;
use App\Support\Helpers\RoleHelper;
use App\Support\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tab "Import Master Invoice".
 *
 * Alur sengaja dipecah jadi dua aksi terpisah supaya reupload data lapangan
 * tidak pernah diam-diam menimpa invoice yang sudah ditagih/dibayar:
 *   1. upload  → parse & klasifikasi saja (tidak ada tulisan ke tb_invoice)
 *   2. apply-safe        → tulis hanya yang aman
 *   3. submit-adjustments → sisanya jadi kandidat Credit/Debit Note dengan approval
 */
class InvoiceImportController extends Controller
{
    use ApiResponse;

    /** Role yang boleh mengoperasikan import invoice — sama dengan Import Master Data. */
    private const ALLOWED_ROLES = ['ADMIN', 'MANAGER', 'SUPERVISOR'];

    public function __construct(private readonly InvoiceImportService $service) {}

    public function template(Request $request, InvoiceImportTemplateService $templates): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        if ($request->query('format') === 'xlsx') {
            if (!class_exists('ZipArchive')) {
                return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
            }

            return response()
                ->download($templates->buildXlsxFile(), 'template-import-master-invoice.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($templates) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            foreach ($templates->buildRows() as $row) {
                // Titik koma, bukan koma — locale Excel Indonesia pakai ";" sebagai list
                // separator sistem (koma dipakai untuk desimal), jadi file terbuka
                // langsung terbagi rapi per kolom tanpa perlu "Data > From Text/CSV".
                fputcsv($handle, $row, ';');
            }
            fclose($handle);
        }, 'template-import-master-invoice.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $request->validate([
            // csv juga sering dikirim dengan MIME text/plain oleh browser/OS, jadi 'txt' ikut diterima.
            // max:51200 (50MB) — endpoint ini sendirian ditinggikan dari konvensi 10MB modul lain
            // karena target volumenya csv s/d ~100.000 baris & xlsx dengan styling template ini.
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'],
        ]);

        $ext = strtolower($request->file('file')?->getClientOriginalExtension() ?? '');
        if (in_array($ext, ['xlsx', 'xls'], true) && !class_exists('ZipArchive')) {
            return $this->errorResponse('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip pada php.ini lalu restart server.', 500);
        }

        InvoiceImportBatch::failStale();

        $active = InvoiceImportBatch::whereNotIn('status', ['completed', 'failed'])
            ->orderByDesc('created_at')
            ->first();
        if ($active) {
            return $this->errorResponse(
                "Masih ada proses import invoice yang belum selesai (\"{$active->original_filename}\", status: {$active->status}). " .
                'Selesaikan atau tunggu batch tersebut dulu sebelum upload file baru — mengunggah bersamaan berisiko membuat invoice terduplikasi.',
                409,
                [
                    'batch_id'          => $active->id,
                    'status'            => $active->status,
                    'original_filename' => $active->original_filename,
                ],
            );
        }

        $file = $request->file('file');

        $batch = InvoiceImportBatch::create([
            'user_id'           => auth()->id(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path'         => $file->store('invoice-imports'),
            'status'            => 'queued',
            'phase'             => 'queued',
        ]);

        ParseInvoiceImportJob::dispatch($batch->id);

        return $this->successResponse([
            'batch_id' => $batch->id,
            'status'   => $batch->status,
        ], 'File diterima. Data sedang dibaca & diklasifikasi di latar belakang.', 202);
    }

    public function status(string $batch): JsonResponse
    {
        InvoiceImportBatch::failStale();

        $model = InvoiceImportBatch::find($batch);
        if (!$model) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }
        if ($denied = $this->guardBatch($model)) {
            return $denied;
        }

        return $this->successResponse($this->statusPayload($model->load('user.karyawan:id,nama_karyawan')));
    }

    /**
     * Batch yang sedang memblokir upload baru (guard di store()), kalau ada.
     * Dipakai FE untuk menemukan proaktif batch yang menggantung tanpa perlu
     * tahu UUID-nya lebih dulu — sebelumnya cuma bisa lewat teks pesan 409.
     */
    public function active(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        InvoiceImportBatch::failStale();

        $model = InvoiceImportBatch::whereNotIn('status', ['completed', 'failed'])
            ->orderByDesc('created_at')
            ->with('user.karyawan:id,nama_karyawan')
            ->first();

        return $this->successResponse($model ? $this->statusPayload($model) : null);
    }

    /** Baris yang butuh keputusan user (paginated). */
    public function review(Request $request, string $batch): JsonResponse
    {
        $model = InvoiceImportBatch::find($batch);
        if (!$model) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }
        if ($denied = $this->guardBatch($model)) {
            return $denied;
        }

        $perPage        = min(100, max(5, (int) $request->query('per_page', 20)));
        $classification = $request->query('classification', 'REVIEW_REQUIRED');
        $reviewStatus   = $request->query('review_status');
        $search         = trim((string) $request->query('search', ''));

        $query = InvoiceImportGroup::where('batch_id', $model->id)
            ->when($classification !== 'ALL', fn($q) => $q->where('classification', $classification))
            ->when($reviewStatus, fn($q) => $q->where('review_status', $reviewStatus))
            ->when($search !== '', fn($q) => $q->where(function ($q2) use ($search) {
                $q2->where('nama_klien', 'like', "%{$search}%")
                    ->orWhere('kode_resto', 'like', "%{$search}%")
                    ->orWhere('no_invoice', 'like', "%{$search}%");
            }))
            ->orderBy('tanggal_invoice')
            ->orderBy('id');

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

        $paginator->getCollection()->transform(fn(InvoiceImportGroup $g) => [
            'id'                  => $g->id,
            'tipe_invoice'        => $g->tipe_invoice,
            'nama_klien'          => $g->nama_klien,
            'kode_resto'          => $g->kode_resto,
            'tanggal_invoice'     => optional($g->tanggal_invoice)->toDateString(),
            'tanggal_jatuh_tempo' => optional($g->tanggal_jatuh_tempo)->toDateString(),
            'no_surat_jalan'      => $g->no_surat_jalan,
            'item_count'          => $g->item_count,
            'first_line'          => $g->first_line,
            'invoice_id'          => $g->invoice_id,
            'no_invoice'          => $g->no_invoice,
            'classification'      => $g->classification,
            'reason'              => $g->reason,
            'risk_flags'          => $g->risk_flags ?? [],
            'header_diff'         => $g->header_diff ?? [],
            'total_lama'          => (float) $g->total_lama,
            'total_baru'          => (float) $g->total_baru,
            'selisih'             => (float) $g->selisih,
            'adjustment_type'     => $g->adjustment_type,
            'review_status'       => $g->review_status,
            'review_message'      => $g->review_message,
            'koreksi_id'          => $g->koreksi_id,
            'apply_status'        => $g->apply_status,
            'apply_message'       => $g->apply_message,
        ]);

        return $this->paginatedResponse($paginator);
    }

    /** Tulis hanya grup NEW_INVOICE & SAFE_UPDATE. */
    public function applySafe(string $batch): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $model = InvoiceImportBatch::find($batch);
        if (!$model) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }
        if (!$model->isAwaitingReview()) {
            return $this->errorResponse('Batch ini tidak dalam status menunggu keputusan, jadi tidak bisa diproses lagi.', 422);
        }

        $total = $this->service->beginApply($model);

        if ($total === 0) {
            $this->service->finalizeApply($model);

            return $this->successResponse($this->statusPayload($model->fresh()), 'Tidak ada data aman yang perlu diproses.');
        }

        ProcessInvoiceImportChunkJob::dispatch($model->id);

        return $this->successResponse([
            'batch_id'      => $model->id,
            'applied_total' => $total,
        ], "Memproses {$total} invoice aman di latar belakang.", 202);
    }

    /**
     * Batalkan import. Dua jalur:
     *
     *  - awaiting_review: tidak ada job yang aktif jalan (menunggu keputusan user) →
     *    batal LANGSUNG (sinkron), staging dihapus, upload baru bisa lanjut segera.
     *  - queued/parsing/classifying: job MASIH aktif jalan, langsung menghapus staging
     *    dari sini berisiko race (job bisa menimpa balik status atau menulis groups
     *    dari file yang sudah dihapus). Jadi cuma menitip flag "batal diminta" —
     *    ParseInvoiceImportJob/ClassifyInvoiceImportJob & InvoiceImportService yang
     *    sedang jalan akan mengecek flag ini di boundary chunk (tiap 100 baris/grup)
     *    dan membersihkan diri sendiri begitu sadar. FE tetap polling status sampai
     *    batch berhenti di failed/phase=canceled.
     *  - processing: sudah mulai menulis invoice sungguhan — TIDAK boleh diinterupsi,
     *    ditolak seperti sebelumnya.
     */
    public function cancel(string $batch): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $model = InvoiceImportBatch::find($batch);
        if (!$model) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        if ($model->status === 'awaiting_review') {
            $model->cancel(sprintf('Dibatalkan oleh %s.', auth()->user()?->username ?? 'pengguna'));

            return $this->successResponse($this->statusPayload($model->fresh()), 'Import dibatalkan. Anda bisa mengunggah file baru sekarang.');
        }

        if (in_array($model->status, ['queued', 'parsing', 'classifying'], true)) {
            $model->requestCancel(auth()->user()?->username ?? 'pengguna');

            return $this->successResponse(
                $this->statusPayload($model->fresh()),
                'Permintaan pembatalan diterima — sedang dihentikan, mohon tunggu sebentar.',
                202,
            );
        }

        return $this->errorResponse(
            "Batch dengan status \"{$model->status}\" tidak bisa dibatalkan lewat sini. " .
            'Import sudah mulai menyimpan data, tidak bisa dihentikan lagi — tunggu sampai selesai.',
            422,
        );
    }

    /** Ajukan / abaikan kandidat penyesuaian untuk baris REVIEW_REQUIRED. */
    public function submitAdjustments(Request $request, string $batch): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $model = InvoiceImportBatch::find($batch);
        if (!$model) {
            return $this->notFoundResponse('Batch import tidak ditemukan');
        }

        $validated = $request->validate([
            'decisions'             => ['required', 'array', 'min:1'],
            'decisions.*.group_id'  => ['required', 'integer'],
            'decisions.*.action'    => ['required', 'in:submit,dismiss'],
            'decisions.*.alasan'    => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->submitAdjustments($model, $validated['decisions'], auth()->id());

        return $this->successResponse($result, sprintf(
            '%d penyesuaian diajukan, %d diabaikan, %d gagal.',
            $result['submitted'], $result['dismissed'], $result['failed'],
        ));
    }

    // ─── Helper ───────────────────────────────────────────────────────

    private function statusPayload(InvoiceImportBatch $b): array
    {
        // ETA dihitung dari pasangan processed/total & timestamp mulai yang
        // relevan dengan fase yang sedang berjalan — parsing & classifying
        // masih berbagi started_at yang sama (satu tahap kontinu), sementara
        // apply punya applied_at sendiri karena baru mulai setelah user klik
        // "Proses Data Aman", bisa jauh setelah parsing+classifying selesai.
        [$etaProcessed, $etaTotal, $etaStart] = match (true) {
            in_array($b->phase, ['parsing_file', 'parsing_rows', 'parsed'], true) => [$b->parsed_rows, $b->total_rows, $b->started_at],
            $b->phase === 'classifying' => [$b->classified_groups, $b->total_groups, $b->started_at],
            $b->phase === 'applying' => [$b->applied_processed, $b->applied_total, $b->applied_at],
            default => [0, 0, null],
        };
        $eta = ImportEtaCalculator::compute($etaStart, $etaProcessed, $etaTotal);

        // Dipakai FE menampilkan/menonaktifkan tombol Batalkan — logic SAMA persis dengan
        // gate di cancel(): awaiting_review (sinkron) & queued/parsing/classifying (optimis)
        // boleh, processing (sudah mulai menulis invoice sungguhan) tidak boleh.
        $cancelable = in_array($b->status, ['awaiting_review', 'queued', 'parsing', 'classifying'], true);

        return [
            'batch_id'               => $b->id,
            'status'                 => $b->status,
            'phase'                  => $b->phase,
            'cancelable'             => $cancelable,
            'message'                => $b->message,
            'original_filename'      => $b->original_filename,
            'user_id'                => $b->user_id,
            'uploaded_by'            => $b->user?->karyawan?->nama_karyawan ?? $b->user?->username,
            'created_at'             => optional($b->created_at)->toIso8601String(),
            'started_at'             => optional($b->started_at)->toIso8601String(),
            'classified_at'          => optional($b->classified_at)->toIso8601String(),
            'applied_at'             => optional($b->applied_at)->toIso8601String(),
            'finished_at'            => optional($b->finished_at)->toIso8601String(),
            'elapsed_seconds'        => $eta['elapsed_seconds'],
            'estimated_remaining_seconds' => $eta['estimated_remaining_seconds'],
            'estimated_completion_at'     => $eta['estimated_completion_at'],
            'total_rows'             => $b->total_rows,
            'parsed_rows'            => $b->parsed_rows,
            'total_groups'           => $b->total_groups,
            'classified_groups'      => $b->classified_groups,
            'cnt_new'                => $b->cnt_new,
            'cnt_unchanged'          => $b->cnt_unchanged,
            'cnt_safe_update'        => $b->cnt_safe_update,
            'cnt_review_required'    => $b->cnt_review_required,
            'cnt_rejected'           => $b->cnt_rejected,
            'cnt_cn_candidate'       => $b->cnt_cn_candidate,
            'cnt_dn_candidate'       => $b->cnt_dn_candidate,
            'cnt_metadata_candidate' => $b->cnt_metadata_candidate,
            'applied_total'          => $b->applied_total,
            'applied_processed'      => $b->applied_processed,
            'applied_inserted'       => $b->applied_inserted,
            'applied_updated'        => $b->applied_updated,
            'applied_skipped'        => $b->applied_skipped,
            'applied_failed'         => $b->applied_failed,
            'adjustment_submitted'   => $b->adjustment_submitted,
            'adjustment_dismissed'   => $b->adjustment_dismissed,
            'pending_review'         => InvoiceImportGroup::where('batch_id', $b->id)
                ->where('classification', 'REVIEW_REQUIRED')
                ->where('review_status', 'PENDING')
                ->count(),
            'errors'                 => $b->errors ?? [],
        ];
    }

    private function guard(): ?JsonResponse
    {
        return RoleHelper::hasAnyRole(auth()->user(), self::ALLOWED_ROLES)
            ? null
            : $this->unauthorizedResponse();
    }

    /** Pemilik batch boleh melihat progressnya sendiri; selain itu ikut aturan role. */
    private function guardBatch(InvoiceImportBatch $batch): ?JsonResponse
    {
        $user = auth()->user();

        return ($batch->user_id === $user->id || RoleHelper::hasAnyRole($user, self::ALLOWED_ROLES))
            ? null
            : $this->unauthorizedResponse();
    }
}
