<?php

use App\Enums\KitchenMode;
use App\Enums\PaymentMethod;
use App\Enums\ServiceType;
use App\Models\Outlet;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?Outlet $outlet = null;

    // Informasi
    public string $code = '';
    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $opensAt = '';
    public string $closesAt = '';
    public bool $isActive = true;

    // Layanan & operasional
    /** @var list<string> */
    public array $serviceTypes = ['dine_in', 'takeaway'];
    public bool $tablesEnabled = false;
    public string $kitchenMode = 'ticket';

    // Pajak & service charge
    public bool $taxEnabled = false;
    public string $taxRate = '10';
    public bool $serviceChargeEnabled = false;
    public string $serviceChargeRate = '5';
    public bool $pricesIncludeTax = false;

    // Pembayaran
    /** @var list<string> */
    public array $paymentMethods = ['cash', 'qris'];

    public function mount(?Outlet $outlet = null): void
    {
        if ($outlet?->exists) {
            $this->authorize('update', $outlet);
            $this->outlet = $outlet;

            $this->fill([
                'code' => $outlet->code,
                'name' => $outlet->name,
                'phone' => (string) $outlet->phone,
                'address' => (string) $outlet->address,
                'opensAt' => substr((string) $outlet->opens_at, 0, 5),
                'closesAt' => substr((string) $outlet->closes_at, 0, 5),
                'isActive' => $outlet->is_active,
                'serviceTypes' => $outlet->service_types->map->value->all(),
                'tablesEnabled' => $outlet->tables_enabled,
                'kitchenMode' => $outlet->kitchen_mode->value,
                'taxEnabled' => $outlet->tax_enabled,
                'taxRate' => (string) (float) $outlet->tax_rate,
                'serviceChargeEnabled' => $outlet->service_charge_enabled,
                'serviceChargeRate' => (string) (float) $outlet->service_charge_rate,
                'pricesIncludeTax' => $outlet->prices_include_tax,
                'paymentMethods' => $outlet->payment_methods->map->value->all(),
            ]);
        } else {
            $this->authorize('create', Outlet::class);
            $this->code = $this->suggestCode();
        }
    }

    public function rendering($view): void
    {
        $view->title($this->outlet ? 'Atur '.$this->outlet->name : 'Tambah Outlet');
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    private function suggestCode(): string
    {
        $count = $this->business()->outlets()->count() + 1;

        return 'OUT'.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
    }

    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('outlets', 'code')
                    ->where('business_id', $this->business()->id)
                    ->ignore($this->outlet?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'opensAt' => ['nullable', 'date_format:H:i'],
            'closesAt' => ['nullable', 'date_format:H:i'],
            'isActive' => ['boolean'],
            'serviceTypes' => ['required', 'array', 'min:1'],
            'serviceTypes.*' => [Rule::enum(ServiceType::class)],
            'tablesEnabled' => ['boolean'],
            'kitchenMode' => ['required', Rule::enum(KitchenMode::class)],
            'taxEnabled' => ['boolean'],
            'taxRate' => [Rule::requiredIf($this->taxEnabled), 'nullable', 'numeric', 'min:0', 'max:100'],
            'serviceChargeEnabled' => ['boolean'],
            'serviceChargeRate' => [Rule::requiredIf($this->serviceChargeEnabled), 'nullable', 'numeric', 'min:0', 'max:100'],
            'pricesIncludeTax' => ['boolean'],
            'paymentMethods' => ['required', 'array', 'min:1'],
            'paymentMethods.*' => [Rule::enum(PaymentMethod::class)],
        ];
    }

    protected function messages(): array
    {
        return [
            'serviceTypes.required' => 'Aktifkan minimal satu jenis layanan.',
            'serviceTypes.min' => 'Aktifkan minimal satu jenis layanan.',
            'paymentMethods.required' => 'Aktifkan minimal satu metode pembayaran.',
            'paymentMethods.min' => 'Aktifkan minimal satu metode pembayaran.',
            'code.unique' => 'Kode outlet sudah dipakai di bisnis ini.',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'code' => strtoupper($this->code),
            'name' => $this->name,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'opens_at' => $this->opensAt ?: null,
            'closes_at' => $this->closesAt ?: null,
            'is_active' => $this->isActive,
            // Urutkan sesuai urutan enum agar konsisten.
            'service_types' => array_values(array_filter(ServiceType::cases(), fn ($t) => in_array($t->value, $this->serviceTypes, true))),
            'tables_enabled' => $this->tablesEnabled,
            'kitchen_mode' => $this->kitchenMode,
            'tax_enabled' => $this->taxEnabled,
            'tax_rate' => $this->taxEnabled ? $this->taxRate : 0,
            'service_charge_enabled' => $this->serviceChargeEnabled,
            'service_charge_rate' => $this->serviceChargeEnabled ? $this->serviceChargeRate : 0,
            'prices_include_tax' => $this->taxEnabled && $this->pricesIncludeTax,
            'payment_methods' => array_values(array_filter(PaymentMethod::cases(), fn ($m) => in_array($m->value, $this->paymentMethods, true))),
        ];

        if ($this->outlet) {
            $this->authorize('update', $this->outlet);
            $this->outlet->update($data);
        } else {
            $this->authorize('create', Outlet::class);
            $this->outlet = $this->business()->outlets()->create($data);
        }

        session()->flash('toast', 'Pengaturan '.$this->outlet->name.' disimpan');

        $this->redirectRoute('settings.business', navigate: true);
    }

    public function with(): array
    {
        $business = $this->business();

        return [
            'gatewayActive' => $business->paymentGatewayActive(),
            'gatewayLabel' => $business->payment_gateway_provider?->label(),
        ];
    }
}; ?>

<form wire:submit="save" class="flex flex-col gap-5 max-w-4xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('settings.business') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Bisnis &amp; Outlet</a>
        <span>/</span>
        <span>{{ $outlet ? $outlet->name : 'Outlet baru' }}</span>
    </div>

    {{-- ── Informasi outlet ── --}}
    <div class="card animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-store"></i>Informasi Outlet</div>
        </div>
        <div class="card-body grid gap-4 sm:grid-cols-[160px_1fr]">
            <div>
                <x-input-label for="code" value="Kode" />
                <x-text-input wire:model="code" id="code" class="uppercase" required />
                <x-input-error :messages="$errors->get('code')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="name" value="Nama outlet" />
                <x-text-input wire:model="name" id="name" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <div class="sm:col-span-2 grid gap-4 sm:grid-cols-3">
                <div>
                    <x-input-label for="phone" value="Telepon" />
                    <x-text-input wire:model="phone" id="phone" />
                </div>
                <div>
                    <x-input-label for="opensAt" value="Jam buka" />
                    <x-text-input wire:model="opensAt" id="opensAt" type="time" />
                    <x-input-error :messages="$errors->get('opensAt')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="closesAt" value="Jam tutup" />
                    <x-text-input wire:model="closesAt" id="closesAt" type="time" />
                    <x-input-error :messages="$errors->get('closesAt')" class="mt-1.5" />
                </div>
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="address" value="Alamat" />
                <textarea wire:model="address" id="address" rows="2" class="f-input resize-none"></textarea>
            </div>
            <div class="sm:col-span-2">
                <x-toggle wire:model="isActive" label="Outlet aktif" icon="fa-power-off"
                          description="Outlet nonaktif tidak bisa dipakai untuk transaksi di kasir" />
            </div>
        </div>
    </div>

    {{-- ── Layanan & meja ── --}}
    <div class="card animate-fadeUp d1">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-bell-concierge"></i>Jenis Layanan</div>
                <div class="section-desc">Aktifkan layanan yang tersedia di outlet ini. Kasir hanya bisa memilih layanan yang aktif.</div>
            </div>
        </div>
        <div class="card-body flex flex-col gap-3">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (ServiceType::cases() as $type)
                    <x-toggle wire:model.live="serviceTypes" value="{{ $type->value }}"
                              :label="$type->label()" :description="$type->description()" :icon="$type->icon()" />
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('serviceTypes')" />

            <div class="tp-divider !mx-0 !my-2"></div>

            <x-toggle wire:model.live="tablesEnabled" label="Gunakan manajemen meja" icon="fa-chair"
                      description="Opsional. Kasir memilih nomor meja untuk pesanan dine-in."
                      :disabled="! in_array('dine_in', $serviceTypes, true)" />
            @if (! in_array('dine_in', $serviceTypes, true))
                <p class="f-hint !mt-0"><i class="fa-solid fa-circle-info mr-1"></i>Manajemen meja hanya berlaku bila layanan Dine-in aktif.</p>
            @endif
        </div>
    </div>

    {{-- ── Dapur ── --}}
    <div class="card animate-fadeUp d2">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-fire-burner"></i>Dapur</div>
                <div class="section-desc">Pilih cara pesanan diteruskan ke dapur.</div>
            </div>
        </div>
        <div class="card-body grid gap-3 sm:grid-cols-2">
            @foreach (KitchenMode::cases() as $mode)
                <label class="choice-card">
                    <input type="radio" wire:model="kitchenMode" value="{{ $mode->value }}" name="kitchenMode">
                    <span class="toggle-icon"><i class="fa-solid {{ $mode->icon() }}"></i></span>
                    <span>
                        <span class="block text-[13px] font-semibold text-pb-text">{{ $mode->label() }}</span>
                        <span class="block text-[11.5px] text-pb-muted mt-0.5">{{ $mode->description() }}</span>
                    </span>
                </label>
            @endforeach
            <x-input-error :messages="$errors->get('kitchenMode')" class="sm:col-span-2" />
        </div>
    </div>

    {{-- ── Pajak & service charge ── --}}
    <div class="card animate-fadeUp d3">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-percent"></i>Pajak &amp; Service Charge</div>
                <div class="section-desc">Diatur khusus untuk outlet ini.</div>
            </div>
        </div>
        <div class="card-body grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-3">
                <x-toggle wire:model.live="taxEnabled" label="Kenakan pajak (PB1)" icon="fa-receipt" />
                @if ($taxEnabled)
                    <div>
                        <x-input-label for="taxRate" value="Tarif pajak (%)" />
                        <x-text-input wire:model="taxRate" id="taxRate" type="number" step="0.01" min="0" max="100" />
                        <x-input-error :messages="$errors->get('taxRate')" class="mt-1.5" />
                    </div>
                    <x-toggle wire:model="pricesIncludeTax" label="Harga menu sudah termasuk pajak"
                              description="Bila aktif, pajak tidak ditambahkan lagi di struk" />
                @endif
            </div>
            <div class="flex flex-col gap-3">
                <x-toggle wire:model.live="serviceChargeEnabled" label="Kenakan service charge" icon="fa-hand-holding-dollar" />
                @if ($serviceChargeEnabled)
                    <div>
                        <x-input-label for="serviceChargeRate" value="Tarif service charge (%)" />
                        <x-text-input wire:model="serviceChargeRate" id="serviceChargeRate" type="number" step="0.01" min="0" max="100" />
                        <x-input-error :messages="$errors->get('serviceChargeRate')" class="mt-1.5" />
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Pembayaran ── --}}
    <div class="card animate-fadeUp d4">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-wallet"></i>Metode Pembayaran</div>
                <div class="section-desc">Metode yang bisa dipilih kasir di outlet ini.</div>
            </div>
            @if ($gatewayActive)
                <span class="badge badge-green"><i class="fa-solid fa-bolt"></i>Gateway {{ $gatewayLabel }} aktif</span>
            @else
                <span class="badge badge-gray">Tanpa gateway · dicatat manual</span>
            @endif
        </div>
        <div class="card-body flex flex-col gap-3">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (PaymentMethod::cases() as $method)
                    <x-toggle wire:model="paymentMethods" value="{{ $method->value }}"
                              :label="$method->label()" :icon="$method->icon()"
                              :description="$method->supportsGateway() ? ($gatewayActive ? 'Diverifikasi gateway' : 'Konfirmasi manual kasir') : 'Dihitung saat tutup shift'" />
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('paymentMethods')" />
            @can('payment-gateway.manage')
                <p class="f-hint !mt-0">
                    <i class="fa-solid fa-circle-info mr-1"></i>Payment gateway bersifat opsional dan diatur di
                    <a href="{{ route('settings.business') }}" wire:navigate class="link">pengaturan bisnis</a>.
                </p>
            @endcan
        </div>
    </div>

    <div class="flex justify-end gap-2.5 pb-4">
        <a href="{{ route('settings.business') }}" wire:navigate class="btn btn-secondary">Batal</a>
        <x-primary-button wire:loading.attr="disabled" wire:target="save">
            <i class="fa-solid fa-check"></i>{{ $outlet ? 'Simpan Pengaturan' : 'Buat Outlet' }}
        </x-primary-button>
    </div>
</form>
