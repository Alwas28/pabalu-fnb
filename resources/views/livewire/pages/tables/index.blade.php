<?php

use App\Models\DiningTable;
use App\Models\Outlet;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Meja & Area')] class extends Component
{
    public ?int $editingId = null;

    public string $area = '';
    public string $name = '';
    public string $capacity = '';
    public bool $isActive = true;

    // Tambah banyak
    public string $bulkArea = '';
    public string $bulkPrefix = '';
    public string $bulkStart = '1';
    public string $bulkCount = '5';
    public string $bulkCapacity = '';

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    #[Computed]
    public function tables()
    {
        return $this->outlet?->diningTables()
            ->with(['openOrders' => fn ($q) => $q->select('id', 'dining_table_id', 'number', 'total', 'placed_at')])
            ->get()
            ->groupBy(fn (DiningTable $t) => $t->area ?: 'Tanpa area') ?? collect();
    }

    #[Computed]
    public function areas(): array
    {
        return $this->outlet?->diningTables()->whereNotNull('area')->reorder('area')->distinct()->pluck('area')->all() ?? [];
    }

    private function managedOutlet(): Outlet
    {
        $outlet = $this->outlet;
        abort_unless($outlet && auth()->user()->canAccessOutlet($outlet), 403);

        return $outlet;
    }

    #[On('table-create')]
    public function create(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'table-form');
    }

    public function edit(int $id): void
    {
        $table = $this->findTable($id);

        $this->resetForm();
        $this->fill([
            'editingId' => $table->id,
            'area' => (string) $table->area,
            'name' => $table->name,
            'capacity' => (string) $table->capacity,
            'isActive' => $table->is_active,
        ]);
        $this->dispatch('open-modal', name: 'table-form');
    }

    public function save(): void
    {
        $outlet = $this->managedOutlet();
        $table = $this->editingId ? $this->findTable($this->editingId) : null;

        $this->validate([
            'area' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:20', Rule::unique('dining_tables', 'name')->where('outlet_id', $outlet->id)->ignore($table?->id)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'isActive' => ['boolean'],
        ], ['name.unique' => 'Nama meja sudah dipakai di outlet ini.']);

        $data = [
            'area' => $this->area ?: null,
            'name' => $this->name,
            'capacity' => $this->capacity ?: null,
            'is_active' => $this->isActive,
        ];

        $table ? $table->update($data) : $outlet->diningTables()->create($data + ['sort_order' => $outlet->diningTables()->count() + 1]);

        $this->dispatch('close-modal', name: 'table-form');
        $this->dispatch('toast', type: 'success', message: 'Meja '.$this->name.' disimpan');
        $this->resetForm();
        unset($this->tables, $this->areas);
    }

    public function saveBulk(): void
    {
        $outlet = $this->managedOutlet();

        $this->validate([
            'bulkArea' => ['nullable', 'string', 'max:60'],
            'bulkPrefix' => ['nullable', 'string', 'max:10'],
            'bulkStart' => ['required', 'integer', 'min:0', 'max:999'],
            'bulkCount' => ['required', 'integer', 'min:1', 'max:50'],
            'bulkCapacity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $names = collect(range((int) $this->bulkStart, (int) $this->bulkStart + (int) $this->bulkCount - 1))
            ->map(fn ($n) => $this->bulkPrefix.$n);
        $taken = $outlet->diningTables()->whereIn('name', $names)->pluck('name');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['bulkStart' => 'Meja '.$taken->join(', ').' sudah ada.']);
        }

        foreach ($names as $index => $name) {
            $outlet->diningTables()->create([
                'area' => $this->bulkArea ?: null,
                'name' => $name,
                'capacity' => $this->bulkCapacity ?: null,
                'sort_order' => (int) $this->bulkStart + $index,
            ]);
        }

        $this->dispatch('close-modal', name: 'table-bulk');
        $this->dispatch('toast', type: 'success', message: $names->count().' meja ditambahkan');
        $this->reset('bulkArea', 'bulkPrefix', 'bulkStart', 'bulkCount', 'bulkCapacity');
        unset($this->tables, $this->areas);
    }

    /** Kosongkan manual (mis. tamu sudah pergi setelah bayar di depan). Meja dengan open bill tidak bisa dikosongkan. */
    public function clear(int $id): void
    {
        $table = $this->findTable($id);

        if ($table->openOrders()->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Meja '.$table->name.' masih punya pesanan belum dibayar.');

            return;
        }

        $table->forceFill(['occupied_at' => null])->save();
        $this->dispatch('toast', type: 'success', message: 'Meja '.$table->name.' kosong');
        unset($this->tables);
    }

    public function delete(int $id): void
    {
        $table = $this->findTable($id);

        if ($table->openOrders()->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Meja dengan pesanan terbuka tidak bisa dihapus.');

            return;
        }

        $table->delete();
        $this->dispatch('toast', type: 'success', message: 'Meja '.$table->name.' dihapus');
        unset($this->tables, $this->areas);
    }

    private function findTable(int $id): DiningTable
    {
        $table = $this->managedOutlet()->diningTables()->find($id);
        abort_unless($table, 404);

        return $table;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'area', 'name', 'capacity', 'isActive');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    @if (auth()->user()->currentOutlet()?->usesTables())
        <button type="button" x-data x-on:click="$dispatch('open-modal', 'table-bulk')" class="btn btn-secondary !px-3 sm:!px-[18px]">
            <i class="fa-solid fa-layer-group"></i><span class="hidden sm:inline">Tambah Banyak</span>
        </button>
        <button type="button" x-data x-on:click="Livewire.dispatch('table-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
            <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Meja</span>
        </button>
    @endif
</x-slot>

<div class="flex flex-col gap-5">
    @php $outlet = $this->outlet; @endphp

    @if (! $outlet)
        <div class="card"><div class="card-body text-center text-pb-muted">Belum ada outlet yang ditugaskan.</div></div>
    @elseif (! $outlet->usesTables())
        <div class="card max-w-xl animate-fadeUp">
            <div class="card-body text-center py-10">
                <i class="fa-solid fa-chair text-3xl text-pb-muted mb-3"></i>
                <div class="font-display font-bold text-pb-text mb-1">{{ $outlet->name }} tidak memakai manajemen meja</div>
                <p class="text-[13px] text-pb-muted">Aktifkan layanan Dine-in dan "Gunakan manajemen meja" di pengaturan outlet.</p>
                @can('update', $outlet)
                    <a href="{{ route('settings.outlets.edit', $outlet) }}" wire:navigate class="btn btn-secondary mt-4"><i class="fa-solid fa-gear"></i>Pengaturan Outlet</a>
                @endcan
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-2 text-[12.5px] text-pb-muted">
            <span class="chip">{{ $this->tables->flatten(1)->count() }} meja</span>
            @if ($outlet->tracksTableStatus())
                <span class="chip"><span class="w-2 h-2 rounded-full bg-[#f87171] inline-block mr-1"></span>{{ $this->tables->flatten(1)->filter->isOccupied()->count() }} terisi</span>
                <span>Status meja dilacak otomatis dari pesanan dine-in.</span>
            @else
                <span>Pelacakan status meja nonaktif untuk outlet ini.</span>
            @endif
        </div>

        @forelse ($this->tables as $area => $tables)
            <div class="flex flex-col gap-3" wire:key="area-{{ $area }}">
                <div class="section-title">{{ $area }}</div>
                <div class="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                    @foreach ($tables as $table)
                        @php $openOrder = $table->openOrders->first(); @endphp
                        <div wire:key="table-{{ $table->id }}" @class([
                            'card p-4 flex flex-col gap-2',
                            'opacity-50' => ! $table->is_active,
                            '!border-[#f87171]' => $outlet->tracksTableStatus() && $table->isOccupied(),
                        ])>
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-display text-xl font-bold text-pb-text">{{ $table->name }}</div>
                                    <div class="text-[11.5px] text-pb-muted">{{ $table->capacity ? $table->capacity.' kursi' : '—' }}{{ $table->is_active ? '' : ' · nonaktif' }}</div>
                                </div>
                                @if ($outlet->tracksTableStatus())
                                    <span class="badge {{ $table->isOccupied() ? 'badge-red' : 'badge-green' }}">{{ $table->isOccupied() ? 'Terisi' : 'Kosong' }}</span>
                                @endif
                            </div>
                            @if ($openOrder)
                                <a href="{{ route('orders.show', $openOrder->id) }}" wire:navigate class="text-[12px] link">{{ $openOrder->number }} · {{ Money::format($openOrder->total) }}</a>
                            @endif
                            <div class="flex gap-1 mt-auto">
                                <button type="button" wire:click="edit({{ $table->id }})" class="btn-ghost text-[12px] !px-2"><i class="fa-solid fa-pen"></i></button>
                                @if ($outlet->tracksTableStatus() && $table->isOccupied() && ! $openOrder)
                                    <button type="button" wire:click="clear({{ $table->id }})" class="btn-ghost text-[12px] !px-2 font-semibold">Kosongkan</button>
                                @endif
                                @unless ($openOrder)
                                    <button type="button" wire:click="delete({{ $table->id }})" wire:confirm="Hapus meja {{ $table->name }}?" class="btn-ghost text-[12px] !px-2 ml-auto" style="color:#f87171" aria-label="Hapus"><i class="fa-solid fa-trash"></i></button>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="card"><div class="card-body text-center text-pb-muted">Belum ada meja. Gunakan "Tambah Banyak" untuk membuat meja sekaligus, mis. A1–A10.</div></div>
        @endforelse

        <x-modal name="table-form" maxWidth="md" focusable>
            <form wire:submit="save">
                <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Meja' : 'Tambah Meja' }}</div>
                <div class="px-6 py-5 grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="name" value="Nomor / nama meja" />
                        <x-text-input wire:model="name" id="name" placeholder="mis. A1" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="capacity" value="Kapasitas (kursi)" />
                        <x-text-input wire:model="capacity" id="capacity" type="number" min="1" />
                        <x-input-error :messages="$errors->get('capacity')" class="mt-1.5" />
                    </div>
                    <div class="col-span-2">
                        <x-input-label for="area" value="Area (opsional)" />
                        <x-text-input wire:model="area" id="area" list="area-options" placeholder="mis. Indoor, Teras, Lantai 2" />
                        <datalist id="area-options">@foreach ($this->areas as $a)<option value="{{ $a }}">@endforeach</datalist>
                    </div>
                    <div class="col-span-2">
                        <x-toggle wire:model="isActive" label="Meja aktif" description="Meja nonaktif tidak bisa dipilih di kasir" />
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal name="table-bulk" maxWidth="md" focusable>
            <form wire:submit="saveBulk">
                <div class="px-6 py-5 border-b border-pb-border">
                    <div class="font-display text-base font-bold text-pb-text">Tambah Banyak Meja</div>
                    <div class="text-xs text-pb-muted mt-px">Contoh: awalan "A", mulai 1, jumlah 6 → A1 sampai A6</div>
                </div>
                <div class="px-6 py-5 grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <x-input-label for="bulkArea" value="Area (opsional)" />
                        <x-text-input wire:model="bulkArea" id="bulkArea" list="area-options" placeholder="mis. Indoor" />
                    </div>
                    <div>
                        <x-input-label for="bulkPrefix" value="Awalan" />
                        <x-text-input wire:model="bulkPrefix" id="bulkPrefix" placeholder="mis. A" />
                    </div>
                    <div>
                        <x-input-label for="bulkCapacity" value="Kapasitas" />
                        <x-text-input wire:model="bulkCapacity" id="bulkCapacity" type="number" min="1" />
                    </div>
                    <div>
                        <x-input-label for="bulkStart" value="Mulai dari nomor" />
                        <x-text-input wire:model="bulkStart" id="bulkStart" type="number" min="0" required />
                        <x-input-error :messages="$errors->get('bulkStart')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="bulkCount" value="Jumlah meja" />
                        <x-text-input wire:model="bulkCount" id="bulkCount" type="number" min="1" max="50" required />
                        <x-input-error :messages="$errors->get('bulkCount')" class="mt-1.5" />
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="saveBulk"><i class="fa-solid fa-check"></i>Tambahkan</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
