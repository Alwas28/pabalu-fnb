<?php

use App\Models\Order;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);
        $this->order = $order->loadReceipt()->load('statusHistories.user');
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
</div>
