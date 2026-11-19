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
    public bool $dineInPayLater = false;
    public bool $tableStatusEnabled = false;
    public string $kitchenMode = 'ticket';
    public bool $kitchenUsePreparing = true;
    public bool $kitchenUseReady = true;
    public bool $recipeStockDeduction = false;

    // Kasir offline (aplikasi Android)
    public bool $offlineEnabled = false;
    public string $offlineMaxHours = '24';

    // Pajak & service charge
    public bool $taxEnabled = false;
    public string $taxRate = '10';
    public bool $serviceChargeEnabled = false;
    public string $serviceChargeRate = '5';
    public bool $pricesIncludeTax = false;

    // Pembayaran
    /** @var list<string> */
    public array $paymentMethods = ['cash', 'qris'];

    // Struk
    public string $receiptPaperWidth = '58';
    public string $receiptFooter = '';

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
                'dineInPayLater' => $outlet->dine_in_pay_later,
                'tableStatusEnabled' => $outlet->table_status_enabled,
                'kitchenMode' => $outlet->kitchen_mode->value,
                'kitchenUsePreparing' => $outlet->kitchen_use_preparing,
                'kitchenUseReady' => $outlet->kitchen_use_ready,
                'recipeStockDeduction' => $outlet->recipe_stock_deduction,
                'offlineEnabled' => $outlet->offline_enabled,
                'offlineMaxHours' => (string) $outlet->offline_max_hours,
                'taxEnabled' => $outlet->tax_enabled,
                'taxRate' => (string) (float) $outlet->tax_rate,
                'serviceChargeEnabled' => $outlet->service_charge_enabled,
                'serviceChargeRate' => (string) (float) $outlet->service_charge_rate,
                'pricesIncludeTax' => $outlet->prices_include_tax,
                'paymentMethods' => $outlet->payment_methods->map->value->all(),
                'receiptPaperWidth' => (string) $outlet->receipt_paper_width,
                'receiptFooter' => (string) $outlet->receipt_footer,
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
            'dineInPayLater' => ['boolean'],
            'tableStatusEnabled' => ['boolean'],
            'kitchenMode' => ['required', Rule::enum(KitchenMode::class)],
            'kitchenUsePreparing' => ['boolean'],
            'kitchenUseReady' => ['boolean'],
            'recipeStockDeduction' => ['boolean'],
            'offlineEnabled' => ['boolean'],
            'offlineMaxHours' => ['required', 'integer', 'min:1', 'max:168'],
            'taxEnabled' => ['boolean'],
            'taxRate' => [Rule::requiredIf($this->taxEnabled), 'nullable', 'numeric', 'min:0', 'max:100'],
            'serviceChargeEnabled' => ['boolean'],
            'serviceChargeRate' => [Rule::requiredIf($this->serviceChargeEnabled), 'nullable', 'numeric', 'min:0', 'max:100'],
            'pricesIncludeTax' => ['boolean'],
            'paymentMethods' => ['required', 'array', 'min:1'],
            'paymentMethods.*' => [Rule::enum(PaymentMethod::class)],
            'receiptPaperWidth' => ['required', Rule::in(array_map('strval', Outlet::RECEIPT_PAPER_WIDTHS))],
            'receiptFooter' => ['nullable', 'string', 'max:255'],
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
            'dine_in_pay_later' => $this->dineInPayLater,
            'table_status_enabled' => $this->tablesEnabled && $this->tableStatusEnabled,
            'kitchen_mode' => $this->kitchenMode,
            'kitchen_use_preparing' => $this->kitchenUsePreparing,
            'kitchen_use_ready' => $this->kitchenUseReady,
            'recipe_stock_deduction' => $this->recipeStockDeduction,
            'offline_enabled' => $this->offlineEnabled,
            'offline_max_hours' => (int) $this->offlineMaxHours,
            'tax_enabled' => $this->taxEnabled,
            'tax_rate' => $this->taxEnabled ? $this->taxRate : 0,
            'service_charge_enabled' => $this->serviceChargeEnabled,
            'service_charge_rate' => $this->serviceChargeEnabled ? $this->serviceChargeRate : 0,
            'prices_include_tax' => $this->taxEnabled && $this->pricesIncludeTax,
            'payment_methods' => array_values(array_filter(PaymentMethod::cases(), fn ($m) => in_array($m->value, $this->paymentMethods, true))),
            'receipt_paper_width' => (int) $this->receiptPaperWidth,
            'receipt_footer' => $this->receiptFooter ?: null,
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

    /** Cabut perangkat kasir: token offline-nya tidak bisa sinkron atau mengunduh data lagi. */
    public function revokeDevice(int $deviceId): void
    {
        $this->authorize('update', $this->outlet);

        $device = $this->outlet->posDevices()->findOrFail($deviceId);
        $device->forceFill(['revoked_at' => now()])->save();

        $this->dispatch('toast', message: 'Perangkat '.$device->code.' dicabut');
    }

    public function with(): array
    {
        $business = $this->business();

        return [
            'devices' => $this->outlet?->posDevices()->with('registrar')->withCount(['orders as offline_orders_count' => fn ($q) => $q->where('placed_offline', true)])->orderBy('id')->get() ?? collect(),
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
            @if ($tablesEnabled && in_array('dine_in', $serviceTypes, true))
                <div class="sm:pl-6 flex flex-col gap-2">
                    <x-toggle wire:model="tableStatusEnabled" label="Lacak status meja (terisi / kosong)" icon="fa-circle-half-stroke"
                              description="Opsional. Meja otomatis terisi saat ada pesanan dine-in dan kosong saat pesanan bayar nanti dilunasi; bisa juga dikosongkan manual." />
                    @if ($outlet)
                        <a href="{{ route('tables.index') }}" wire:navigate class="link text-[12.5px] self-start"><i class="fa-solid fa-chair mr-1"></i>Kelola daftar meja</a>
                    @endif
                </div>
            @endif
            <x-toggle wire:model="dineInPayLater" label="Dine-in boleh bayar nanti" icon="fa-file-invoice"
                      description="Pesanan dine-in bisa disimpan dulu (open bill), ditambah item, lalu dibayar di akhir."
                      :disabled="! in_array('dine_in', $serviceTypes, true)" />
            @if (! in_array('dine_in', $serviceTypes, true))
                <p class="f-hint !mt-0"><i class="fa-solid fa-circle-info mr-1"></i>Manajemen meja & bayar nanti hanya berlaku bila layanan Dine-in aktif.</p>
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
                    <input type="radio" wire:model.live="kitchenMode" value="{{ $mode->value }}" name="kitchenMode">
                    <span class="toggle-icon"><i class="fa-solid {{ $mode->icon() }}"></i></span>
                    <span>
                        <span class="block text-[13px] font-semibold text-pb-text">{{ $mode->label() }}</span>
                        <span class="block text-[11.5px] text-pb-muted mt-0.5">{{ $mode->description() }}</span>
                    </span>
                </label>
            @endforeach
            <x-input-error :messages="$errors->get('kitchenMode')" class="sm:col-span-2" />
            @if ($kitchenMode === 'display')
                <div class="sm:col-span-2 flex flex-col gap-2">
                    <div class="f-label !mb-0">Tahap antrean dapur</div>
                    <p class="f-hint !mt-0">"Antre" dan "Disajikan" selalu dipakai. Nyalakan tahap tambahan bila dapur ingin melacaknya.</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <x-toggle wire:model="kitchenUsePreparing" label="Dimasak" icon="fa-fire" description="Staf dapur menandai item mulai dimasak" />
                        <x-toggle wire:model="kitchenUseReady" label="Siap" icon="fa-bell" description="Item selesai dimasak, menunggu diantar ke pelanggan" />
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Persediaan ── --}}
    <div class="card animate-fadeUp d2">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-warehouse"></i>Persediaan</div>
                <div class="section-desc">Stok bahan dicatat per outlet.</div>
            </div>
        </div>
        <div class="card-body">
            <x-toggle wire:model="recipeStockDeduction" label="Kurangi stok bahan otomatis dari resep" icon="fa-book-open"
                      description="Setiap penjualan mengurangi bahan sesuai resep menu, varian & modifier. Bila nonaktif, resep tetap dipakai untuk menghitung food cost." />
        </div>
    </div>

    {{-- ── Kasir offline ── --}}
    <div class="card animate-fadeUp d2">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-wifi"></i>Kasir Offline</div>
                <div class="section-desc">Aplikasi kasir Android tetap bisa berjualan saat internet putus, lalu sinkron otomatis.</div>
            </div>
        </div>
        <div class="card-body flex flex-col gap-4">
            <x-toggle wire:model.live="offlineEnabled" label="Izinkan kasir offline" icon="fa-cloud-arrow-up"
                      description="Saat offline hanya pesanan yang langsung dibayar (tunai atau non-tunai dikonfirmasi manual). Bayar nanti, payment gateway & layar dapur butuh internet." />

            @if ($offlineEnabled)
                <div class="max-w-xs">
                    <x-input-label for="offlineMaxHours" value="Batas lama offline (jam)" />
                    <x-text-input wire:model="offlineMaxHours" id="offlineMaxHours" type="number" min="1" max="168" />
                    <p class="f-hint">Setelah batas ini aplikasi meminta tersambung internet dulu. Transaksi yang terlambat sinkron tetap diterima dan ditandai.</p>
                    <x-input-error :messages="$errors->get('offlineMaxHours')" />
                </div>
            @endif

            @if ($outlet)
                <div>
                    <div class="text-[13px] font-semibold mb-2">Perangkat kasir terdaftar</div>
                    @forelse ($devices as $device)
                        <div wire:key="device-{{ $device->id }}" class="flex items-center gap-3 py-2 border-t border-pb-border first:border-t-0">
                            <span class="badge {{ $device->isRevoked() ? 'badge-gray' : 'badge-blue' }}">{{ $device->code }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-[13px] font-medium truncate">{{ $device->name }}</div>
                                <div class="text-[11.5px] text-pb-muted">
                                    {{ $device->offline_orders_count }} transaksi offline ·
                                    {{ $device->last_synced_at ? 'sinkron '.$device->last_synced_at->diffForHumans() : 'belum pernah sinkron' }}
                                    @if ($device->registrar) · didaftarkan {{ $device->registrar->name }} @endif
                                </div>
                            </div>
                            @if ($device->isRevoked())
                                <span class="badge badge-red">Dicabut</span>
                            @else
                                <button type="button" class="btn-ghost" style="color:#f87171" wire:click="revokeDevice({{ $device->id }})"
                                        wire:confirm="Cabut perangkat {{ $device->code }}? Transaksi yang belum sinkron di perangkat ini tidak bisa dikirim lagi.">
                                    Cabut
                                </button>
                            @endif
                        </div>
                    @empty
                        <p class="text-[12.5px] text-pb-muted">Belum ada. Perangkat terdaftar otomatis saat aplikasi Android login di outlet ini.</p>
                    @endforelse
                </div>
            @endif
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

    {{-- ── Struk ── --}}
    <div class="card animate-fadeUp d4">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-print"></i>Struk</div>
                <div class="section-desc">Struk dicetak ke printer thermal (ESC/POS) dan memuat QR code menuju struk digital.</div>
            </div>
        </div>
        <div class="card-body grid gap-4 sm:grid-cols-[260px_1fr]">
            <div>
                <x-input-label value="Lebar kertas" />
                <div class="grid grid-cols-2 gap-2">
                    @foreach (Outlet::RECEIPT_PAPER_WIDTHS as $width)
                        <label class="choice-card !p-3 !items-center">
                            <input type="radio" wire:model="receiptPaperWidth" value="{{ $width }}" name="receiptPaperWidth">
                            <span class="text-[13px] font-semibold text-pb-text">{{ $width }} mm</span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('receiptPaperWidth')" class="mt-1.5" />
            </div>
            <div>
                <x-input-label for="receiptFooter" value="Teks penutup struk" />
                <x-text-input wire:model="receiptFooter" id="receiptFooter" maxlength="255" placeholder="Terima kasih atas kunjungan Anda" />
                <x-input-error :messages="$errors->get('receiptFooter')" class="mt-1.5" />
                <p class="f-hint">mis. info Wi-Fi, akun Instagram, atau kebijakan penukaran.</p>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-2.5 pb-4">
        <a href="{{ route('settings.business') }}" wire:navigate class="btn btn-secondary">Batal</a>
        <x-primary-button wire:loading.attr="disabled" wire:target="save">
            <i class="fa-solid fa-check"></i>{{ $outlet ? 'Simpan Pengaturan' : 'Buat Outlet' }}
        </x-primary-button>
    </div>
</form>
