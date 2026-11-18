<?php

use App\Enums\PurchaseStatus;
use App\Models\PurchaseOrder;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Pembelian')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function outlet()
    {
        return auth()->user()->currentOutlet();
    }

    private function query()
    {
        return PurchaseOrder::where('outlet_id', $this->outlet?->id ?? 0);
    }

    #[Computed]
    public function orders()
    {
        return $this->query()
            ->with('supplier')
            ->when($this->status === 'unpaid', fn ($q) => $q->whereRaw('received_total - returned_total > paid_amount'))
            ->when($this->status !== '' && $this->status !== 'unpaid', fn ($q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('order_date')
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function summary(): array
    {
        $active = $this->query()->where('status', '!=', PurchaseStatus::Cancelled->value);

        return [
            'waiting' => (clone $active)->whereIn('status', [PurchaseStatus::Ordered->value, PurchaseStatus::Partial->value])->count(),
            'outstanding' => (int) (clone $active)->selectRaw('coalesce(sum(case when received_total - returned_total > paid_amount then received_total - returned_total - paid_amount else 0 end), 0) as v')->value('v'),
            'month' => (int) (clone $active)->where('order_date', '>=', now()->startOfMonth()->toDateString())->sum('total'),
        ];
    }
}; ?>

<x-slot name="actions">
    <a href="{{ route('purchasing.suppliers') }}" wire:navigate class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-truck-field"></i><span class="hidden sm:inline">Supplier</span></a>
    <a href="{{ route('purchasing.create') }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Buat PO</span></a>
</x-slot>

<div class="flex flex-col gap-5">
    <div class="stat-grid !grid-cols-1 sm:!grid-cols-3 max-w-3xl">
        <div class="stat-card"><div class="stat-label">Menunggu barang</div><div class="stat-num">{{ $this->summary['waiting'] }} PO</div></div>
        <div class="stat-card"><div class="stat-label">Sisa tagihan supplier</div><div class="stat-num trend-down">{{ Money::format($this->summary['outstanding']) }}</div></div>
        <div class="stat-card"><div class="stat-label">Nilai PO bulan ini</div><div class="stat-num">{{ Money::format($this->summary['month']) }}</div></div>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header flex-wrap gap-2">
            <div class="card-title"><i class="fa-solid fa-truck"></i>Purchase Order · {{ $this->outlet?->name }}</div>
            <div class="flex flex-wrap gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-52 !text-[12.5px]" placeholder="No. PO / supplier">
                </div>
                <select wire:model.live="status" class="f-input !py-1.5 !w-44 !text-[12.5px]" aria-label="Status">
                    <option value="">Semua status</option>
                    @foreach (PurchaseStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                    <option value="unpaid">Belum lunas</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>No. PO</th><th>Tanggal</th><th>Supplier</th><th class="text-right">Nilai PO</th><th class="text-right">Sisa tagihan</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($this->orders as $order)
                        <tr wire:key="po-{{ $order->id }}">
                            <td class="td-main"><a href="{{ route('purchasing.show', $order) }}" wire:navigate class="link">{{ $order->number }}</a></td>
                            <td>{{ $order->order_date->format('d/m/Y') }}</td>
                            <td>{{ $order->supplier->name }}</td>
                            <td class="text-right">{{ Money::format($order->total) }}</td>
                            <td @class(['text-right font-semibold', 'trend-down' => $order->outstanding() > 0])>{{ $order->outstanding() ? Money::format($order->outstanding()) : '—' }}</td>
                            <td><span class="badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada purchase order.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->orders->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->orders->links() }}</div>
        @endif
    </div>
</div>
