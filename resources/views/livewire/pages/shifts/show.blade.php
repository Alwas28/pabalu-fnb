<?php

use App\Enums\CashMovementType;
use App\Models\CashShift;
use App\Support\Money;
use App\Support\Pos\ShiftManager;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public CashShift $shift;

    public function mount(CashShift $shift): void
    {
        $this->authorize('view', $shift);
        $this->shift = $shift->load(['outlet.business', 'user', 'closer', 'movements.user']);
    }

    public function rendering($view): void
    {
        $view->title('Shift '.$this->shift->user->name);
    }

    public function with(): array
    {
        return [
            'summary' => app(ShiftManager::class)->summary($this->shift),
            'orders' => $this->shift->payments()->with('order')->get()->pluck('order')->unique('id')->sortBy('id')->values(),
            'tz' => $this->shift->outlet->business->timezone ?: config('app.timezone'),
        ];
    }
}; ?>

<x-slot name="actions">
    <button type="button" onclick="window.print()" class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-print"></i><span class="hidden sm:inline">Cetak</span></button>
</x-slot>

<div class="flex flex-col gap-5 max-w-5xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('shifts.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Shift Kasir</a>
        <span>/</span><span>{{ $shift->opened_at->timezone($tz)->format('d/m/Y H:i') }}</span>
    </div>

    <div class="card animate-fadeUp">
        <div class="card-header flex-wrap gap-3">
            <div>
                <div class="card-title"><i class="fa-solid fa-clock"></i>{{ $shift->user->name }} · {{ $shift->outlet->name }}</div>
                <div class="section-desc">
                    {{ $shift->opened_at->timezone($tz)->translatedFormat('d M Y, H:i') }}
                    — {{ $shift->closed_at ? $shift->closed_at->timezone($tz)->translatedFormat('d M Y, H:i').' (ditutup '.$shift->closer?->name.')' : 'sekarang' }}
                </div>
            </div>
            <span class="badge {{ $shift->status->badge() }}">{{ $shift->status->label() }}</span>
        </div>
        <div class="card-body flex flex-col gap-4">
            <x-shift-summary :summary="$summary" :shift="$shift" />
            @if ($shift->opening_note || $shift->closing_note)
                <div class="text-[13px] text-pb-sub flex flex-col gap-1">
                    @if ($shift->opening_note)<div><b class="text-pb-text">Catatan buka:</b> {{ $shift->opening_note }}</div>@endif
                    @if ($shift->closing_note)<div><b class="text-pb-text">Catatan tutup:</b> {{ $shift->closing_note }}</div>@endif
                </div>
            @endif
        </div>
    </div>

    @if ($shift->movements->isNotEmpty())
        <div class="card overflow-hidden animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-right-left"></i>Kas Masuk / Keluar</div></div>
            <table class="tbl">
                <thead><tr><th>Waktu</th><th>Jenis</th><th>Alasan</th><th>Oleh</th><th class="text-right">Nominal</th></tr></thead>
                <tbody>
                    @foreach ($shift->movements as $movement)
                        <tr wire:key="mv-{{ $movement->id }}">
                            <td>{{ $movement->created_at->timezone($tz)->format('H:i') }}</td>
                            <td>{{ $movement->type->label() }}</td>
                            <td class="!whitespace-normal">{{ $movement->reason }}</td>
                            <td>{{ $movement->user?->name ?? '—' }}</td>
                            <td @class(['text-right font-semibold', 'trend-up' => $movement->type === CashMovementType::In, 'trend-down' => $movement->type === CashMovementType::Out])>
                                {{ $movement->type === CashMovementType::In ? '+' : '-' }}{{ Money::format($movement->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card overflow-hidden animate-fadeUp d2">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-receipt"></i>Order Dibayar di Shift Ini</div><span class="chip">{{ $orders->count() }} order</span></div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>No. Order</th><th>Layanan</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="so-{{ $order->id }}">
                            <td class="td-main">
                                @can('view', $order)
                                    <a href="{{ route('orders.show', $order) }}" wire:navigate class="link">{{ $order->number }}</a>
                                @else
                                    {{ $order->number }}
                                @endcan
                            </td>
                            <td>{{ $order->service_type->label() }}{{ $order->table_number ? ' · Meja '.$order->table_number : '' }}</td>
                            <td class="text-right font-semibold">{{ Money::format($order->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center !py-8">Belum ada order dibayar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
