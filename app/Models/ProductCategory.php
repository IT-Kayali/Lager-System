<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    protected $fillable = [
        'name',
        'priority',
        'slug',
        'description',
        'color',
        'is_active',
        'price_tiers_enabled',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'price_tiers_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductCategory $category): void {
            if (! $category->slug) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function getRouteName(): string
    {
        return preg_replace('/\s+/', '-', trim((string) $this->name));
    }

    /**
     * Use a stable URL identifier; names with spaces and hyphens can collide.
     */
    public function getRouteKey(): string
    {
        $slug = Str::slug((string) $this->name);

        return ($slug !== '' ? $slug : 'kategorie') . '--id-' . $this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $value = (string) $value;

        if (preg_match('/^.+--id-([1-9][0-9]*)$/D', $value, $matches) === 1) {
            return $this->whereKey($matches[1])->firstOrFail();
        }

        $nameFromUrl = str_replace('-', ' ', $value);

        return $this->where('name', $value)->first()
            ?? $this->where('name', $nameFromUrl)->first()
            ?? $this->whereKey($value)->firstOrFail();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product', 'product_category_id', 'product_id')
            ->withTimestamps();
    }
}