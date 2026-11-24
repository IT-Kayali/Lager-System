<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\StockMovement;

final class DeliveryNoteBatchNumber
{
    /**
     * Only use actual outbound movements of this offer, never current stock.
     * Existing movement records identify the product, not the offer item.
     */
    public static function forOffer(Offer $offer): array
    {
        $movements = StockMovement::query()
            ->where('reference_type', Offer::class)
            ->where('reference_id', $offer->id)
            ->where('type', 'out')
            ->where('quantity', '<', 0)
            ->with('batch')
            ->get();

        $numbers = [];
        foreach ($movements->groupBy('product_id') as $productId => $rows) {
            $allocations = $rows->map(function (StockMovement $movement) {
                $batch = $movement->batch;
                if ($batch === null || (int) $batch->product_id !== (int) $movement->product_id) {
                    return [];
                }

                return [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'received_at' => $batch->received_at?->toDateString(),
                    'quantity' => abs((float) $movement->quantity),
                ];
            });
            $numbers[$productId] = self::latest($allocations);
        }

        return $numbers;
    }

    /**
     * Branch withdrawals store allocation snapshots, including receipt dates.
     * The receipt date, then the batch ID, determines the newest used batch.
     */
    public static function latest(iterable $allocations): ?string
    {
        $latest = null;
        $latestKey = null;

        foreach ($allocations as $allocation) {
            if (! is_array($allocation) || (float) ($allocation['quantity'] ?? 0) <= 0) {
                continue;
            }

            $number = trim((string) ($allocation['batch_number'] ?? ''));
            $date = (string) ($allocation['received_at'] ?? '');
            $id = (int) ($allocation['batch_id'] ?? 0);
            if ($number === '' || $id <= 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                continue;
            }

            $key = [$date, $id];
            if ($latestKey === null || $key > $latestKey) {
                $latestKey = $key;
                $latest = $number;
            }
        }

        return $latest;
    }
}
