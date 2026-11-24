<?php

namespace App\Support;

use App\Models\Offer;
use App\Models\ProductBatch;
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

    /**
     * A preview is deliberately separate from a booked allocation. It is
     * recalculated from physical stock each time and does not reserve it.
     *
     * @return array{numbers: array, provisional: bool}
     */
    public static function forDocument(Offer $offer): array
    {
        if (! $offer->exists || in_array($offer->status, [Offer::STATUS_CANCELLED, Offer::STATUS_RESERVATION_EXPIRED], true)) {
            return ['numbers' => [], 'provisional' => false];
        }

        if ($offer->isCompleted() || $offer->completed_at !== null) {
            return ['numbers' => self::forOffer($offer), 'provisional' => false];
        }

        if (! $offer->isReservationActive()) {
            return ['numbers' => [], 'provisional' => false];
        }

        // Do not fall back to current stock for incomplete historic movements.
        if (StockMovement::query()
            ->where('reference_type', Offer::class)
            ->where('reference_id', $offer->id)
            ->where('type', 'out')
            ->exists()) {
            return ['numbers' => self::forOffer($offer), 'provisional' => false];
        }

        return ['numbers' => self::preview($offer), 'provisional' => true];
    }

    private static function preview(Offer $offer): array
    {
        // The fulfillment service consumes stock per original item in FIFO
        // order (receipt date, then ID). PDF sorting must not change that plan.
        $items = $offer->items->sortBy('id')->groupBy('product_id');
        $batches = ProductBatch::query()
            ->whereIn('product_id', $items->keys()->all())
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $numbers = [];
        foreach ($items as $productId => $positions) {
            $stock = $batches->get($productId, collect());
            $required = $positions->sum(fn ($item) => self::quantityUnits($item->quantity));
            $available = $stock->sum(fn ($batch) => self::quantityUnits($batch->quantity));

            // A partial stock allocation is not a valid preview for this
            // product. Leave the batch line empty if it cannot be fulfilled.
            if ($required <= 0 || $required > $available) {
                continue;
            }

            $pool = $stock->filter(fn ($batch) => self::quantityUnits($batch->quantity) > 0)->values();
            $index = 0;
            $remainingInBatch = $pool->isEmpty() ? 0 : self::quantityUnits($pool[0]->quantity);

            foreach ($positions as $item) {
                $remaining = self::quantityUnits($item->quantity);
                $allocations = [];
                while ($remaining > 0 && $index < $pool->count()) {
                    $batch = $pool[$index];
                    $take = min($remaining, $remainingInBatch);
                    $allocations[] = [
                        'batch_id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'received_at' => $batch->received_at?->toDateString(),
                        'quantity' => $take / 1000,
                    ];
                    $remaining -= $take;
                    $remainingInBatch -= $take;
                    if ($remainingInBatch === 0) {
                        $index++;
                        $remainingInBatch = $index < $pool->count()
                            ? self::quantityUnits($pool[$index]->quantity)
                            : 0;
                    }
                }

                $number = $remaining === 0 ? self::latest($allocations) : null;
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
