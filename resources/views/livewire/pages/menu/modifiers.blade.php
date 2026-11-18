<?php

use App\Models\ModifierGroup;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Modifier & Topping')] class extends Component
{
    public ?int $editingId = null;

    public string $name = '';
    public string $minSelect = '0';
    /** Kosong = tanpa batas. */
    public string $maxSelect = '1';

    /** @var list<array{id: ?int, name: string, price: string, is_available: bool}> */
    public array $options = [];

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function groups()
    {
        return $this->business()->modifierGroups()->with('modifiers')->withCount('menuItems')->get();
    }

    #[On('modifier-create')]
    public function create(): void
    {
        $this->resetForm();
        $this->options = [$this->blankOption(), $this->blankOption()];
        $this->dispatch('open-modal', name: 'modifier-form');
    }

    public function edit(int $id): void
    {
        $group = $this->findGroup($id);

        $this->resetForm();
        $this->fill([
            'editingId' => $group->id,
            'name' => $group->name,
            'minSelect' => (string) $group->min_select,
            'maxSelect' => $group->max_select === null ? '' : (string) $group->max_select,
            'options' => $group->modifiers->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price' => (string) $m->price,
                'is_available' => $m->is_available,
            ])->all(),
        ]);
        $this->dispatch('open-modal', name: 'modifier-form');
    }

    public function addOption(): void
    {
        $this->options[] = $this->blankOption();
    }

    public function removeOption(int $index): void
    {
        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function save(): void
    {
        $group = $this->editingId ? $this->findGroup($this->editingId) : null;

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'minSelect' => ['required', 'integer', 'min:0', 'max:20'],
            'maxSelect' => ['nullable', 'integer', 'min:1', 'max:20', 'gte:minSelect'],
            'options' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'options.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'options.*.is_available' => ['boolean'],
        ], [
            'maxSelect.gte' => 'Maksimal pilihan tidak boleh kurang dari minimal.',
            'options.required' => 'Tambahkan minimal satu pilihan.',
            'options.*.name.required' => 'Nama pilihan wajib diisi.',
            'options.*.name.distinct' => 'Nama pilihan tidak boleh sama.',
        ], [
            'options.*.price' => 'harga',
        ]);

        if ((int) $this->minSelect > count($this->options)) {
            throw ValidationException::withMessages(['minSelect' => 'Minimal pilihan melebihi jumlah pilihan yang tersedia.']);
        }

        DB::transaction(function () use ($group) {
            $data = [
                'name' => $this->name,
                'min_select' => (int) $this->minSelect,
                'max_select' => $this->maxSelect === '' ? null : (int) $this->maxSelect,
            ];

            $group = $group ? tap($group)->update($data) : $this->business()->modifierGroups()->create($data);

            $keep = [];
            foreach (array_values($this->options) as $index => $option) {
                $attributes = [
                    'name' => $option['name'],
                    'price' => (int) ($option['price'] ?: 0),
                    'is_available' => (bool) $option['is_available'],
                    'sort_order' => $index,
                ];

                $modifier = $option['id'] ? $group->modifiers()->find($option['id']) : null;
                $modifier = $modifier ? tap($modifier)->update($attributes) : $group->modifiers()->create($attributes);
                $keep[] = $modifier->id;
            }

            // Riwayat order menyimpan salinan nama & harga, jadi pilihan lama aman dihapus.
            $group->modifiers()->whereNotIn('id', $keep)->delete();
        });

        $this->dispatch('close-modal', name: 'modifier-form');
        $this->dispatch('toast', type: 'success', message: 'Grup '.$this->name.' disimpan');
        $this->resetForm();
        unset($this->groups);
    }

    public function delete(int $id): void
    {
        $group = $this->findGroup($id);
        $group->delete();

        $this->dispatch('toast', type: 'success', message: 'Grup '.$group->name.' dihapus');
        unset($this->groups);
    }

    private function findGroup(int $id): ModifierGroup
    {
        $group = $this->business()->modifierGroups()->find($id);
        abort_unless($group, 404);

        return $group;
    }

    /** @return array{id: null, name: string, price: string, is_available: bool} */
    private function blankOption(): array
    {
        return ['id' => null, 'name' => '', 'price' => '0', 'is_available' => true];
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'minSelect', 'maxSelect', 'options');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('modifier-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Grup</span>
    </button>
</x-slot>

<div class="flex flex-col gap-5">
    <p class="text-[12.5px] text-pb-muted -mb-1 max-w-3xl">
        Grup modifier adalah pilihan tambahan seperti <b>Level Gula</b>, <b>Level Pedas</b> atau <b>Topping</b>.
        Satu grup bisa dipasang ke banyak menu dari halaman ubah menu.
    </p>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->groups as $group)
            <div class="card animate-fadeUp" wire:key="group-{{ $group->id }}">
                <div class="card-header">
                    <div class="min-w-0">
                        <div class="card-title truncate"><i class="fa-solid fa-layer-group"></i>{{ $group->name }}</div>
                        <div class="section-desc">{{ $group->ruleLabel() }} · dipakai {{ $group->menu_items_count }} menu</div>
                    </div>
                    <div class="flex gap-1 shrink-0">
                        <button type="button" wire:click="edit({{ $group->id }})" class="icon-btn !w-8 !h-8" aria-label="Ubah"><i class="fa-solid fa-pen text-xs"></i></button>
                        <button type="button" wire:click="delete({{ $group->id }})" wire:confirm="Hapus grup {{ $group->name }}? Grup akan dilepas dari semua menu."
                                class="icon-btn !w-8 !h-8" aria-label="Hapus" style="color:#f87171"><i class="fa-solid fa-trash text-xs"></i></button>
                    </div>
                </div>
                <div class="py-2">
                    @foreach ($group->modifiers as $modifier)
                        <div class="list-row !py-1.5 text-[13px]" wire:key="mod-{{ $modifier->id }}">
                            <span @class(['flex-1', 'line-through text-pb-muted' => ! $modifier->is_available])>{{ $modifier->name }}</span>
                            <span class="text-pb-muted">{{ $modifier->price > 0 ? '+'.Money::format($modifier->price) : 'Gratis' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="card md:col-span-2 xl:col-span-3"><div class="card-body text-center text-pb-muted">Belum ada grup modifier.</div></div>
        @endforelse
    </div>

    <x-modal name="modifier-form" maxWidth="xl" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                <div class="font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Grup Modifier' : 'Tambah Grup Modifier' }}</div>
                <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4 max-h-[65vh] overflow-y-auto">
                <div>
                    <x-input-label for="name" value="Nama grup" />
                    <x-text-input wire:model="name" id="name" placeholder="mis. Level Gula" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="minSelect" value="Minimal dipilih" />
                        <x-text-input wire:model="minSelect" id="minSelect" type="number" min="0" />
                        <p class="f-hint">0 = opsional</p>
                        <x-input-error :messages="$errors->get('minSelect')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="maxSelect" value="Maksimal dipilih" />
                        <x-text-input wire:model="maxSelect" id="maxSelect" type="number" min="1" placeholder="Tanpa batas" />
                        <p class="f-hint">1 = pilih salah satu; kosong = tanpa batas</p>
                        <x-input-error :messages="$errors->get('maxSelect')" class="mt-1.5" />
                    </div>
                </div>

                <div>
                    <x-input-label value="Pilihan" />
                    <div class="flex flex-col gap-2">
                        @foreach ($options as $i => $option)
                            <div class="grid grid-cols-[1fr_130px_auto_auto] gap-2 items-start" wire:key="opt-{{ $i }}-{{ $option['id'] ?? 'new' }}">
                                <div>
                                    <input type="text" wire:model="options.{{ $i }}.name" class="f-input" placeholder="mis. Less Sugar" aria-label="Nama pilihan">
                                    <x-input-error :messages="$errors->get('options.'.$i.'.name')" class="mt-1" />
                                </div>
                                <div>
                                    <input type="number" wire:model="options.{{ $i }}.price" class="f-input" min="0" placeholder="Harga +" aria-label="Tambahan harga">
                                    <x-input-error :messages="$errors->get('options.'.$i.'.price')" class="mt-1" />
                                </div>
                                <label class="flex items-center gap-1.5 text-[12px] text-pb-sub pt-2.5" title="Tersedia">
                                    <input type="checkbox" wire:model="options.{{ $i }}.is_available" class="switch"> Ada
                                </label>
                                <button type="button" wire:click="removeOption({{ $i }})" class="icon-btn !w-9 !h-9" aria-label="Hapus pilihan"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('options')" class="mt-1.5" />
                    <button type="button" wire:click="addOption" class="btn-ghost font-semibold mt-2"><i class="fa-solid fa-plus mr-1"></i>Tambah pilihan</button>
                    <p class="f-hint">Harga pilihan ditambahkan ke harga menu. Isi 0 bila gratis.</p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
