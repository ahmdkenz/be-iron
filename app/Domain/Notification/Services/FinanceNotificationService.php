<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Notifications\IronFinanceNotification;
use App\Models\EndingBalanceApKoreksi;
use App\Models\EndingBalanceKoreksi;
use App\Models\Invoice;
use App\Models\TagihanAp;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Titik masuk tunggal untuk memicu notifikasi aktivitas Finance. Setiap method
 * mewakili satu event nyata (submit/approve/reject/import/rekonsiliasi bank),
 * menentukan siapa penerimanya lewat FinanceNotificationRecipientResolver, lalu
 * mengirim IronFinanceNotification (database + broadcast, sinkron — lihat
 * catatan di kelas Notification-nya).
 */
class FinanceNotificationService
{
    public function __construct(private readonly FinanceNotificationRecipientResolver $recipients) {}

    // ─── Opening Balance AR ──────────────────────────────────────────────────

    public function obArSubmitted(Invoice $ob): void
    {
        $ob->loadMissing(['klienAr', 'submittedBy']);

        $this->dispatch(
            $this->recipients->approvers(),
            actorId: $ob->submitted_by,
            category: 'opening_balance_ar',
            severity: 'info',
            title: 'Opening Balance AR menunggu persetujuan',
            body: sprintf('%s mengajukan opening balance %s untuk klien %s.',
                $ob->submittedBy?->name ?? 'Seseorang', $ob->no_invoice, $ob->klienAr?->nama_klien ?? '-'),
            routeName: 'finance-opening-balance',
            entityType: 'opening_balance_ar',
            entityId: $ob->id,
            actorName: $ob->submittedBy?->name,
        );
    }

    public function obArApproved(Invoice $ob): void
    {
        $ob->loadMissing(['klienAr', 'approvedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($ob->submitted_by), $this->recipients->arPicFor($ob->klien_ar_id)]),
            actorId: $ob->approved_by,
            category: 'opening_balance_ar',
            severity: 'success',
            title: 'Opening Balance AR disetujui',
            body: sprintf('Opening balance %s untuk klien %s telah disetujui oleh %s.',
                $ob->no_invoice, $ob->klienAr?->nama_klien ?? '-', $ob->approvedBy?->name ?? '-'),
            routeName: 'finance-opening-balance',
            entityType: 'opening_balance_ar',
            entityId: $ob->id,
            actorName: $ob->approvedBy?->name,
        );
    }

    public function obArRejected(Invoice $ob, string $note): void
    {
        $ob->loadMissing(['klienAr', 'rejectedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($ob->submitted_by), $this->recipients->arPicFor($ob->klien_ar_id)]),
            actorId: $ob->rejected_by,
            category: 'opening_balance_ar',
            severity: 'warning',
            title: 'Opening Balance AR ditolak',
            body: sprintf('Opening balance %s untuk klien %s ditolak oleh %s. Catatan: %s',
                $ob->no_invoice, $ob->klienAr?->nama_klien ?? '-', $ob->rejectedBy?->name ?? '-', $note),
            routeName: 'finance-opening-balance',
            entityType: 'opening_balance_ar',
            entityId: $ob->id,
            actorName: $ob->rejectedBy?->name,
        );
    }

    // ─── Opening Balance AP ──────────────────────────────────────────────────

    public function obApSubmitted(TagihanAp $ob): void
    {
        $ob->loadMissing(['vendorAp', 'submittedBy']);

        $this->dispatch(
            $this->recipients->approvers(),
            actorId: $ob->submitted_by,
            category: 'opening_balance_ap',
            severity: 'info',
            title: 'Opening Balance AP menunggu persetujuan',
            body: sprintf('%s mengajukan opening balance %s untuk vendor %s.',
                $ob->submittedBy?->name ?? 'Seseorang', $ob->no_tagihan, $ob->vendorAp?->nama_vendor ?? '-'),
            routeName: 'ap-opening-balance-index',
            entityType: 'opening_balance_ap',
            entityId: $ob->id,
            actorName: $ob->submittedBy?->name,
        );
    }

    public function obApApproved(TagihanAp $ob): void
    {
        $ob->loadMissing(['vendorAp', 'approvedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($ob->submitted_by), $this->recipients->apPicFor($ob->vendor_ap_id)]),
            actorId: $ob->approved_by,
            category: 'opening_balance_ap',
            severity: 'success',
            title: 'Opening Balance AP disetujui',
            body: sprintf('Opening balance %s untuk vendor %s telah disetujui oleh %s.',
                $ob->no_tagihan, $ob->vendorAp?->nama_vendor ?? '-', $ob->approvedBy?->name ?? '-'),
            routeName: 'ap-opening-balance-index',
            entityType: 'opening_balance_ap',
            entityId: $ob->id,
            actorName: $ob->approvedBy?->name,
        );
    }

    public function obApRejected(TagihanAp $ob, string $note): void
    {
        $ob->loadMissing(['vendorAp', 'rejectedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($ob->submitted_by), $this->recipients->apPicFor($ob->vendor_ap_id)]),
            actorId: $ob->rejected_by,
            category: 'opening_balance_ap',
            severity: 'warning',
            title: 'Opening Balance AP ditolak',
            body: sprintf('Opening balance %s untuk vendor %s ditolak oleh %s. Catatan: %s',
                $ob->no_tagihan, $ob->vendorAp?->nama_vendor ?? '-', $ob->rejectedBy?->name ?? '-', $note),
            routeName: 'ap-opening-balance-index',
            entityType: 'opening_balance_ap',
            entityId: $ob->id,
            actorName: $ob->rejectedBy?->name,
        );
    }

    // ─── Koreksi Ending Balance AR (2 tahap: SPV lalu Manager) ──────────────

    public function ebKoreksiArSubmitted(EndingBalanceKoreksi $koreksi): void
    {
        $koreksi->loadMissing(['klienAr', 'submittedBy']);

        $this->dispatch(
            $this->recipients->spvApprovers(),
            actorId: $koreksi->submitted_by,
            category: 'ending_balance_koreksi_ar',
            severity: 'info',
            title: 'Koreksi Ending Balance AR menunggu persetujuan SPV',
            body: sprintf('%s mengajukan koreksi %s (%s) untuk klien %s.',
                $koreksi->submittedBy?->name ?? 'Seseorang', $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->klienAr?->nama_klien ?? '-'),
            routeName: 'finance-ending-balance',
            entityType: 'ending_balance_koreksi_ar',
            entityId: $koreksi->id,
            actorName: $koreksi->submittedBy?->name,
        );
    }

    public function ebKoreksiArNeedsManager(EndingBalanceKoreksi $koreksi): void
    {
        $koreksi->loadMissing(['klienAr', 'spv']);

        $this->dispatch(
            $this->recipients->approvers(),
            actorId: $koreksi->spv_id,
            category: 'ending_balance_koreksi_ar',
            severity: 'info',
            title: 'Koreksi Ending Balance AR menunggu persetujuan Manager',
            body: sprintf('SPV %s menyetujui koreksi %s (%s) untuk klien %s, menunggu persetujuan akhir.',
                $koreksi->spv?->name ?? '-', $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->klienAr?->nama_klien ?? '-'),
            routeName: 'finance-ending-balance',
            entityType: 'ending_balance_koreksi_ar',
            entityId: $koreksi->id,
            actorName: $koreksi->spv?->name,
        );
    }

    public function ebKoreksiArApproved(EndingBalanceKoreksi $koreksi): void
    {
        $koreksi->loadMissing(['klienAr', 'manager']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($koreksi->submitted_by), $this->recipients->arPicFor($koreksi->klien_ar_id)]),
            actorId: $koreksi->manager_id,
            category: 'ending_balance_koreksi_ar',
            severity: 'success',
            title: 'Koreksi Ending Balance AR disetujui',
            body: sprintf('Koreksi %s (%s) untuk klien %s telah disetujui oleh %s.',
                $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->klienAr?->nama_klien ?? '-', $koreksi->manager?->name ?? '-'),
            routeName: 'finance-ending-balance',
            entityType: 'ending_balance_koreksi_ar',
            entityId: $koreksi->id,
            actorName: $koreksi->manager?->name,
        );
    }

    public function ebKoreksiArRejected(EndingBalanceKoreksi $koreksi, string $note, string $stage): void
    {
        $koreksi->loadMissing(['klienAr', 'spv', 'manager']);
        $actor = $stage === 'spv' ? $koreksi->spv : $koreksi->manager;

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($koreksi->submitted_by), $this->recipients->arPicFor($koreksi->klien_ar_id)]),
            actorId: $stage === 'spv' ? $koreksi->spv_id : $koreksi->manager_id,
            category: 'ending_balance_koreksi_ar',
            severity: 'warning',
            title: 'Koreksi Ending Balance AR ditolak',
            body: sprintf('Koreksi %s (%s) untuk klien %s ditolak oleh %s. Catatan: %s',
                $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->klienAr?->nama_klien ?? '-', $actor?->name ?? '-', $note),
            routeName: 'finance-ending-balance',
            entityType: 'ending_balance_koreksi_ar',
            entityId: $koreksi->id,
            actorName: $actor?->name,
        );
    }

    // ─── Koreksi Ending Balance AP (1 tahap) ─────────────────────────────────

    public function ebKoreksiApSubmitted(EndingBalanceApKoreksi $koreksi): void
    {
        $koreksi->loadMissing(['vendorAp', 'submittedBy']);

        $this->dispatch(
            $this->recipients->approvers(),
            actorId: $koreksi->submitted_by,
            category: 'ending_balance_koreksi_ap',
            severity: 'info',
            title: 'Koreksi Ending Balance AP menunggu persetujuan',
            body: sprintf('%s mengajukan koreksi %s (%s) untuk vendor %s.',
                $koreksi->submittedBy?->name ?? 'Seseorang', $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->vendorAp?->nama_vendor ?? '-'),
            routeName: 'ap-ending-balance-index',
            entityType: 'ending_balance_koreksi_ap',
            entityId: $koreksi->id,
            actorName: $koreksi->submittedBy?->name,
        );
    }

    public function ebKoreksiApApproved(EndingBalanceApKoreksi $koreksi): void
    {
        $koreksi->loadMissing(['vendorAp', 'approvedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($koreksi->submitted_by), $this->recipients->apPicFor($koreksi->vendor_ap_id)]),
            actorId: $koreksi->approved_by,
            category: 'ending_balance_koreksi_ap',
            severity: 'success',
            title: 'Koreksi Ending Balance AP disetujui',
            body: sprintf('Koreksi %s (%s) untuk vendor %s telah disetujui oleh %s.',
                $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->vendorAp?->nama_vendor ?? '-', $koreksi->approvedBy?->name ?? '-'),
            routeName: 'ap-ending-balance-index',
            entityType: 'ending_balance_koreksi_ap',
            entityId: $koreksi->id,
            actorName: $koreksi->approvedBy?->name,
        );
    }

    public function ebKoreksiApRejected(EndingBalanceApKoreksi $koreksi, string $note): void
    {
        $koreksi->loadMissing(['vendorAp', 'approvedBy']);

        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($koreksi->submitted_by), $this->recipients->apPicFor($koreksi->vendor_ap_id)]),
            actorId: $koreksi->approved_by,
            category: 'ending_balance_koreksi_ap',
            severity: 'warning',
            title: 'Koreksi Ending Balance AP ditolak',
            body: sprintf('Koreksi %s (%s) untuk vendor %s ditolak oleh %s. Catatan: %s',
                $koreksi->tipe, $koreksi->no_dokumen ?? ('#' . $koreksi->id), $koreksi->vendorAp?->nama_vendor ?? '-', $koreksi->approvedBy?->name ?? '-', $note),
            routeName: 'ap-ending-balance-index',
            entityType: 'ending_balance_koreksi_ap',
            entityId: $koreksi->id,
            actorName: $koreksi->approvedBy?->name,
        );
    }

    // ─── Import (Invoice, Master, Rekening Koran) ────────────────────────────

    public function importCompleted(string $type, ?int $userId, string $label, string $summary): void
    {
        $this->dispatch(
            $this->recipients->user($userId),
            actorId: null,
            category: 'import_' . $type,
            severity: 'success',
            title: $label . ' selesai',
            body: $summary,
            routeName: $this->importRouteName($type),
        );
    }

    public function importFailed(string $type, ?int $userId, string $label, string $message): void
    {
        $this->dispatch(
            $this->recipients->user($userId),
            actorId: null,
            category: 'import_' . $type,
            severity: 'error',
            title: $label . ' gagal',
            body: $message,
            routeName: $this->importRouteName($type),
        );
    }

    public function bankImportNeedsConfirmation(?int $userId, string $message): void
    {
        $this->dispatch(
            $this->recipients->merge([$this->recipients->user($userId), $this->recipients->approvers()]),
            actorId: null,
            category: 'import_bank',
            severity: 'warning',
            title: 'Import Rekening Koran perlu konfirmasi',
            body: $message,
            routeName: 'finance-laporan-rekening-koran',
        );
    }

    private function importRouteName(string $type): string
    {
        return match ($type) {
            'invoice' => 'finance-invoice-index',
            'master'  => 'master-unified-import',
            'bank'    => 'finance-laporan-rekening-koran',
            default   => 'finance-laporan',
        };
    }

    // ─── Rekonsiliasi Bank ────────────────────────────────────────────────

    /**
     * @param  int[]  $vendorApIds  Bisa lebih dari satu (mis. Payment Voucher AP multi vendor).
     */
    public function bankReconciliationAction(
        string $action,
        string $title,
        string $body,
        ?int $klienArId = null,
        array $vendorApIds = [],
    ): void {
        $groups = [$this->recipients->approvers(), $this->recipients->arPicFor($klienArId)];
        foreach ($vendorApIds as $vendorApId) {
            $groups[] = $this->recipients->apPicFor($vendorApId);
        }

        $this->dispatch(
            $this->recipients->merge($groups),
            actorId: auth()->id(),
            category: 'rekonsiliasi_bank_' . $action,
            severity: 'info',
            title: $title,
            body: $body,
            routeName: 'finance-rekonsiliasi-bank',
            entityType: 'bank_statement_action',
        );
    }

    // ─── Internal ─────────────────────────────────────────────────────────

    private function dispatch(
        Collection $recipients,
        ?int $actorId,
        string $category,
        string $severity,
        string $title,
        string $body,
        ?string $routeName = null,
        array $routeParams = [],
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $actorName = null,
    ): void {
        $recipients = $recipients
            ->reject(fn(User $user) => $actorId !== null && (int) $user->id === (int) $actorId)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new IronFinanceNotification(
            category: $category,
            severity: $severity,
            title: $title,
            body: $body,
            routeName: $routeName,
            routeParams: $routeParams,
            entityType: $entityType,
            entityId: $entityId,
            actorName: $actorName,
        ));
    }
}
