<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Product extends Model
{
    protected $fillable = [
        'product_code',
        'name',
        'manufacturer_designation',
        'serial_number',
        'unit',
        'supplier',
        'supplier_id',
        'minimum_stock',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (! $product->product_code) {
                $lastCode = Product::query()
                    ->where('product_code', 'like', 'PRD-%')
                    ->orderByDesc('id')
                    ->value('product_code');

                $lastNumber = $lastCode
                    ? (int) str_replace('PRD-', '', $lastCode)
                    : 1000;

                $product->product_code = 'PRD-' . ($lastNumber + 1);
            }
        });

        static::created(function (Product $product): void {
            if (Schema::hasTable('product_price_tiers') && Schema::hasTable('customer_groups')) {
                ProductPriceTier::ensureForProduct($product);
            }
        });
    }

    public function scopeNaturalNameOrder($query, string $direction = 'asc')
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $directionSql = strtoupper($direction);
        $driver = $query->getConnection()->getDriverName();
        $table = $query->getModel()->getTable();
        $nameColumn = $table . '.name';
        $idColumn = $table . '.id';

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $query
                ->orderByRaw("CASE WHEN {$nameColumn} REGEXP '^[0-9]' THEN 0 ELSE 1 END {$directionSql}")
                ->orderByRaw("CASE WHEN {$nameColumn} REGEXP '^[0-9]' THEN CAST({$nameColumn} AS UNSIGNED) ELSE 0 END {$directionSql}")
                ->orderByRaw("LOWER({$nameColumn}) {$directionSql}")
                ->orderBy($idColumn, $direction);
        }

        if ($driver === 'pgsql') {
            return $query
                ->orderByRaw("CASE WHEN {$nameColumn} ~ '^[0-9]' THEN 0 ELSE 1 END {$directionSql}")
                ->orderByRaw("CASE WHEN {$nameColumn} ~ '^[0-9]' THEN CAST(SUBSTRING({$nameColumn} FROM '^[0-9]+') AS BIGINT) ELSE 0 END {$directionSql}")
                ->orderByRaw("LOWER({$nameColumn}) {$directionSql}")
                ->orderBy($idColumn, $direction);
        }

        return $query
            ->orderByRaw("CASE WHEN {$nameColumn} GLOB '[0-9]*' THEN 0 ELSE 1 END {$directionSql}")
            ->orderByRaw("CASE WHEN {$nameColumn} GLOB '[0-9]*' THEN CAST({$nameColumn} AS INTEGER) ELSE 0 END {$directionSql}")
            ->orderByRaw("LOWER({$nameColumn}) {$directionSql}")
            ->orderBy($idColumn, $direction);
    }

    /**
     * Der sichtbare Produktname ist der URL-Parameter.
     * Beispiel: /products/1 statt /products/666.
     */
    public function getRouteKey(): string
    {
        return (string) $this->name;
    }

    /**
     * Namen haben Vorrang vor numerischen Datenbank-IDs.
     * Alte ID-Links bleiben ohne Namenskollision erreichbar.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $routeValue = (string) $value;

        $product = $this->newQuery()
            ->where('name', $routeValue)
            ->first();

        if ($product !== null) {
            return $product;
        }

        if (ctype_digit($routeValue)) {
            return $this->newQuery()
                ->whereKey($routeValue)
                ->firstOrFail();
        }

        return $this->newQuery()
            ->where('name', $routeValue)
            ->firstOrFail();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'category_product', 'product_id', 'product_category_id')
            ->withTimestamps();
    }

    public function supplierRecord(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class);
    }

    public function manualPriceRules(): HasMany
    {
        return $this->hasMany(ManualPriceRule::class);
    }

    public function offerItems(): HasMany
    {
        return $this->hasMany(OfferItem::class);
    }

    public function usesPriceTiers(): bool
    {
        if (! Schema::hasColumn('product_categories', 'price_tiers_enabled')) {
            return true;
        }

        $categories = $this->relationLoaded('categories')
            ? $this->categories
            : $this->categories()->get(['product_categories.id', 'price_tiers_enabled']);

        if ($categories->isEmpty()) {
            return true;
        }

        return $categories->contains(fn (ProductCategory $category) => $category->price_tiers_enabled);
    }

    public function unitLabel(string $locale = 'de'): string
    {
        $labels = $locale === 'en'
            ? [
                'gram' => 'Gram',
                'liter' => 'Liter',
                'piece' => 'Piece',
            ]
            : [
                'gram' => 'Gramm',
                'liter' => 'Liter',
                'piece' => 'Stück',
            ];

        return $labels[$this->unit] ?? ($this->unit ?: '—');
    }

    public function getTotalStockAttribute(): float
    {
        return (float) $this->batches()->sum('quantity');
    }

    public function getReservedStockAttribute(): float
    {
        $reserved = 0.0;

        if (Schema::hasTable('offers') && Schema::hasTable('offer_items')) {
            $reserved += (float) DB::table('offer_items')
                ->join('offers', 'offers.id', '=', 'offer_items.offer_id')
                ->where('offer_items.product_id', $this->id)
                ->whereIn('offers.status', Offer::RESERVING_STATUSES)
                ->sum('offer_items.quantity');
        }

        if (Schema::hasTable('branch_withdrawals') && Schema::hasTable('branch_withdrawal_items')) {
            $reserved += (float) DB::table('branch_withdrawal_items')
                ->join('branch_withdrawals', 'branch_withdrawals.id', '=', 'branch_withdrawal_items.branch_withdrawal_id')
                ->where('branch_withdrawal_items.product_id', $this->id)
                ->whereIn('branch_withdrawals.status', BranchWithdrawal::RESERVING_STATUSES)
                ->sum('branch_withdrawal_items.quantity');
        }

        return $reserved;
    }

    public function getAvailableStockAttribute(): float
    {
        return max(0, $this->total_stock - $this->reserved_stock);
    }

    public function getMaxReservableAttribute(): float
    {
        return max(0, $this->total_stock - (float) $this->minimum_stock - $this->reserved_stock);
    }

    public function getLowStockWarningThresholdAttribute(): float
    {
        $minimum = (float) $this->minimum_stock;

        if ($minimum <= 0) {
            return 0.0;
        }

        $percentage = ApplicationSetting::lowStockWarningPercentage();

        return $minimum + ($minimum * ($percentage / 100));
    }

    public function getStockStatusAttribute(): string
    {
        $available = (float) $this->available_stock;
        $minimum = (float) $this->minimum_stock;

        if ($minimum <= 0) {
            return 'ok';
        }

        if ($available <= $minimum) {
            return 'critical';
        }

        if ($available <= $this->low_stock_warning_threshold) {
            return 'low';
        }

        return 'ok';
    }
}
