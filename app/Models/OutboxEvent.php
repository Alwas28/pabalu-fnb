<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Event bisnis yang menunggu / sudah dikirim ke Analytics Platform. Dihapus 30 hari setelah terkirim. */
class OutboxEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** Belum terkirim dan sudah waktunya dicoba (lagi). */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('dispatched_at')
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    /** @return array<string, mixed> */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'business_id' => $this->business_id,
            'outlet_id' => $this->outlet_id,
            'data' => $this->payload,
        ];
    }

    public function prunable(): Builder
    {
        return static::whereNotNull('dispatched_at')->where('dispatched_at', '<=', now()->subDays(30));
    }
}
