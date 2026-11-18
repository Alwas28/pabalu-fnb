<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Pelanggan')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $editingId = null;
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $birthday = '';
    public string $notes = '';

    /** Pelanggan yang riwayatnya sedang dilihat. */
    public ?int $viewingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function customers()
    {
        $cancelled = OrderStatus::Cancelled->value;

        return $this->business()->customers()
            ->when($this->search !== '', fn ($q) => Customer::applySearch($q, $this->search))
            ->withCount(['orders as visits' => fn ($q) => $q->where('status', '!=', $cancelled)])
            ->withSum(['orders as spent' => fn ($q) => $q->where('status', '!=', $cancelled)], 'paid_amount')
            ->withMax(['orders as last_visit' => fn ($q) => $q->where('status', '!=', $cancelled)], 'placed_at')
            ->paginate(25);
    }

    #[Computed]
    public function viewing(): ?Customer
    {
        return $this->viewingId ? $this->business()->customers()->find($this->viewingId) : null;
    }

    #[Computed]
    public function history()
    {
        return $this->viewing?->orders()->with('outlet')->latest('placed_at')->limit(30)->get() ?? collect();
    }

    #[On('customer-create')]
    public function create(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'customer-form');
    }

    public function edit(int $id): void
    {
        $customer = $this->find($id);
        $this->resetForm();
        $this->fill([
            'editingId' => $customer->id,
            'name' => $customer->name,
            'phone' => (string) $customer->phone,
            'email' => (string) $customer->email,
            'birthday' => (string) $customer->birthday?->toDateString(),
            'notes' => (string) $customer->notes,
        ]);
        $this->dispatch('open-modal', name: 'customer-form');
    }

    public function save(): void
    {
        $this->phone = (string) Customer::normalizePhone($this->phone);

        $this->validate(Customer::rules($this->business()->id, $this->editingId), ['phone.unique' => 'Nomor HP sudah terdaftar untuk pelanggan lain.']);

        $data = [
            'name' => $this->name,
            'phone' => $this->phone ?: null,
            'email' => $this->email ?: null,
            'birthday' => $this->birthday ?: null,
            'notes' => $this->notes ?: null,
        ];

        $this->editingId ? $this->find($this->editingId)->update($data) : $this->business()->customers()->create($data);

        $this->dispatch('close-modal', name: 'customer-form');
        $this->dispatch('toast', type: 'success', message: 'Pelanggan '.$this->name.' disimpan');
        $this->resetForm();
        unset($this->customers, $this->viewing);
    }

    public function view(int $id): void
    {
        $this->find($id);
        $this->viewingId = $id;
        unset($this->viewing, $this->history);
        $this->dispatch('open-modal', name: 'customer-history');
    }

    private function find(int $id): Customer
    {
        $customer = $this->business()->customers()->find($id);
        abort_unless($customer, 404);

        return $customer;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'phone', 'email', 'birthday', 'notes');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('customer-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
        <i class="fa-solid fa-user-plus"></i><span class="hidden sm:inline">Tambah Pelanggan</span>
    </button>
</x-slot>

<div class="flex flex-col gap-5">
    @php $tz = auth()->user()->activeBusiness()->timezone; @endphp
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-users"></i>Pelanggan</div>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-56 !text-[12.5px]" placeholder="Nama / nomor HP">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Nama</th><th>Kontak</th><th class="text-right">Kunjungan</th><th class="text-right">Total belanja</th><th>Terakhir</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->customers as $customer)
                        <tr wire:key="cus-{{ $customer->id }}">
                            <td class="td-main">
                                <button type="button" wire:click="view({{ $customer->id }})" class="link">{{ $customer->name }}</button>
                                @if ($customer->notes)<div class="text-[11px] text-pb-muted font-normal truncate max-w-[260px]">{{ $customer->notes }}</div>@endif
                            </td>
                            <td class="text-[12.5px]">{{ $customer->phone ?? '—' }}<div class="text-pb-muted">{{ $customer->email }}</div></td>
                            <td class="text-right">{{ $customer->visits }}</td>
                            <td class="text-right font-semibold">{{ Money::format((int) $customer->spent) }}</td>
                            <td class="text-[12.5px]">{{ $customer->last_visit ? \Illuminate\Support\Carbon::parse($customer->last_visit)->timezone($tz)->format('d/m/Y') : '—' }}</td>
                            <td class="text-right"><button type="button" wire:click="edit({{ $customer->id }})" class="btn-ghost font-semibold"><i class="fa-solid fa-pen mr-1"></i>Ubah</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada pelanggan. Pelanggan juga bisa ditambahkan langsung dari kasir.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->customers->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->customers->links() }}</div>
        @endif
    </div>

    <x-modal name="customer-form" maxWidth="md" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Pelanggan' : 'Tambah Pelanggan' }}</div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <x-input-label for="name" value="Nama" />
                    <x-text-input wire:model="name" id="name" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="phone" value="Nomor HP" />
                    <x-text-input wire:model="phone" id="phone" placeholder="08…" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="birthday" value="Tanggal lahir" />
                    <x-text-input wire:model="birthday" id="birthday" type="date" />
                    <x-input-error :messages="$errors->get('birthday')" class="mt-1.5" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="email" value="Email" />
                    <x-text-input wire:model="email" id="email" type="email" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                </div>
                <div class="col-span-2">
                    <x-input-label for="notes" value="Catatan" />
                    <x-text-input wire:model="notes" id="notes" placeholder="mis. alergi kacang, suka kopi tanpa gula" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="customer-history" maxWidth="xl">
        @if ($customer = $this->viewing)
            <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                <div>
                    <div class="font-display text-base font-bold text-pb-text">{{ $customer->name }}</div>
                    <div class="text-xs text-pb-muted mt-px">{{ $customer->phone }}{{ $customer->notes ? ' · '.$customer->notes : '' }}</div>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <table class="tbl">
                    <thead><tr><th>Order</th><th>Tanggal</th><th>Outlet</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($this->history as $order)
                            <tr wire:key="ch-{{ $order->id }}">
                                <td class="td-main">
                                    @can('view', $order)
                                        <a href="{{ route('orders.show', $order) }}" wire:navigate class="link">{{ $order->number }}</a>
                                    @else
                                        {{ $order->number }}
                                    @endcan
                                </td>
                                <td>{{ $order->placed_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                                <td>{{ $order->outlet->name }}</td>
                                <td class="text-right">{{ Money::format($order->total) }}</td>
                                <td><span class="badge {{ $order->status->badge() }}">{{ $order->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center !py-8">Belum ada order.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-modal>
</div>
