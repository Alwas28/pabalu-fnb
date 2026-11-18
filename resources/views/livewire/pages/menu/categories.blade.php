<?php

use App\Models\MenuCategory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Kategori Menu')] class extends Component
{
    public ?int $editingId = null;

    public string $name = '';
    public string $sortOrder = '0';
    public bool $isActive = true;

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function categories()
    {
        return $this->business()->menuCategories()->withCount('items')->get();
    }

    #[On('category-create')]
    public function create(): void
    {
        $this->resetForm();
        $this->sortOrder = (string) (($this->categories->max('sort_order') ?? 0) + 1);
        $this->dispatch('open-modal', name: 'category-form');
    }

    public function edit(int $id): void
    {
        $category = $this->findCategory($id);

        $this->resetForm();
        $this->fill([
            'editingId' => $category->id,
            'name' => $category->name,
            'sortOrder' => (string) $category->sort_order,
            'isActive' => $category->is_active,
        ]);
        $this->dispatch('open-modal', name: 'category-form');
    }

    public function save(): void
    {
        $category = $this->editingId ? $this->findCategory($this->editingId) : null;

        $this->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('menu_categories', 'name')
                ->where('business_id', $this->business()->id)
                ->ignore($category?->id)],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['boolean'],
        ], ['name.unique' => 'Kategori dengan nama ini sudah ada.']);

        $data = ['name' => $this->name, 'sort_order' => (int) $this->sortOrder, 'is_active' => $this->isActive];

        $category
            ? $category->update($data)
            : $this->business()->menuCategories()->create($data);

        $this->dispatch('close-modal', name: 'category-form');
        $this->dispatch('toast', type: 'success', message: 'Kategori '.$this->name.' disimpan');
        $this->resetForm();
        unset($this->categories);
    }

    /** Menu di kategori yang dihapus tetap ada, hanya menjadi "Tanpa kategori". */
    public function delete(int $id): void
    {
        $category = $this->findCategory($id);
        $category->delete();

        $this->dispatch('toast', type: 'success', message: 'Kategori '.$category->name.' dihapus');
        unset($this->categories);
    }

    private function findCategory(int $id): MenuCategory
    {
        $category = $this->business()->menuCategories()->find($id);
        abort_unless($category, 404);

        return $category;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'sortOrder', 'isActive');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('category-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Kategori</span>
    </button>
</x-slot>

<div class="flex flex-col gap-5 max-w-3xl">
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-tags"></i>Kategori Menu</div>
                <div class="section-desc">Urutan kategori juga dipakai sebagai urutan tab di kasir.</div>
            </div>
            <span class="chip">{{ $this->categories->count() }} kategori</span>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr><th class="w-16">Urutan</th><th>Nama</th><th>Menu</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->categories as $category)
                        <tr wire:key="category-{{ $category->id }}" @class(['opacity-60' => ! $category->is_active])>
                            <td>{{ $category->sort_order }}</td>
                            <td class="td-main">{{ $category->name }}</td>
                            <td>
                                <a href="{{ route('menu.items.index', ['category' => $category->id]) }}" wire:navigate class="link">{{ $category->items_count }} menu</a>
                            </td>
                            <td><span class="badge {{ $category->is_active ? 'badge-green' : 'badge-gray' }}">{{ $category->is_active ? 'Tampil' : 'Disembunyikan' }}</span></td>
                            <td class="text-right">
                                <div class="inline-flex gap-1.5">
                                    <button type="button" wire:click="edit({{ $category->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-pen mr-1"></i>Ubah</button>
                                    <button type="button" wire:click="delete({{ $category->id }})"
                                            wire:confirm="Hapus kategori {{ $category->name }}? Menu di dalamnya tidak ikut terhapus."
                                            class="btn-ghost font-semibold" style="color:#f87171">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center !py-8">Belum ada kategori. Mulai dengan menambahkan kategori seperti "Minuman" atau "Makanan".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal name="category-form" maxWidth="md" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                <div class="font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Kategori' : 'Tambah Kategori' }}</div>
                <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4">
                <div class="grid grid-cols-[1fr_110px] gap-4">
                    <div>
                        <x-input-label for="name" value="Nama kategori" />
                        <x-text-input wire:model="name" id="name" placeholder="mis. Minuman Kopi" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="sortOrder" value="Urutan" />
                        <x-text-input wire:model="sortOrder" id="sortOrder" type="number" min="0" />
                        <x-input-error :messages="$errors->get('sortOrder')" class="mt-1.5" />
                    </div>
                </div>
                <x-toggle wire:model="isActive" label="Tampilkan di kasir" description="Kategori tersembunyi tidak muncul sebagai tab di kasir" />
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
