<?php

namespace App\Observers;

use App\Models\PurchasePayment;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event supplier_payment.recorded: pembayaran tagihan PO ke supplier (uang keluar). */
class PurchasePaymentAnalyticsObserver
{
    public function created(PurchasePayment $payment): void
    {
        Outbox::afterCommit('supplier_payment.recorded', fn () => ($fresh = PurchasePayment::with('purchaseOrder')->find($payment->id)) ? [
            'business_id' => $fresh->purchaseOrder->business_id,
            'outlet_id' => $fresh->purchaseOrder->outlet_id,
            'occurred_at' => $fresh->created_at,
            'data' => EventPayloads::supplierPayment($fresh),
        ] : null);
    }
}
