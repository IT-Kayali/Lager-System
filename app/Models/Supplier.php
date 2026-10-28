<?php

namespace App\Models;

use App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'supplier_number',
        'company_name',
        'contact_person',
        'email',
        'phone_country_code',
        'phone',
        'whatsapp_country_code',
        'whatsapp',
        'street',
        'house_number',
        'postal_code',
        'city',
        'country',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Supplier $supplier): void {
            if (! $supplier->supplier_number) {
                $lastNumber = Supplier::query()
                    ->where('supplier_number', 'like', 'LFR-%')
                    ->orderByDesc('id')
                    ->value('supplier_number');

                $lastSequence = $lastNumber
                    ? (int) str_replace('LFR-', '', $lastNumber)
                    : 1000;

                $supplier->supplier_number = 'LFR-' . ($lastSequence + 1);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function fullAddress(): string
    {
        return collect([
            trim(($this->street ?? '') . ' ' . ($this->house_number ?? '')),
            trim(($this->postal_code ?? '') . ' ' . ($this->city ?? '')),
            $this->country,
        ])->filter()->implode("\n");
    }
    public function getRouteName(): string
    {
        $name = $this->company_name ?: $this->supplier_number ?: (string) $this->id;

        return preg_replace('/\s+/', '-', trim((string) $name));
    }

    public function getRouteKey(): string
    {
        return \Illuminate\Support\Str::slug(
            (string) $this->company_name
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
            ->get(['id', 'company_name'])
            ->first(
                fn (self $record) =>
                    \Illuminate\Support\Str::slug(
                        (string) $record->company_name
                    ) === $routeValue
            );

        if ($matching !== null) {
            return $this->newQuery()
                ->findOrFail($matching->getKey());
        }

        // Ältere direkte Namenslinks.
        $matched = $this->newQuery()
            ->where('company_name', $routeValue)
            ->first();

        if ($matched !== null) {
            return $matched;
        }

        // Alte Lieferanten-Links unterstützen.
        $legacy = str_replace('-', ' ', $routeValue);

        if ($legacy !== $routeValue) {
            $matched = $this->newQuery()
                ->where('company_name', $legacy)
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        $matched = $this->newQuery()
            ->where('supplier_number', $routeValue)
            ->first();

        if ($matched !== null) {
            return $matched;
        }

        // Alte numerische URLs ohne Namenskollision.
        if (ctype_digit($routeValue)) {
            return $this->newQuery()
                ->whereKey($routeValue)
                ->firstOrFail();
        }

        return $this->newQuery()
            ->where('company_name', $routeValue)
            ->firstOrFail();
    }


}
