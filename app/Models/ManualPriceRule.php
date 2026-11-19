<?php

namespace App\Models;

use App\Support\GermanNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPriceRule extends Model
{
    protected $fillable = [
        'product_id',
        'customer_group_id',
        'min_quantity',
        'max_quantity',
        'price',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'max_quantity' => 'decimal:3',
            'price' => 'decimal:5',
        ];
    }

    public function rangeLabel(string $unitLabel): string
    {
        $customLabel = trim((string) $this->label);

        if ($customLabel !== '') {
            return $customLabel;
        }

        $min = GermanNumber::format($this->min_quantity);
        $max = $this->max_quantity !== null
            ? GermanNumber::format($this->max_quantity)
            : null;

        return $max !== null
            ? "{$min}–{$max} {$unitLabel}"
            : "ab {$min} {$unitLabel}";
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}
