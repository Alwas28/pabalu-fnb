<?php

use App\Enums\PaymentGateway;
use App\Enums\Permission;
use App\Models\Business;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Bisnis & Outlet')] class extends Component
{
    // Profil bisnis
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $timezone = 'Asia/Makassar';

    // Payment gateway (opsional)
    public bool $gatewayEnabled = false;
    public string $gatewayProvider = '';
    public bool $gatewaySandbox = true;
    public string $gatewayServerKey = '';
    public string $gatewayClientKey = '';
    public bool $hasGatewayCredentials = false;

    public const TIMEZONES = [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];

    #[Computed]
    public function business(): Business
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function outlets()
    {
        return $this->business->outlets()->get();
    }

    public function canManageGateway(): bool
    {
        return auth()->user()->hasPermission(Permission::ManagePaymentGateway);
    }

    public function mount(): void
    {
        $b = $this->business;

        $this->fill([
            'name' => $b->name,
            'phone' => (string) $b->phone,
            'email' => (string) $b->email,
            'address' => (string) $b->address,
            'timezone' => $b->timezone,
            'gatewayEnabled' => $b->payment_gateway_enabled,
            'gatewayProvider' => (string) $b->payment_gateway_provider?->value,
            'gatewaySandbox' => $b->payment_gateway_sandbox,
            'hasGatewayCredentials' => ! empty($b->payment_gateway_credentials),
        ]);
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', Rule::in(array_keys(self::TIMEZONES))],
        ]);

        $this->business->update(array_map(fn ($v) => $v === '' ? null : $v, $data));

        $this->dispatch('toast', type: 'success', message: 'Profil bisnis disimpan');
    }

    public function saveGateway(): void
    {
        abort_unless($this->canManageGateway(), 403);

        $this->validate([
            'gatewayEnabled' => ['boolean'],
            'gatewayProvider' => [Rule::requiredIf($this->gatewayEnabled), 'nullable', Rule::enum(PaymentGateway::class)],
            'gatewaySandbox' => ['boolean'],
            'gatewayServerKey' => [Rule::requiredIf($this->gatewayEnabled && ! $this->hasGatewayCredentials), 'nullable', 'string', 'max:255'],
            'gatewayClientKey' => ['nullable', 'string', 'max:255'],
        ], [
            'gatewayProvider.required' => 'Pilih provider payment gateway.',
            'gatewayServerKey.required' => 'Server key wajib diisi saat gateway diaktifkan.',
        ]);

        $business = $this->business;
        $credentials = $business->payment_gateway_credentials ?? [];

        // Kunci yang dikosongkan berarti "tetap gunakan yang tersimpan".
        if ($this->gatewayServerKey !== '') {
            $credentials['server_key'] = $this->gatewayServerKey;
        }
        if ($this->gatewayClientKey !== '') {
            $credentials['client_key'] = $this->gatewayClientKey;
        }

        $business->update([
            'payment_gateway_enabled' => $this->gatewayEnabled,
            'payment_gateway_provider' => $this->gatewayProvider ?: null,
            'payment_gateway_sandbox' => $this->gatewaySandbox,
            'payment_gateway_credentials' => $credentials ?: null,
        ]);

        $this->reset('gatewayServerKey', 'gatewayClientKey');
        $this->hasGatewayCredentials = ! empty($credentials);

        $this->dispatch('toast', type: 'success', message: $this->gatewayEnabled ? 'Payment gateway diaktifkan' : 'Pengaturan payment gateway disimpan');
    }
}; ?>

<x-slot name="actions">
    @can('create', App\Models\Outlet::class)
        <a href="{{ route('settings.outlets.create') }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]">
            <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah Outlet</span>
        </a>
    @endcan
</x-slot>

<div class="flex flex-col gap-5">
    {{-- ── Daftar outlet ── --}}
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-store"></i>Outlet</div>
            <span class="chip">{{ $this->outlets->count() }} Outlet</span>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Outlet</th>
                        <th>Layanan</th>
                        <th>Meja</th>
                        <th>Dapur</th>
                        <th>Pajak / Service</th>
                        <th>Pembayaran</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->outlets as $outlet)
                        <tr>
                            <td class="td-main">
                                {{ $outlet->name }}
                                <div class="text-[11px] text-pb-muted font-normal">{{ $outlet->code }}</div>
                            </td>
                            <td>
                                <div class="flex gap-1">
                                    @foreach ($outlet->service_types as $type)
                                        <span class="badge badge-blue">{{ $type->label() }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if ($outlet->usesTables())
                                    <span class="badge badge-green">Aktif</span>
                                @else
                                    <span class="badge badge-gray">Tidak</span>
                                @endif
                            </td>
                            <td><i class="fa-solid {{ $outlet->kitchen_mode->icon() }} mr-1"></i>{{ $outlet->usesKitchenDisplay() ? 'Layar' : 'Tiket' }}</td>
                            <td>
                                {{ $outlet->tax_enabled ? (float) $outlet->tax_rate.'%' : '—' }}
                                /
                                {{ $outlet->service_charge_enabled ? (float) $outlet->service_charge_rate.'%' : '—' }}
                            </td>
                            <td>{{ $outlet->payment_methods->count() }} metode</td>
                            <td>
                                <span class="badge {{ $outlet->is_active ? 'badge-green' : 'badge-gray' }}">{{ $outlet->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('settings.outlets.edit', $outlet) }}" wire:navigate class="btn-ghost font-semibold inline-flex items-center gap-1.5">
                                    <i class="fa-solid fa-sliders"></i>Atur
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center !py-8">Belum ada outlet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-2 items-start">
        {{-- ── Profil bisnis ── --}}
        <form wire:submit="saveProfile" class="card animate-fadeUp d1">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-building"></i>Profil Bisnis</div>
            </div>
            <div class="card-body flex flex-col gap-4">
                <div>
                    <x-input-label for="name" value="Nama bisnis" />
                    <x-text-input wire:model="name" id="name" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="phone" value="Telepon" />
                        <x-text-input wire:model="phone" id="phone" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="email" value="Email bisnis" />
                        <x-text-input wire:model="email" id="email" type="email" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                    </div>
                </div>
                <div>
                    <x-input-label for="address" value="Alamat" />
                    <textarea wire:model="address" id="address" rows="2" class="f-input resize-none"></textarea>
                    <x-input-error :messages="$errors->get('address')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="timezone" value="Zona waktu" />
                    <select wire:model="timezone" id="timezone" class="f-input">
                        @foreach ($this::TIMEZONES as $tz => $label)
                            <option value="{{ $tz }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('timezone')" class="mt-1.5" />
                </div>
                <div class="flex justify-end">
                    <x-primary-button wire:loading.attr="disabled" wire:target="saveProfile">
                        <i class="fa-solid fa-check"></i>Simpan Profil
                    </x-primary-button>
                </div>
            </div>
        </form>

        {{-- ── Payment gateway (opsional, khusus Owner) ── --}}
        @if ($this->canManageGateway())
            <form wire:submit="saveGateway" class="card animate-fadeUp d2">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-plug"></i>Payment Gateway</div>
                    <span class="badge {{ $this->business->paymentGatewayActive() ? 'badge-green' : 'badge-gray' }}">
                        {{ $this->business->paymentGatewayActive() ? 'Aktif' : 'Opsional · Nonaktif' }}
                    </span>
                </div>
                <div class="card-body flex flex-col gap-4">
                    <p class="text-[12.5px] text-pb-sub">
                        Tanpa payment gateway, pembayaran non-tunai (QRIS, kartu, transfer, e-wallet) tetap bisa dicatat manual oleh kasir.
                        Aktifkan bila ingin pembayaran diverifikasi otomatis oleh provider.
                    </p>

                    <x-toggle wire:model.live="gatewayEnabled" label="Aktifkan payment gateway" icon="fa-bolt"
                              description="Berlaku untuk semua outlet di bisnis ini" />

                    <div x-data x-show="$wire.gatewayEnabled" x-cloak class="flex flex-col gap-4">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="gatewayProvider" value="Provider" />
                                <select wire:model="gatewayProvider" id="gatewayProvider" class="f-input">
                                    <option value="">— Pilih provider —</option>
                                    @foreach (PaymentGateway::cases() as $provider)
                                        <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('gatewayProvider')" class="mt-1.5" />
                            </div>
                            <div class="flex items-end">
                                <x-toggle wire:model="gatewaySandbox" label="Mode sandbox" description="Untuk uji coba" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="gatewayServerKey" value="Server key" />
                            <x-text-input wire:model="gatewayServerKey" id="gatewayServerKey" type="password" autocomplete="off"
                                          placeholder="{{ $hasGatewayCredentials ? '•••••••• (tersimpan — kosongkan bila tidak diubah)' : 'Masukkan server key' }}" />
                            <x-input-error :messages="$errors->get('gatewayServerKey')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="gatewayClientKey" value="Client key (opsional)" />
                            <x-text-input wire:model="gatewayClientKey" id="gatewayClientKey" type="password" autocomplete="off"
                                          placeholder="{{ $hasGatewayCredentials ? 'Kosongkan bila tidak diubah' : 'Masukkan client key' }}" />
                            <p class="f-hint"><i class="fa-solid fa-lock mr-1"></i>Kunci disimpan terenkripsi dan tidak pernah ditampilkan kembali.</p>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button wire:loading.attr="disabled" wire:target="saveGateway">
                            <i class="fa-solid fa-check"></i>Simpan Gateway
                        </x-primary-button>
                    </div>
                </div>
            </form>
        @endif
    </div>
</div>
