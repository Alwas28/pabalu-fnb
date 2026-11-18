<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_reference' => $this->client_reference,
            'outlet_id' => $this->outlet_id,
            'service_type' => $this->service_type->value,
            'table_number' => $this->table_number,
            'dining_table_id' => $this->dining_table_id,
            'cash_shift_id' => $this->cash_shift_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'delivery_address' => $this->delivery_address,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'subtotal' => $this->subtotal,
            'discount' => [
                'type' => $this->discount_type?->value,
                'value' => (float) $this->discount_value,
                'amount' => $this->discount_amount,
                'reason' => $this->discount_reason,
            ],
            'service_charge' => ['rate' => (float) $this->service_charge_rate, 'amount' => $this->service_charge_amount],
            'tax' => ['rate' => (float) $this->tax_rate, 'included' => $this->prices_include_tax, 'amount' => $this->tax_amount],
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'change_amount' => $this->change_amount,
            'cashier' => $this->whenLoaded('cashier', fn () => $this->cashier ? ['id' => $this->cashier->id, 'name' => $this->cashier->name] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'variant_id' => $item->menu_variant_id,
                'name' => $item->name,
                'variant_name' => $item->variant_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
                'kitchen_status' => $item->kitchen_status->value,
                'kitchen_batch' => $item->kitchen_batch,
                'modifiers' => $item->relationLoaded('modifiers') ? $item->modifiers->map(fn ($m) => [
                    'modifier_id' => $m->modifier_id,
                    'group_name' => $m->group_name,
                    'name' => $m->name,
                    'price' => $m->price,
                ])->values() : [],
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method->value,
                'amount' => $p->amount,
                'tendered_amount' => $p->tendered_amount,
                'change_amount' => $p->change_amount,
                'reference' => $p->reference,
                'status' => $p->status->value,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->values()),
            'receipt_url' => $this->receiptUrl(),
            'placed_at' => $this->placed_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
