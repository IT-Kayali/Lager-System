<?php

namespace App\Http\Controllers;

use App\Models\BranchWithdrawal;
use App\Models\Offer;
use App\Models\WarehouseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WarehouseNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->warehouseUser($request);

        $query = WarehouseNotification::query()->unreadFor($user);

        $items = (clone $query)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (WarehouseNotification $notification): array => $this->payload($notification))
            ->values();

        return response()->json([
            'unread_count' => (clone $query)->count(),
            'items' => $items,
        ]);
    }

    public function open(Request $request, WarehouseNotification $notification): RedirectResponse
    {
        $user = $this->warehouseUser($request);

        abort_unless($notification->user_id === $user->id, 403, 'Diese Benachrichtigung gehört nicht zu diesem Benutzer.');
        abort_unless($notification->dismissed_at === null, 404, 'Diese Benachrichtigung ist nicht mehr aktiv.');

        $notification->markAsRead();

        if (in_array($notification->type, [
            WarehouseNotification::TYPE_OFFER,
            WarehouseNotification::TYPE_OFFER_NOTE,
        ], true)) {
            $offer = Offer::query()->find($notification->subject_id);

            if (! $offer || ! in_array($offer->status, [
                Offer::STATUS_IN_PROGRESS,
                Offer::STATUS_READY,
                Offer::STATUS_COMPLETED,
            ], true)) {
                return redirect()
                    ->route('warehouse.offers.index')
                    ->with('error', 'Der Auftrag ist nicht mehr in der Lageransicht verfügbar.');
            }

            if ($notification->type === WarehouseNotification::TYPE_OFFER_NOTE) {
                return redirect()->to(route('warehouse.offers.show', $offer) . '#warehouse-internal-notes');
            }

            return redirect()->route('warehouse.offers.show', $offer);
        }

        if ($notification->type === WarehouseNotification::TYPE_BRANCH_WITHDRAWAL) {
            $withdrawal = BranchWithdrawal::query()->find($notification->subject_id);

            if (! $withdrawal || ! in_array($withdrawal->status, [
                BranchWithdrawal::STATUS_IN_PROGRESS,
                BranchWithdrawal::STATUS_ISSUED,
            ], true)) {
                return redirect()
                    ->route('branch-withdrawals.index')
                    ->with('error', 'Der Filialausgang ist nicht mehr in der Lageransicht verfügbar.');
            }

            return redirect()->route('branch-withdrawals.index', [
                'search' => $withdrawal->withdrawal_number,
                'search_field' => 'number',
                'exact' => 1,
            ]);
        }

        return redirect()->route('dashboard');
    }

    private function warehouseUser(Request $request): \App\Models\User
    {
        $user = $request->user();

        abort_unless($user?->is_active && $user->isWarehouse(), 403, 'Benachrichtigungen sind nur für Lager-Mitarbeiter verfügbar.');

        return $user;
    }

    private function payload(WarehouseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'time' => $notification->created_at?->format('d.m.Y H:i'),
            'open_url' => route('warehouse.notifications.open', $notification),
        ];
    }
}
