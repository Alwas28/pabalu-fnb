<?php

namespace App\Support\Pos;

use App\Enums\ApprovalStatus;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RefundType;
use App\Models\CashShift;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Void & refund pesanan yang sudah dibayar — wajib persetujuan owner (Blueprint Khusus F&B bagian 14).
 *
 * - Staf mengajukan dengan alasan; owner menyetujui / menolak. Pengajuan oleh owner langsung berlaku.
 * - Void: seluruh transaksi dibatalkan, setiap pembayaran dikembalikan dengan metode asalnya.
 *   Hanya untuk order yang belum pernah di-refund.
 * - Refund: sebagian / seluruh sisa nominal dengan satu metode.
 * - Pengembalian tunai dicatat ke shift berjalan (pengaju, atau owner) sehingga mengurangi kas laci.
 */
class RefundService
{
    /**
     * @param  array<string, mixed>  $input  type, amount, method, reason
     *
     * @throws ValidationException
     */
    public function request(User $user, Order $order, array $input): RefundRequest
    {
        $this->ensureRefundable($order);

        $data = Validator::make($input, [
            'type' => ['required', Rule::enum(RefundType::class)],
            'amount' => ['exclude_unless:type,refund', 'required', 'integer', 'min:1', 'max:'.$this->refundable($order)],
            'method' => ['exclude_unless:type,refund', 'required', Rule::enum(PaymentMethod::class)],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'amount.max' => 'Nominal refund melebihi sisa yang bisa dikembalikan ('.Money::format($this->refundable($order)).').',
        ], ['amount' => 'nominal', 'method' => 'metode', 'reason' => 'alasan'])->validate();

        $type = RefundType::from($data['type']);

        if ($type === RefundType::Void && $order->refunded_amount > 0) {
            throw ValidationException::withMessages(['type' => 'Order yang sudah pernah di-refund tidak bisa di-void. Ajukan refund untuk sisanya.']);
        }

        if (RefundRequest::where('order_id', $order->id)->where('status', ApprovalStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['type' => 'Masih ada pengajuan yang menunggu persetujuan owner untuk order ini.']);
        }

        $request = RefundRequest::create([
            'business_id' => $order->business_id,
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
            'type' => $type,
            'amount' => $type === RefundType::Void ? $this->refundable($order) : (int) $data['amount'],
            'method' => $type === RefundType::Refund ? $data['method'] : null,
            'reason' => $data['reason'],
            'status' => ApprovalStatus::Pending,
            'requested_by' => $user->id,
        ]);

        // Owner tidak perlu menyetujui pengajuannya sendiri.
        if ($user->hasPermission(Permission::ApproveRefunds)) {
            return $this->approve($user, $request);
        }

        return $request;
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function approve(User $owner, RefundRequest $request, ?string $note = null): RefundRequest
    {
        $this->authorizeDecision($owner, $request);

        return DB::transaction(function () use ($owner, $request, $note) {
            $request = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($request->order_id)->lockForUpdate()->firstOrFail();

            if (! $request->isPending()) {
                throw ValidationException::withMessages(['request' => 'Pengajuan ini sudah diputuskan.']);
            }

            $this->ensureRefundable($order);

            if ($request->amount > $this->refundable($order)) {
                throw ValidationException::withMessages(['request' => 'Nominal melebihi sisa yang bisa dikembalikan.']);
            }

            $shift = $this->cashShift($request, $owner);
            $rows = $request->type === RefundType::Void
                ? $order->payments->map(fn ($p) => ['method' => $p->method, 'amount' => $p->amount])->all()
                : [['method' => $request->method, 'amount' => $request->amount]];

            foreach ($rows as $row) {
                $order->refunds()->create($row + [
                    'refund_request_id' => $request->id,
                    'cash_shift_id' => $row['method'] === PaymentMethod::Cash ? $shift?->id : null,
                    'user_id' => $owner->id,
                ]);
            }

            $refunded = $order->refunded_amount + $request->amount;
            $from = $order->status;

            $order->forceFill([
                'refunded_amount' => $refunded,
                'payment_status' => $refunded >= $order->paid_amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ])->save();

            if ($request->type === RefundType::Void) {
                $order->forceFill([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $owner->id,
                    'cancel_reason' => 'Void: '.$request->reason,
                ])->save();
                $order->items()->get()->filter(fn ($i) => $i->kitchen_status->isActive())
                    ->each(fn ($i) => $i->update(['kitchen_status' => KitchenItemStatus::Cancelled, 'kitchen_updated_at' => now()]));
                OrderWriter::history($order, $from, OrderStatus::Cancelled, $owner, 'Void disetujui: '.$request->reason);
                OrderWriter::releaseIfFree($order->diningTable);
                OrderEvents::voided($order);
            } else {
                OrderWriter::history($order, $from, $order->status, $owner, 'Refund '.Money::format($request->amount).' disetujui: '.$request->reason);
            }

            $request->update([
                'status' => ApprovalStatus::Approved,
                'decided_by' => $owner->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $request;
        });
    }

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function reject(User $owner, RefundRequest $request, string $note): RefundRequest
    {
        $this->authorizeDecision($owner, $request);

        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => 'Pengajuan ini sudah diputuskan.']);
        }

        Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:255']], [], ['note' => 'alasan penolakan'])->validate();

        $request->update([
            'status' => ApprovalStatus::Rejected,
            'decided_by' => $owner->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $request;
    }

    /** Sisa nominal yang masih bisa dikembalikan. */
    public function refundable(Order $order): int
    {
        return max(0, $order->paid_amount - $order->refunded_amount);
    }

    private function ensureRefundable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled
            || ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)
            || $this->refundable($order) === 0) {
            throw ValidationException::withMessages(['type' => 'Hanya order lunas yang belum dikembalikan penuh yang bisa di-void / refund.']);
        }
    }

    private function authorizeDecision(User $owner, RefundRequest $request): void
    {
        if (! $owner->hasPermission(Permission::ApproveRefunds) || $owner->activeBusiness()?->id !== $request->business_id) {
            throw new AuthorizationException('Hanya owner yang dapat menyetujui void / refund.');
        }
    }

    /** Shift berjalan di outlet: milik pengaju, atau milik owner yang menyetujui. */
    private function cashShift(RefundRequest $request, User $owner): ?CashShift
    {
        return CashShift::open()->where('outlet_id', $request->outlet_id)
            ->whereIn('user_id', array_filter([$request->requested_by, $owner->id]))
            ->orderByRaw('user_id = ? desc', [$request->requested_by])
            ->first();
    }
}
