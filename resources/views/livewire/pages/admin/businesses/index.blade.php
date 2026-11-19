<?php

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Bisnis')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = semua, 'active', 'suspended' */
    #[Url]
    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function businesses()
    {
        $since = now()->subDays(30);

        return Business::query()
            ->with('owner')
            ->withCount(['outlets', 'memberships'])
            ->withCount(['orders as orders_30d' => fn ($q) => $q->where('placed_at', '>=', $since)->where('status', '!=', OrderStatus::Cancelled)])
            ->withSum(['orders as revenue_30d' => fn ($q) => $q->where('placed_at', '>=', $since)->where('status', '!=', OrderStatus::Cancelled)], 'total')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhereHas('owner', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%'))))
            ->when($this->status === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->when($this->status === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->latest()
            ->paginate(20);
    }
}; ?>

@php $tz = config('app.admin_timezone'); @endphp

<div class="card overflow-hidden animate-fadeUp">
    <div class="card-header flex-wrap gap-3">
        <div class="card-title"><i class="fa-solid fa-store"></i>Semua Bisnis</div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-60 !text-[12.5px]" placeholder="Nama bisnis / owner / email" aria-label="Cari bisnis">
            </div>
            <select wire:model.live="status" class="f-input !py-1.5 !w-40 !text-[12.5px]" aria-label="Status">
                <option value="">Semua status</option>
                <option value="active">Aktif</option>
                <option value="suspended">Ditangguhkan</option>
            </select>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>Bisnis</th><th>Owner</th><th class="text-right">Outlet</th><th class="text-right">Anggota</th><th class="text-right">Transaksi 30 hari</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($this->businesses as $business)
                    <tr wire:key="biz-{{ $business->id }}" class="cursor-pointer" onclick="Livewire.navigate('{{ route('admin.businesses.show', $business) }}')">
                        <td class="td-main">
                            <a href="{{ route('admin.businesses.show', $business) }}" wire:navigate class="link">{{ $business->name }}</a>
                            <div class="text-[11px] text-pb-muted font-normal">Daftar {{ $business->created_at->timezone($tz)->format('d/m/Y') }}</div>
                        </td>
                        <td class="text-[12.5px]">
                            {{ $business->owner?->name ?? '—' }}
                            @if ($business->owner?->isDeactivated())<span class="badge badge-red ml-1">Nonaktif</span>@endif
                            <div class="text-pb-muted">{{ $business->owner?->email }}</div>
                        </td>
                        <td class="text-right">{{ $business->outlets_count }}</td>
                        <td class="text-right">{{ $business->memberships_count }}</td>
                        <td class="text-right whitespace-nowrap">{{ Money::format((int) $business->revenue_30d) }}<div class="text-[11px] text-pb-muted">{{ $business->orders_30d }} pesanan</div></td>
                        <td><span class="badge {{ $business->isSuspended() ? 'badge-red' : 'badge-green' }}">{{ $business->isSuspended() ? 'Ditangguhkan' : 'Aktif' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center !py-8">Tidak ada bisnis yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->businesses->hasPages())
        <div class="px-5 py-3 border-t border-pb-border">{{ $this->businesses->links() }}</div>
    @endif
</div>
