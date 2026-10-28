<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $fillable = [
        'customer_number',
        'customer_group_id',
        'company_name',
        'email',
        'phone_country_code',
        'phone',
        'city',

        'delivery_street',
        'delivery_house_number',
        'delivery_postal_code',
        'delivery_city',
        'delivery_country',
        'delivery_address',

        'billing_street',
        'billing_house_number',
        'billing_postal_code',
        'billing_city',
        'billing_country',
        'billing_address',

        'vat_number',
        'delivery_note_instruction',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (! $customer->customer_number) {
                $lastNumber = Customer::query()
                    ->where('customer_number', 'like', 'KD-%')
                    ->orderByDesc('id')
                    ->value('customer_number');

                $lastNumeric = $lastNumber
                    ? (int) str_replace('KD-', '', $lastNumber)
                    : 10019;

                $customer->customer_number = 'KD-' . ($lastNumeric + 1);
            }
        });
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

        $matching = $this->newQuery()
            ->where('company_name', $routeValue)
            ->first();

        if ($matching !== null) {
            return $matching;
        }

        $matching = $this->newQuery()
            ->where('customer_number', $routeValue)
            ->first();

        if ($matching !== null) {
            return $matching;
        }

        if (ctype_digit($routeValue)) {
            return $this->newQuery()
                ->whereKey($routeValue)
                ->firstOrFail();
        }

        return $this->newQuery()
            ->where('company_name', $routeValue)
            ->firstOrFail();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function walletTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerWalletTransaction::class);
    }

    public function getWalletBalanceAttribute(): float
    {
        return (float) $this->walletTransactions()->sum('amount');
    }

}
