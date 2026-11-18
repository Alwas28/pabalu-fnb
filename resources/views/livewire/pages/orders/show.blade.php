<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundType;
use App\Models\Order;
use App\Support\Pos\RefundService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Order $order;

    // Pengajuan void / refund
    public string $refundType = 'refund';
    public string $refundAmount = '';
    public string $refundMethod = 'cash';
    public string $refundReason = '';

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);
        $this->loadOrder($order);
    }

    private function loadOrder(Order $order): void
    {
        $this->order = $order->loadReceipt()->load(['statusHistories.user', 'refunds.user', 'refundRequests.requester', 'refundRequests.decider', 'canceller']);
    }

    public function canRequestRefund(): bool
    {
        return in_array($this->order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)
            && $this->order->status !== OrderStatus::Cancelled
            && app(RefundService::class)->refundable($this->order) > 0
            && ! $this->order->refundRequests->contains(fn ($r) => $r->isPending());
    }

    public function requestRefund(): void
    {
        $this->authorize('view', $this->order);

        $request = app(RefundService::class)->request(auth()->user(), $this->order, [
            'type' => $this->refundType,
            'amount' => Money::parse($this->refundAmount) ?? '',
            'method' => $this->refundMethod,
            'reason' => $this->refundReason,
        ]);

        $this->reset('refundType', 'refundAmount', 'refundMethod', 'refundReason');
        $this->loadOrder($this->order->fresh());
        $this->dispatch('close-modal', name: 'refund-request');
        $this->dispatch('toast', type: 'success', message: $request->isPending()
            ? $request->type->label().' diajukan, menunggu persetujuan owner'
            : $request->type->label().' berhasil diproses');
    }

    public function rendering($view): void
    {
        $view->title('Order '.$this->order->number);
    }
}; ?>

@php
    $tz = $order->business->timezone ?: config('app.timezone');
    $rate = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, ',', ''), '0'), ',').'%';
@endphp

<x-slot name="actions">
    @if ($this->canRequestRefund())
        <button type="button" x-data x-on:click="$dispatch('open-modal', 'refund-request')" class="btn btn-secondary !px-3 sm:!px-[18px]" style="color:#f87171">
            <i class="fa-solid fa-rotate-left"></i><span class="hidden sm:inline">Void / Refund</span>
        </button>
    @endif
    @if ($order->isOpen())
        @can('operate', $order)
            <a href="{{ route('pos', ['order' => $order->id]) }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-cash-register"></i><span class="hidden sm:inline">Buka di Kasir</span></a>
        @endcan
    @endif
    <a href="{{ route('receipts.show', ['token' => $order->receipt_token, 'print' => 1]) }}" target="_blank" class="btn btn-secondary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-print"></i><span class="hidden sm:inline">{{ $order->isOpen() ? 'Cetak Tagihan' : 'Cetak Struk' }}</span>
    </a>
</x-slot>

<div class="flex flex-col gap-5 max-w-5xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('orders.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Pesanan</a>
        <span>/</span><span>{{ $order->number }}</span>
    </div>

    <div class="two-col">
        <div class="flex flex-col gap-5 min-w-0">
            <div class="card overflow-hidden animate-fadeUp">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-list"></i>Item Pesanan</div>
                    <div class="flex gap-1.5">
                        <span class="badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span>
                        <span class="badge {{ $order->payment_status->badge() }}">{{ $order->payment_status->label() }}</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>Menu</th><th>Dapur</th><th class="text-right">Harga</th><th class="text-right">Qty</th><th class="text-right">Jumlah</th></tr></thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr wire:key="oi-{{ $item->id }}">
                                    <td class="td-main !whitespace-normal">
                                        {{ $item->displayName() }}
                                        @foreach ($item->modifiers as $modifier)
                                            <div class="text-[11.5px] text-pb-muted font-normal">+ {{ $modifier->group_name }}: {{ $modifier->name }}{{ $modifier->price ? ' ('.Money::format($modifier->price).')' : '' }}</div>
                                        @endforeach
                                        @if ($item->notes)
                                            <div class="text-[11.5px] text-pb-muted font-normal italic">“{{ $item->notes }}”</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $item->kitchen_status->badge() }}">{{ $item->kitchen_status->label() }}</span>
                                        @if ($item->kitchen_batch > 1)<div class="text-[10.5px] text-pb-muted mt-0.5">Tambahan #{{ $item->kitchen_batch - 1 }}</div>@endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ Money::format($item->unit_price) }}</td>
                                    <td class="text-right">{{ $item->quantity }}</td>
                                    <td class="text-right whitespace-nowrap font-semibold">{{ Money::format($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4 border-t border-pb-border flex flex-col gap-1.5 text-[13px] sm:ml-auto sm:w-80">
                    <div class="flex justify-between text-pb-sub"><span>Subtotal</span><span>{{ Money::format($order->subtotal) }}</span></div>
                    @if ($order->discount_amount > 0)
                        <div class="flex justify-between text-pb-sub">
                            <span>Diskon{{ $order->discount_type?->value === 'percent' ? ' '.$rate($order->discount_value) : '' }}</span>
                            <span>-{{ Money::format($order->discount_amount) }}</span>
                        </div>
                        @if ($order->discount_reason)
                            <div class="text-[11.5px] text-pb-muted -mt-1">Alasan: {{ $order->discount_reason }}</div>
                        @endif
                    @endif
                    @if ($order->service_charge_amount > 0)
                        <div class="flex justify-between text-pb-sub"><span>Service {{ $rate($order->service_charge_rate) }}</span><span>{{ Money::format($order->service_charge_amount) }}</span></div>
                    @endif
                    @if ($order->tax_amount > 0)
                        <div class="flex justify-between text-pb-sub">
                            <span>{{ $order->prices_include_tax ? 'Termasuk pajak' : 'Pajak' }} {{ $rate($order->tax_rate) }}</span>
                            <span>{{ Money::format($order->tax_amount) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between font-display text-lg font-bold text-pb-text mt-1"><span>Total</span><span>{{ Money::format($order->total) }}</span></div>
                </div>
            </div>

            @if ($order->refundRequests->isNotEmpty() || $order->refunds->isNotEmpty())
                <div class="card overflow-hidden animate-fadeUp d1">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-rotate-left"></i>Void &amp; Refund</div>
                        @if ($order->refunded_amount > 0)
                            <span class="chip">Dikembalikan {{ Money::format($order->refunded_amount) }}</span>
                        @endif
                    </div>
                    <div class="flex flex-col divide-y divide-pb-border text-[13px]">
                        @foreach ($order->refundRequests as $request)
                            <div class="px-5 py-3 flex flex-wrap items-start gap-3" wire:key="rr-{{ $request->id }}">
                                <span class="badge {{ $request->status->badge() }}">{{ $request->status->label() }}</span>
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-pb-text">{{ $request->type->label() }} {{ Money::format($request->amount) }}{{ $request->method ? ' · '.$request->method->label() : '' }}</div>
                                    <div class="text-pb-sub">“{{ $request->reason }}” — {{ $request->requester?->name }}, {{ $request->created_at->timezone($tz)->format('d/m H:i') }}</div>
                                    @if ($request->decided_at)
                                        <div class="text-[12px] text-pb-muted">{{ $request->status->label() }} oleh {{ $request->decider?->name }} {{ $request->decided_at->timezone($tz)->format('d/m H:i') }}{{ $request->decision_note ? ' · '.$request->decision_note : '' }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card overflow-hidden animate-fadeUp d1">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-wallet"></i>Pembayaran</div></div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>Metode</th><th>Referensi</th><th class="text-right">Diterima</th><th class="text-right">Dialokasikan</th><th class="text-right">Kembalian</th></tr></thead>
                        <tbody>
                            @foreach ($order->payments as $payment)
                                <tr wire:key="op-{{ $payment->id }}">
                                    <td class="td-main"><i class="fa-solid {{ $payment->method->icon() }} mr-1.5 a-text"></i>{{ $payment->method->label() }}</td>
                                    <td>{{ $payment->reference ?? '—' }}</td>
                                    <td class="text-right">{{ Money::format($payment->tendered_amount) }}</td>
                                    <td class="text-right">{{ Money::format($payment->amount) }}</td>
                                    <td class="text-right">{{ $payment->change_amount ? Money::format($payment->change_amount) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-5">
            <div class="card animate-fadeUp d1">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-circle-info"></i>Detail</div></div>
                <div class="card-body flex flex-col gap-2 text-[13px]">
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Outlet</span><span class="text-pb-text text-right">{{ $order->outlet->name }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Waktu</span><span class="text-pb-text text-right">{{ $order->placed_at->timezone($tz)->translatedFormat('d M Y, H:i') }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Kasir</span><span class="text-pb-text text-right">{{ $order->cashier?->name ?? '—' }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Layanan</span><span class="text-pb-text text-right">{{ $order->service_type->label() }}{{ $order->table_number ? ' · Meja '.$order->table_number : '' }}</span></div>
                    @if ($order->customer_name)
                        <div class="flex justify-between gap-3"><span class="text-pb-muted">Pelanggan</span><span class="text-pb-text text-right">{{ $order->customer_name }}</span></div>
                    @endif
                    @if ($order->cancelled_at)
                        <div class="flex justify-between gap-3"><span class="text-pb-muted">Dibatalkan</span><span class="text-pb-text text-right">{{ $order->cancelled_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $order->canceller?->name }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-pb-muted">Alasan</span><span class="trend-down text-right">{{ $order->cancel_reason }}</span></div>
                    @endif
                    @if ($order->notes)
                        <div class="flex justify-between gap-3"><span class="text-pb-muted">Catatan</span><span class="text-pb-text text-right">{{ $order->notes }}</span></div>
                    @endif
                </div>
            </div>

            <div class="card animate-fadeUp d2">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-qrcode"></i>Struk Digital</div></div>
                <div class="card-body flex flex-col items-center gap-3">
                    <div class="w-36 bg-white p-2 rounded-lg">{!! App\Support\Receipt\QrCode::svg($order->receiptUrl()) !!}</div>
                    <a href="{{ $order->receiptUrl() }}" target="_blank" class="link text-[12px] break-all text-center">{{ $order->receiptUrl() }}</a>
                </div>
            </div>

            <div class="card animate-fadeUp d3">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Status</div>
                    @if (! $order->outlet->usesKitchenDisplay())
                        <div class="flex gap-1">
                            @foreach ($order->items->pluck('kitchen_batch')->unique()->sort() as $batch)
                                <a href="{{ route('orders.kitchen-ticket', ['order' => $order->id, 'batch' => $batch]) }}" target="_blank" class="chip" title="Cetak tiket dapur">
                                    <i class="fa-solid fa-fire-burner"></i>{{ $batch === 1 ? 'Tiket' : '+'.($batch - 1) }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="card-body flex flex-col gap-3 text-[12.5px]">
                    @foreach ($order->statusHistories as $history)
                        <div wire:key="h-{{ $history->id }}">
                            <div class="text-pb-text font-semibold">{{ $history->to_status->label() }}</div>
                            <div class="text-pb-muted">{{ $history->created_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $history->user?->name ?? 'Sistem' }}</div>
                            @if ($history->note)<div class="text-pb-sub">{{ $history->note }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <x-modal name="refund-request" maxWidth="md" focusable>
        <form wire:submit="requestRefund">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Ajukan Void / Refund</div>
                <div class="text-xs text-pb-muted mt-px">
                    {{ $order->number }} · bisa dikembalikan {{ Money::format(app(RefundService::class)->refundable($order)) }}
                    @cannot('refunds.approve') · perlu persetujuan owner @endcannot
                </div>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4">
                <div class="grid gap-2">
                    @foreach (RefundType::cases() as $type)
                        <label class="choice-card !p-3">
                            <input type="radio" wire:model.live="refundType" value="{{ $type->value }}" name="refundType" @disabled($type === RefundType::Void && $order->refunded_amount > 0)>
                            <span>
                                <span class="block text-[13px] font-semibold text-pb-text">{{ $type->label() }}</span>
                                <span class="block text-[11.5px] text-pb-muted">{{ $type->description() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('type')" />
                @if ($refundType === 'refund')
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <x-input-label for="refundAmount" value="Nominal (Rp)" />
                            <x-text-input wire:model="refundAmount" id="refundAmount" type="number" min="1" required />
                            <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="refundMethod" value="Dikembalikan via" />
                            <select wire:model="refundMethod" id="refundMethod" class="f-input">
                                @foreach (PaymentMethod::cases() as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif
                <div>
                    <x-input-label for="refundReason" value="Alasan" />
                    <x-text-input wire:model="refundReason" id="refundReason" placeholder="mis. Salah input menu, minuman tumpah" required />
                    <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                </div>
                <p class="f-hint !mt-0"><i class="fa-solid fa-circle-info mr-1"></i>Pengembalian tunai diambil dari laci kas shift yang sedang berjalan.</p>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="requestRefund"><i class="fa-solid fa-paper-plane"></i>@can('refunds.approve') Proses @else Ajukan @endcan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
