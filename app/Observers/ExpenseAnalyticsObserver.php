<?php

namespace App\Observers;

use App\Models\Expense;
use App\Support\Analytics\EventPayloads;
use App\Support\Analytics\Outbox;

/** Event expense.recorded / expense.updated / expense.deleted: pengeluaran operasional. */
class ExpenseAnalyticsObserver
{
    public function created(Expense $expense): void
    {
        $this->record('expense.recorded', $expense);
    }

    public function updated(Expense $expense): void
    {
        $this->record('expense.updated', $expense);
    }

    public function deleted(Expense $expense): void
    {
        // Data disusun sekarang karena baris sudah tidak ada setelah commit.
        $event = $this->event($expense);
        Outbox::afterCommit('expense.deleted', fn () => $event);
    }

    private function record(string $type, Expense $expense): void
    {
        Outbox::afterCommit($type, fn () => ($fresh = Expense::find($expense->id)) ? $this->event($fresh) : null);
    }

    /** @return array{business_id: int, outlet_id: ?int, data: array<string, mixed>} */
    private function event(Expense $expense): array
    {
        return [
            'business_id' => $expense->business_id,
            'outlet_id' => $expense->outlet_id,
            'data' => EventPayloads::expense($expense),
        ];
    }
}
