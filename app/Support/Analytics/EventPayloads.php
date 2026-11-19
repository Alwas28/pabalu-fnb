<?php

namespace App\Support\Analytics;

use App\Models\CashShift;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Outlet;
use App\Models\PurchasePayment;
use App\Models\QuickPurchase;
use App\Models\QuickPurchaseItem;

/**
 * Isi event yang dikirim ke Analytics Platform. Nominal dalam rupiah (integer), waktu ISO-8601,
 * jumlah bahan dalam satuan dasar. Struktur ini kontrak dengan penerima: tambahkan field baru,
 * jangan ubah / hapus field lama.
 */
class EventPayloads
{
    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        $order->loadMissing(['outlet', 'items.modifiers', 'payments']);

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'outlet' => self::outlet($order->outlet),
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                'service_type' => $order->service_type->value,
                'delivery_channel' => $order->delivery_channel?->value,
                'table_number' => $order->table_number,
                'customer_id' => $order->customer_id,
                'cashier_id' => $order->cashier_id,
                'offline' => $order->client_reference !== null,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'paid_at' => $order->payments->max('paid_at')?->toIso8601String(),
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'service_charge_amount' => $order->service_charge_amount,
                'tax_amount' => $order->tax_amount,
                'prices_include_tax' => $order->prices_include_tax,
                'total' => $order->total,
                'paid_amount' => $order->paid_amount,
                'refunded_amount' => (int) $order->refunded_amount,
                'cost_amount' => (int) $order->items->sum('cost_amount'),
            ],
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'menu_variant_id' => $item->menu_variant_id,
                'name' => $item->name,
                'variant_name' => $item->variant_name,
                'quantity' => (int) $item->quantity - (int) $item->voided_quantity,
                'unit_price' => (int) $item->unit_price,
                'line_total' => (int) $item->line_total,
                'cost_amount' => (int) $item->cost_amount,
                'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'modifier_id' => $modifier->modifier_id, 'group' => $modifier->group_name, 'name' => $modifier->name, 'price' => (int) $modifier->price,
                ])->values()->all(),
            ])->values()->all(),
            'payments' => $order->payments->map(fn ($payment) => [
                'method' => $payment->method->value,
                'amount' => (int) $payment->amount,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function orderCancelled(Order $order, bool $wasPaid): array
    {
        $order->loadMissing('outlet');

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'outlet' => self::outlet($order->outlet),
                'was_paid' => $wasPaid,
                'total' => $order->total,
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                'reason' => $order->cancel_reason,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(OrderRefund $refund): array
    {
        $refund->loadMissing('order.outlet');

        return [
            'refund' => [
                'id' => $refund->id,
                'order_id' => $refund->order_id,
                'order_number' => $refund->order->number,
                'outlet' => self::outlet($refund->order->outlet),
                'method' => $refund->method->value,
                'amount' => (int) $refund->amount,
                'refunded_at' => $refund->created_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function shift(CashShift $shift): array
    {
        $shift->loadMissing('outlet');

        return [
            'shift' => [
                'id' => $shift->id,
                'outlet' => self::outlet($shift->outlet),
                'cashier_id' => $shift->user_id,
                'opened_at' => $shift->opened_at?->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'opening_cash' => (int) $shift->opening_cash,
                'expected_cash' => (int) $shift->expected_cash,
                'actual_cash' => (int) $shift->actual_cash,
                'cash_difference' => (int) $shift->cash_difference,
                'summary' => $shift->summary,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function expense(Expense $expense): array
    {
        $expense->loadMissing(['category', 'outlet']);

        return [
            'expense' => [
                'id' => $expense->id,
                'outlet' => $expense->outlet ? self::outlet($expense->outlet) : null,
                'category' => $expense->category?->name,
                'category_template_id' => $expense->category?->template_id,
                'amount' => (int) $expense->amount,
                'method' => $expense->method->value,
                'spent_at' => $expense->spent_at->toDateString(),
                'description' => $expense->description,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function quickPurchase(QuickPurchase $purchase): array
    {
        $purchase->loadMissing(['outlet', 'items.ingredient']);

        return [
            'purchase' => [
                'id' => $purchase->id,
                'outlet' => self::outlet($purchase->outlet),
                'purchased_at' => $purchase->purchased_at->toDateString(),
                'store_name' => $purchase->store_name,
                'method' => $purchase->method->value,
                'total' => $purchase->total,
                'cancelled_at' => $purchase->cancelled_at?->toIso8601String(),
                'items' => $purchase->items->map(fn (QuickPurchaseItem $item) => [
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient' => $item->ingredient->name,
                    'unit' => $item->ingredient->base_unit->value,
                    'quantity' => (float) $item->base_quantity,
                    'total_price' => $item->total_price,
                ])->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function supplierPayment(PurchasePayment $payment): array
    {
        $payment->loadMissing('purchaseOrder.outlet', 'purchaseOrder.supplier');
        $order = $payment->purchaseOrder;

        return [
            'payment' => [
                'id' => $payment->id,
                'outlet' => self::outlet($order->outlet),
                'purchase_order_id' => $order->id,
                'purchase_order_number' => $order->number,
                'supplier_id' => $order->supplier_id,
                'supplier' => $order->supplier?->name,
                'method' => $payment->method->value,
                'amount' => (int) $payment->amount,
                'paid_at' => $payment->paid_at?->toDateString(),
            ],
        ];
    }

    /** @return array{id: int, code: ?string, name: string} */
    private static function outlet(Outlet $outlet): array
    {
        return ['id' => $outlet->id, 'code' => $outlet->code, 'name' => $outlet->name];
    }
}
