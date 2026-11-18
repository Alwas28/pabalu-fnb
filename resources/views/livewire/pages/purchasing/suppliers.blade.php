<?php

use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Supplier')] class extends Component
{
    public ?int $editingId = null;
    public string $name = '';
    public string $contactName = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $notes = '';
    public bool $isActive = true;

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function suppliers()
    {
        return $this->business()->suppliers()
            ->withCount('purchaseOrders')
            ->withSum(['purchaseOrders as payable' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'received_total')
            ->withSum(['purchaseOrders as returned' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'returned_total')
            ->withSum(['purchaseOrders as paid' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'paid_amount')
            ->get();
    }

    #[On('supplier-create')]
    public function create(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'supplier-form');
    }

    public function edit(int $id): void
    {
        $supplier = $this->find($id);
        $this->resetForm();
        $this->fill([
            'editingId' => $supplier->id,
            'name' => $supplier->name,
            'contactName' => (string) $supplier->contact_name,
            'phone' => (string) $supplier->phone,
            'email' => (string) $supplier->email,
            'address' => (string) $supplier->address,
            'notes' => (string) $supplier->notes,
            'isActive' => $supplier->is_active,
        ]);
        $this->dispatch('open-modal', name: 'supplier-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('suppliers', 'name')->where('business_id', $this->business()->id)->ignore($this->editingId)],
            'contactName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'isActive' => ['boolean'],
        ], ['name.unique' => 'Supplier dengan nama ini sudah ada.']);

        $data = [
            'name' => $this->name,
            'contact_name' => $this->contactName ?: null,
            'phone' => $this->phone ?: null,
            'email' => $this->email ?: null,
            'address' => $this->address ?: null,
            'notes' => $this->notes ?: null,
            'is_active' => $this->isActive,
        ];

        $this->editingId ? $this->find($this->editingId)->update($data) : $this->business()->suppliers()->create($data);

        $this->dispatch('close-modal', name: 'supplier-form');
        $this->dispatch('toast', type: 'success', message: 'Supplier '.$this->name.' disimpan');
        $this->resetForm();
        unset($this->suppliers);
    }

    private function find(int $id): Supplier
    {
        $supplier = $this->business()->suppliers()->find($id);
        abort_unless($supplier, 404);

        return $supplier;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'contactName', 'phone', 'email', 'address', 'notes', 'isActive');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('supplier-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Supplier</span>
    </button>
</x-slot>

<div class="flex flex-col gap-5">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('purchasing.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Pembelian</a>
        <span>/</span><span>Supplier</span>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Supplier</th><th>Kontak</th><th class="text-right">PO</th><th class="text-right">Sisa tagihan</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->suppliers as $supplier)
                        @php $outstanding = max(0, (int) $supplier->payable - (int) $supplier->returned - (int) $supplier->paid); @endphp
                        <tr wire:key="sup-{{ $supplier->id }}" @class(['opacity-60' => ! $supplier->is_active])>
                            <td class="td-main">{{ $supplier->name }}<div class="text-[11px] text-pb-muted font-normal">{{ $supplier->address }}</div></td>
                            <td class="text-[12.5px]">{{ $supplier->contact_name ?? '—' }}<div class="text-pb-muted">{{ $supplier->phone }}</div></td>
                            <td class="text-right">{{ $supplier->purchase_orders_count }}</td>
                            <td @class(['text-right font-semibold', 'trend-down' => $outstanding > 0])>{{ $outstanding ? Money::format($outstanding) : '—' }}</td>
                            <td><span class="badge {{ $supplier->is_active ? 'badge-green' : 'badge-gray' }}">{{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="text-right"><button type="button" wire:click="edit({{ $supplier->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-pen mr-1"></i>Ubah</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada supplier.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal name="supplier-form" maxWidth="lg" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Supplier' : 'Tambah Supplier' }}</div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <x-input-label for="name" value="Nama supplier" />
                    <x-text-input wire:model="name" id="name" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="contactName" value="Nama kontak" />
                    <x-text-input wire:model="contactName" id="contactName" />
                </div>
                <div>
                    <x-input-label for="phone" value="Telepon / WA" />
                    <x-text-input wire:model="phone" id="phone" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="email" value="Email" />
                    <x-text-input wire:model="email" id="email" type="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="address" value="Alamat" />
                    <textarea wire:model="address" id="address" rows="2" class="f-input resize-none"></textarea>
                </div>
                <div class="col-span-2">
                    <x-input-label for="notes" value="Catatan" />
                    <x-text-input wire:model="notes" id="notes" placeholder="mis. jadwal kirim, termin pembayaran" />
                </div>
                <div class="col-span-2"><x-toggle wire:model="isActive" label="Supplier aktif" /></div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
