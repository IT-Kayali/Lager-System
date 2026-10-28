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

    public function getRouteKey(): string
    {
        return \Illuminate\Support\Str::slug(
            (string) $this->name
        );
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $routeValue = (string) $value;

        // Eindeutige URL aus dem Namen ermitteln.
        $matching = $this->newQuery()
            ->get(['id', 'name'])
            ->first(
                fn (self $record) =>
                    \Illuminate\Support\Str::slug(
                        (string) $record->name
                    ) === $routeValue
            );

        if ($matching !== null) {
            return $this->newQuery()
                ->findOrFail($matching->getKey());
        }

        // Ältere direkte Namenslinks.
        $matched = $this->newQuery()
            ->where('name', $routeValue)
            ->first();

        if ($matched !== null) {
            return $matched;
        }

        // Alte lesbare Kategorie-Links unterstützen.
        $legacy = str_replace('-', ' ', $routeValue);

        if ($legacy !== $routeValue) {
            $matched = $this->newQuery()
                ->where('name', $legacy)
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        // Alte numerische URLs ohne Namenskollision.
        if (ctype_digit($routeValue)) {
            return $this->newQuery()
                ->whereKey($routeValue)
                ->firstOrFail();
        }

        return $this->newQuery()
            ->where('name', $routeValue)
            ->firstOrFail();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product', 'product_category_id', 'product_id')
            ->withTimestamps();
    }
}
