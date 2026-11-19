<?php

namespace App\Observers;

use App\Enums\ShiftStatus;
use App\Models\CashShift;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event shift.closed: rekap kas per shift kasir. */
class CashShiftAnalyticsObserver
{
    public function updated(CashShift $shift): void
    {
        if (! $shift->wasChanged('status') || $shift->status !== ShiftStatus::Closed) {
            return;
        }

        Outbox::afterCommit('shift.closed', fn () => ($fresh = CashShift::find($shift->id)) ? [
            'business_id' => $fresh->business_id,
            'outlet_id' => $fresh->outlet_id,
            'occurred_at' => $fresh->closed_at,
            'data' => EventPayloads::shift($fresh),
        ] : null);
    }
}
