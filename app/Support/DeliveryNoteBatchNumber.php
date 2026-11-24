<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\StockMovement;

final class DeliveryNoteBatchNumber
{
    /**
     * Resolve the newest actual batch per offer item, not the newest stock.
     * Historical outbound movements identify the offer and product only.
     * Repeated product rows are shown only when the movement quantities
     * identify the same newest batch in every possible contiguous allocation.
     */
    public static function forOffer(Offer $offer): array
    {
        if (! $offer->exists) {
            return [];
        }

        $movements = StockMovement::query()
            ->where('reference_type', Offer::class)
            ->where('reference_id', $offer->id)
            ->where('type', 'out')
            ->where('quantity', '<', 0)
            ->with('batch')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $numbers = [];
        foreach ($offer->items->groupBy('product_id') as $productId => $items) {
            $allocations = [];
            foreach ($movements->get($productId, []) as $movement) {
                $batch = $movement->batch;
                if ($batch === null || (int) $batch->product_id !== (int) $productId) {
                    continue 2;
                }

                $allocations[] = [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'received_at' => $batch->received_at?->toDateString(),
                    'quantity' => abs((float) $movement->quantity),
                ];
            }

            $expected = $items->sum(fn ($item) => self::quantityUnits($item->quantity));
            $actual = array_sum(array_map(fn ($allocation) => self::quantityUnits($allocation['quantity']), $allocations));
            if ($expected !== $actual || $allocations === [] || self::latest($allocations) === null) {
                continue;
            }

            foreach ($items as $item) {
                $number = $items->count() === 1
                    ? self::latest($allocations)
                    : self::unambiguousLatest($allocations, self::quantityUnits($item->quantity));
                if ($number !== null) {
                    $numbers[$item->id] = $number;
                }
            }
        }

        return $numbers;
    }

    private static function quantityUnits(mixed $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }

    /**
     * FIFO writes each item's deductions consecutively. Because old records
     * have no item ID, any matching contiguous block is a possible allocation.
     * Never guess when candidate blocks disagree.
     */
    private static function unambiguousLatest(array $allocations, int $quantity): ?string
    {
        if ($quantity <= 0) {
            return null;
        }

        $left = 0;
        $sum = 0;
        $number = null;
        foreach ($allocations as $right => $allocation) {
            $sum += self::quantityUnits($allocation['quantity']);
            while ($sum > $quantity && $left <= $right) {
                $sum -= self::quantityUnits($allocations[$left]['quantity']);
                $left++;
            }
            if ($sum !== $quantity || $left > $right) {
                continue;
            }

            $candidate = self::latest(array_slice($allocations, $left, $right - $left + 1));
            if ($candidate === null || ($number !== null && $number !== $candidate)) {
                return null;
            }

            $number = $candidate;
        }

        return $number;
    }

    /**
     * Receipt date, then internal batch ID, determines the newest used batch.
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
                return null;
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
