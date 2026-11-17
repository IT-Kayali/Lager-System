<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseNotification extends Model
{
    public const TYPE_OFFER = 'offer';
    public const TYPE_OFFER_NOTE = 'offer_note';
    public const TYPE_BRANCH_WITHDRAWAL = 'branch_withdrawal';

    protected $fillable = [
        'user_id',
        'type',
        'subject_id',
        'title',
        'message',
        'read_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at');
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
