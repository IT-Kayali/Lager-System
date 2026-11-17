<?php

namespace App\Services;

use App\Models\BranchWithdrawal;
use App\Models\Offer;
use App\Models\OfferInternalNote;
use App\Models\User;
use App\Models\WarehouseNotification;
use Illuminate\Support\Str;

class WarehouseNotificationService
{
    public function notifyOfferHandoff(Offer $offer): void
    {
        $offer->loadMissing('customer');

        $customer = trim((string) $offer->customer?->company_name);
        $message = $customer !== ''
            ? 'Kunde: ' . $customer
            : 'Neuer Auftrag wurde an das Lager übergeben.';

        $this->createForWarehouseUsers(
            WarehouseNotification::TYPE_OFFER,
            $offer->id,
            'Neues Angebot ' . $offer->offer_number,
            $message
        );
    }

    public function notifyBranchWithdrawalHandoff(BranchWithdrawal $withdrawal): void
    {
        $branch = trim((string) $withdrawal->branch_name);

        $this->createForWarehouseUsers(
            WarehouseNotification::TYPE_BRANCH_WITHDRAWAL,
            $withdrawal->id,
            'Neuer Filialausgang ' . $withdrawal->withdrawal_number,
            $branch !== '' ? 'Filiale: ' . $branch : 'Neuer Filialauftrag wurde an das Lager übergeben.'
        );
    }

    /**
     * Benachrichtigt nur über Notizen zu Aufträgen, die das Lager bereits sehen darf.
     * Der Verfasser bekommt keine Benachrichtigung über seinen eigenen Eintrag.
     */
    public function notifyOfferInternalNote(Offer $offer, OfferInternalNote $note): void
    {
        if (! in_array($offer->status, [
            Offer::STATUS_IN_PROGRESS,
            Offer::STATUS_READY,
            Offer::STATUS_COMPLETED,
        ], true)) {
            return;
        }

        $author = trim((string) $note->user?->name);
        $excerpt = Str::limit(trim(preg_replace('/\s+/u', ' ', $note->note) ?? ''), 160);

        $this->createForWarehouseUsers(
            WarehouseNotification::TYPE_OFFER_NOTE,
            $offer->id,
            'Neue interne Notiz · Bestellung ' . $offer->offer_number,
            ($author !== '' ? $author . ': ' : '') . $excerpt,
            $note->user_id
        );
    }

    public function dismissOffer(Offer|int $offer): void
    {
        $offerId = $offer instanceof Offer ? $offer->id : $offer;

        foreach ([WarehouseNotification::TYPE_OFFER, WarehouseNotification::TYPE_OFFER_NOTE] as $type) {
            $this->dismissSubject($type, $offerId);
        }
    }

    public function dismissBranchWithdrawal(BranchWithdrawal|int $withdrawal): void
    {
        $this->dismissSubject(
            WarehouseNotification::TYPE_BRANCH_WITHDRAWAL,
            $withdrawal instanceof BranchWithdrawal ? $withdrawal->id : $withdrawal
        );
    }

    private function createForWarehouseUsers(
        string $type,
        int $subjectId,
        string $title,
        string $message,
        ?int $excludeUserId = null
    ): void
    {
        $warehouseUserIds = User::query()
            ->where('role', User::ROLE_WAREHOUSE)
            ->where('is_active', true)
            ->when($excludeUserId !== null, fn ($query) => $query->where('id', '!=', $excludeUserId))
            ->pluck('id');

        if ($warehouseUserIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $warehouseUserIds
            ->map(fn (int $userId): array => [
                'user_id' => $userId,
                'type' => $type,
                'subject_id' => $subjectId,
                'title' => $title,
                'message' => $message,
                'read_at' => null,
                'dismissed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        WarehouseNotification::query()->insert($rows);
    }

    private function dismissSubject(string $type, int $subjectId): void
    {
        WarehouseNotification::query()
            ->where('type', $type)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->update([
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
