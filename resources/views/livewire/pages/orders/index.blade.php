<?php

use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Pesanan')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $date = '';

    #[Url(as: 'outlet')]
    public string $outletId = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->date = $this->date ?: now($this->timezone())->toDateString();
        $this->outletId = $this->outletId ?: (string) auth()->user()->currentOutlet()?->id;
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function timezone(): string
    {
        return auth()->user()->activeBusiness()->timezone ?: config('app.timezone');
    }

    #[Computed]
    public function outlets()
    {
        return auth()->user()->accessibleOutlets();
    }

    private function query()
    {
        // Hanya outlet yang boleh diakses pengguna; outlet dari URL yang tidak berhak diabaikan.
        $outletIds = $this->outlets->pluck('id');
        $selected = $outletIds->contains((int) $this->outletId) ? [(int) $this->outletId] : $outletIds->all();

        try {
            $day = Carbon::parse($this->date, $this->timezone());
        } catch (\Throwable) {
            $day = now($this->timezone());
        }

        return Order::query()
            ->whereIn('outlet_id', $selected)
            ->whereBetween('placed_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', '%'.$this->search.'%')
                ->orWhere('customer_name', 'like', '%'.$this->search.'%')));
    }

    #[Computed]
    public function orders()
    {
        return $this->query()
            ->with(['outlet', 'cashier', 'payments'])
            ->withCount('items')
            ->latest('placed_at')
            ->latest('id')
            ->paginate(25);
    }

    #[Computed]
    public function summary(): array
    {
        $row = $this->query()->where('status', '!=', 'cancelled')
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue')
            ->first();

        return ['orders' => (int) $row->orders, 'revenue' => (int) $row->revenue];
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="stat-grid !grid-cols-2 max-w-xl">
        <div class="stat-card animate-fadeUp">
            <div class="min-w-0 flex flex-col gap-1">
            <div class="stat-num whitespace-nowrap !text-[22px]">{{ number_format($this->summary['orders'], 0, ',', '.') }}</div>
            <div class="stat-label">Jumlah order</div>
            </div>
        </div>
        <div class="stat-card animate-fadeUp d1">
            <div class="min-w-0 flex flex-col gap-1">
            <div class="stat-num whitespace-nowrap !text-[22px]">{{ Money::format($this->summary['revenue']) }}</div>
            <div class="stat-label">Total penjualan</div>
            </div>
        </div>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header flex-wrap gap-3">
            <div class="card-title"><i class="fa-solid fa-receipt"></i>Daftar Pesanan</div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-52 !text-[12.5px]" placeholder="No. order / pelanggan">
                </div>
                <input type="date" wire:model.live="date" class="f-input !py-1.5 !w-40 !text-[12.5px]" aria-label="Tanggal">
                @if ($this->outlets->count() > 1)
                    <select wire:model.live="outletId" class="f-input !py-1.5 !w-44 !text-[12.5px]" aria-label="Outlet">
                        <option value="all">Semua outlet</option>
                        @foreach ($this->outlets as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th>No. Order</th><th>Waktu</th><th>Layanan</th><th>Kasir</th><th>Bayar</th><th class="text-right">Total</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->orders as $order)
                        <tr wire:key="order-{{ $order->id }}" class="cursor-pointer" onclick="Livewire.navigate('{{ route('orders.show', $order) }}')">
                            <td class="td-main">
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="link">{{ $order->number }}</a>
                                <div class="text-[11px] text-pb-muted font-normal">{{ $order->items_count }} item{{ $order->customer_name ? ' · '.$order->customer_name : '' }}</div>
                            </td>
                            <td class="whitespace-nowrap">{{ $order->placed_at->timezone($this->timezone())->format('H:i') }}</td>
                            <td>{{ $order->service_type->label() }}{{ $order->table_number ? ' · Meja '.$order->table_number : '' }}</td>
                            <td>{{ $order->cashier?->name ?? '—' }}</td>
                            <td class="text-[12px]">{{ $order->payments->map(fn ($p) => $p->method->label())->unique()->join(' + ') }}</td>
                            <td class="text-right font-semibold whitespace-nowrap">{{ Money::format($order->total) }}</td>
                            <td>
                                <span class="badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span>
                                <span class="badge {{ $order->payment_status->badge() }}">{{ $order->payment_status->label() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center !py-8">Belum ada pesanan pada tanggal ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->orders->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->orders->links() }}</div>
        @endif
    </div>
</div>
