<?php

namespace App\Support\Analytics;

use App\Models\OutboxEvent;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pencatat event untuk Analytics Platform / Central Dashboard.
 *
 * Event dicatat SETELAH transaksi DB aksi bisnis berhasil (DB::afterCommit), sehingga aksi yang
 * dibatalkan tidak pernah terkirim dan isi event (mis. item order) sudah lengkap. Penerima wajib
 * memperlakukan `id` sebagai kunci idempoten karena pengiriman bersifat at-least-once.
 */
class Outbox
{
    public static function enabled(): bool
    {
        return filled(config('services.analytics.endpoint'));
    }

    /**
     * @param  Closure(): (array{business_id: int, outlet_id: ?int, data: array<string, mixed>, occurred_at?: ?CarbonInterface}|null)  $build
     *                                                                                                                                         dipanggil setelah commit; null = batal dicatat
     */
    public static function afterCommit(string $type, Closure $build): void
    {
        if (! self::enabled()) {
            return;
        }

        DB::afterCommit(function () use ($type, $build) {
            $event = $build();

            if ($event !== null) {
                self::record($type, $event['business_id'], $event['outlet_id'], $event['data'], $event['occurred_at'] ?? null);
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function record(string $type, int $businessId, ?int $outletId, array $data, ?CarbonInterface $occurredAt = null): OutboxEvent
    {
        return OutboxEvent::create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'payload' => $data,
            'occurred_at' => $occurredAt ?? now(),
            'created_at' => now(),
        ]);
    }
}
