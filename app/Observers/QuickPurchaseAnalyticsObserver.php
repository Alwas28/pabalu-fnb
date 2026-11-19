<?php

namespace App\Observers;

use App\Models\QuickPurchase;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event quick_purchase.recorded / quick_purchase.cancelled: belanja bahan tanpa PO (uang keluar). */
class QuickPurchaseAnalyticsObserver
{
    public function created(QuickPurchase $purchase): void
    {
        $this->record('quick_purchase.recorded', $purchase);
    }

    public function updated(QuickPurchase $purchase): void
    {
        if ($purchase->wasChanged('cancelled_at') && $purchase->isCancelled()) {
            $this->record('quick_purchase.cancelled', $purchase);
        }
    }

    private function record(string $type, QuickPurchase $purchase): void
    {
        // Setelah commit item belanja sudah tersimpan semua.
        Outbox::afterCommit($type, fn () => ($fresh = QuickPurchase::find($purchase->id)) ? [
            'business_id' => $fresh->business_id,
            'outlet_id' => $fresh->outlet_id,
            'occurred_at' => $type === 'quick_purchase.cancelled' ? $fresh->cancelled_at : $fresh->created_at,
            'data' => EventPayloads::quickPurchase($fresh),
        ] : null);
    }
}
