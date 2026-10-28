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

    /**
     * Stable links avoid collisions between company names, hyphenated names,
     * supplier numbers and numeric database identifiers.
     */
    public function getRouteKey(): string
    {
        return 'id-' . $this->getKey();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $value = (string) $value;

        if (preg_match('/^id-([1-9][0-9]*)$/D', $value, $matches) === 1) {
            return $this->whereKey($matches[1])->firstOrFail();
        }

        $nameFromUrl = str_replace('-', ' ', $value);

        return $this->where('company_name', $value)->first()
            ?? $this->where('company_name', $nameFromUrl)->first()
            ?? $this->where('supplier_number', $value)->first()
            ?? $this->whereKey($value)->firstOrFail();
    }


}