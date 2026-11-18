<?php

use App\Models\MenuItem;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Daftar Menu')] class extends Component
{
    use WithPagination;

    #[Url(as: 'category')]
    public string $categoryId = '';

    #[Url(as: 'q')]
    public string $search = '';

    /** active | archived | all */
    #[Url]
    public string $status = 'active';

    public function updated(string $property): void
    {
        if (in_array($property, ['categoryId', 'search', 'status'], true)) {
            $this->resetPage();
        }
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function outlet()
    {
        return auth()->user()->currentOutlet();
    }

    #[Computed]
    public function categories()
    {
        return $this->business()->menuCategories()->get();
    }

    #[Computed]
    public function items()
    {
        $outlet = $this->outlet;

        return $this->business()->menuItems()
            ->with(['category', 'outlets' => fn ($q) => $outlet ? $q->whereKey($outlet->id) : $q->whereRaw('1 = 0')])
            ->withCount(['variants', 'modifierGroups'])
            ->when($this->categoryId === 'none', fn ($q) => $q->whereNull('menu_category_id'))
            ->when(is_numeric($this->categoryId), fn ($q) => $q->where('menu_category_id', (int) $this->categoryId))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('sku', 'like', '%'.$this->search.'%')))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'archived', fn ($q) => $q->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25);
    }

    /** Tandai habis / tersedia kembali di outlet aktif (tanpa mengubah harga outlet). */
    public function toggleAvailability(int $id): void
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        $item = $this->business()->menuItems()->with(['outlets' => fn ($q) => $q->whereKey($outlet->id)])->findOrFail($id);
        $available = ! $item->isAvailableAt($outlet);

        $item->outlets()->syncWithoutDetaching([$outlet->id => ['is_available' => $available]]);
        \App\Models\AuditLog::record($item->business_id, $item, 'outlet_price', $item->name,
            [$outlet->name => [$available ? 'habis' : 'tersedia', $available ? 'tersedia' : 'habis']]);

        $this->dispatch('toast', type: 'success', message: $item->name.($available ? ' tersedia kembali' : ' ditandai habis').' di '.$outlet->name);
        unset($this->items);
    }
}; ?>

<x-slot name="actions">
    <a href="{{ route('menu.items.create') }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Menu</span>
    </a>
</x-slot>

<div class="flex flex-col gap-5">
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header flex-wrap gap-3">
            <div class="card-title"><i class="fa-solid fa-utensils"></i>Menu</div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-52 !text-[12.5px]" placeholder="Cari nama / SKU">
                </div>
                <select wire:model.live="categoryId" class="f-input !py-1.5 !w-44 !text-[12.5px]" aria-label="Kategori">
                    <option value="">Semua kategori</option>
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                    <option value="none">Tanpa kategori</option>
                </select>
                <select wire:model.live="status" class="f-input !py-1.5 !w-32 !text-[12.5px]" aria-label="Status">
                    <option value="active">Aktif</option>
                    <option value="archived">Diarsipkan</option>
                    <option value="all">Semua</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Menu</th>
                        <th>Kategori</th>
                        <th class="text-right">Harga dasar</th>
                        <th>Opsi</th>
                        @if ($this->outlet)<th>Di {{ $this->outlet->name }}</th>@endif
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->items as $item)
                        @php($outletPrice = $this->outlet ? $item->priceAt($this->outlet) : $item->price)
                        <tr wire:key="item-{{ $item->id }}" @class(['opacity-60' => ! $item->is_active])>
                            <td class="td-main">
                                <div class="flex items-center gap-2.5">
                                    @if ($item->imageUrl())
                                        <img src="{{ $item->imageUrl() }}" alt="" class="w-9 h-9 rounded-lg object-cover shrink-0">
                                    @else
                                        <div class="a-bg-lt a-text w-9 h-9 rounded-lg grid place-items-center shrink-0"><i class="fa-solid fa-mug-hot text-xs"></i></div>
                                    @endif
                                    <div>
                                        {{ $item->name }}
                                        @if (! $item->is_active)<span class="chip !text-[10px] !py-0.5 ml-1">Arsip</span>@endif
                                        <div class="text-[11px] text-pb-muted font-normal">{{ $item->sku ?: 'Tanpa SKU' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $item->category?->name ?? '—' }}</td>
                            <td class="text-right whitespace-nowrap">
                                {{ Money::format($item->price) }}
                                @if ($this->outlet && $outletPrice !== $item->price)
                                    <div class="text-[11px] a-text">{{ Money::format($outletPrice) }} di outlet ini</div>
                                @endif
                            </td>
                            <td class="text-[12px] text-pb-muted whitespace-nowrap">
                                {{ $item->variants_count }} varian · {{ $item->modifier_groups_count }} modifier
                            </td>
                            @if ($this->outlet)
                                <td>
                                    @if ($item->is_active)
                                        <button type="button" wire:click="toggleAvailability({{ $item->id }})"
                                                class="badge {{ $item->isAvailableAt($this->outlet) ? 'badge-green' : 'badge-red' }} cursor-pointer"
                                                title="Klik untuk mengubah">
                                            {{ $item->isAvailableAt($this->outlet) ? 'Tersedia' : 'Habis' }}
                                        </button>
                                    @else
                                        <span class="text-pb-muted">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="text-right">
                                <a href="{{ route('menu.items.edit', $item) }}" wire:navigate class="btn-ghost font-semibold"><i class="fa-solid fa-pen mr-1"></i>Ubah</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">
                            Belum ada menu yang cocok.
                            <a href="{{ route('menu.items.create') }}" wire:navigate class="link ml-1">Tambah menu</a>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->items->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->items->links() }}</div>
        @endif
    </div>
</div>
