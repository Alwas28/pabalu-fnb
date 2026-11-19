<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event penjualan untuk Analytics: order.paid & order.cancelled (dari kasir web, API, maupun sinkronisasi offline). */
class OrderAnalyticsObserver
{
    public function created(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            $this->paid($order);
        }
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('payment_status') && $order->payment_status === PaymentStatus::Paid) {
            $this->paid($order);
        }

        if ($order->wasChanged('status') && $order->status === OrderStatus::Cancelled) {
            $wasPaid = (int) $order->paid_amount > 0;

            Outbox::afterCommit('order.cancelled', fn () => ($fresh = Order::find($order->id)) ? [
                'business_id' => $fresh->business_id,
                'outlet_id' => $fresh->outlet_id,
                'occurred_at' => $fresh->cancelled_at,
                'data' => EventPayloads::orderCancelled($fresh, $wasPaid),
            ] : null);
        }
    }

    private function paid(Order $order): void
    {
        Outbox::afterCommit('order.paid', fn () => ($fresh = Order::find($order->id)) ? [
            'business_id' => $fresh->business_id,
            'outlet_id' => $fresh->outlet_id,
            'occurred_at' => $fresh->payments()->latest('paid_at')->first()?->paid_at,
            'data' => EventPayloads::order($fresh),
        ] : null);
    }
}
