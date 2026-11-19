<?php

namespace App\Observers;

use App\Models\OrderRefund;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event order.refunded: uang dikembalikan ke pelanggan (refund disetujui owner). */
class OrderRefundAnalyticsObserver
{
    public function created(OrderRefund $refund): void
    {
        Outbox::afterCommit('order.refunded', fn () => ($fresh = OrderRefund::with('order')->find($refund->id)) ? [
            'business_id' => $fresh->order->business_id,
            'outlet_id' => $fresh->order->outlet_id,
            'occurred_at' => $fresh->created_at,
            'data' => EventPayloads::refund($fresh),
        ] : null);
    }
}
