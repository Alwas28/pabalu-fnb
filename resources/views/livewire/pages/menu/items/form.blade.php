<?php

use App\Models\MenuItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public ?MenuItem $menuItem = null;

    public string $name = '';
    public string $categoryId = '';
    public string $sku = '';
    public string $description = '';
    public string $price = '';
    public string $sortOrder = '0';
    public bool $isActive = true;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $photo = null;
    public bool $removePhoto = false;

    /** @var list<array{id: ?int, name: string, price_adjustment: string}> */
    public array $variants = [];

    /** @var list<string> id grup modifier yang dipasang */
    public array $modifierGroupIds = [];

    /**
     * Pengaturan per outlet, dikunci dengan id outlet.
     *
     * @var array<int|string, array{available: bool, price: string}>
     */
    public array $outletSettings = [];

    public function mount(?MenuItem $menuItem = null): void
    {
        foreach ($this->outlets as $outlet) {
            $this->outletSettings[$outlet->id] = ['available' => true, 'price' => ''];
        }

        if (! $menuItem?->exists) {
            return;
        }

        abort_unless($menuItem->business_id === $this->business()->id, 404);
        $menuItem->load(['variants', 'modifierGroups', 'outlets']);
        $this->menuItem = $menuItem;

        $this->fill([
            'name' => $menuItem->name,
            'categoryId' => (string) $menuItem->menu_category_id,
            'sku' => (string) $menuItem->sku,
            'description' => (string) $menuItem->description,
            'price' => (string) $menuItem->price,
            'sortOrder' => (string) $menuItem->sort_order,
            'isActive' => $menuItem->is_active,
            'variants' => $menuItem->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price_adjustment' => (string) $v->price_adjustment,
            ])->all(),
            'modifierGroupIds' => $menuItem->modifierGroups->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ]);

        foreach ($menuItem->outlets as $outlet) {
            if (isset($this->outletSettings[$outlet->id])) {
                $this->outletSettings[$outlet->id] = [
                    'available' => (bool) $outlet->pivot->is_available,
                    'price' => $outlet->pivot->price === null ? '' : (string) $outlet->pivot->price,
                ];
            }
        }
    }

    public function rendering($view): void
    {
        $view->title($this->menuItem ? 'Ubah '.$this->menuItem->name : 'Tambah Menu');
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function categories()
    {
        return $this->business()->menuCategories()->get();
    }

    #[Computed]
    public function modifierGroups()
    {
        return $this->business()->modifierGroups()->with('modifiers')->get();
    }

    /** Hanya outlet yang boleh diakses pengguna (Manager Outlet: outlet tugasnya). */
    #[Computed]
    public function outlets()
    {
        return auth()->user()->accessibleOutlets();
    }

    public function clearPhoto(): void
    {
        $this->photo = null;
        $this->removePhoto = true;
    }

    public function updatedPhoto(): void
    {
        $this->removePhoto = false;
    }

    public function addVariant(): void
    {
        $this->variants[] = ['id' => null, 'name' => '', 'price_adjustment' => '0'];
    }

    public function removeVariant(int $index): void
    {
        unset($this->variants[$index]);
        $this->variants = array_values($this->variants);
    }

    public function save(): void
    {
        $businessId = $this->business()->id;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'categoryId' => ['nullable', Rule::exists('menu_categories', 'id')->where('business_id', $businessId)],
            'sku' => ['nullable', 'string', 'max:40', 'alpha_dash',
                Rule::unique('menu_items', 'sku')->where('business_id', $businessId)->ignore($this->menuItem?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['boolean'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'variants' => ['array', 'max:20'],
            'variants.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'variants.*.price_adjustment' => ['required', 'integer', 'min:-1000000000', 'max:1000000000'],
            'modifierGroupIds' => ['array'],
            'modifierGroupIds.*' => [Rule::exists('modifier_groups', 'id')->where('business_id', $businessId)],
            'outletSettings' => ['array'],
            'outletSettings.*.available' => ['boolean'],
            'outletSettings.*.price' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], [
            'sku.unique' => 'SKU sudah dipakai menu lain.',
            'variants.*.name.required' => 'Nama varian wajib diisi.',
            'variants.*.name.distinct' => 'Nama varian tidak boleh sama.',
            'photo.max' => 'Ukuran foto maksimal 2 MB.',
        ], [
            'outletSettings.*.price' => 'harga outlet',
            'variants.*.price_adjustment' => 'selisih harga',
        ]);

        $menuItem = DB::transaction(function () {
            $data = [
                'menu_category_id' => $this->categoryId ?: null,
                'name' => $this->name,
                'sku' => $this->sku ?: null,
                'description' => $this->description ?: null,
                'price' => (int) $this->price,
                'sort_order' => (int) $this->sortOrder,
                'is_active' => $this->isActive,
            ];

            $menuItem = $this->menuItem
                ? tap($this->menuItem)->update($data)
                : $this->business()->menuItems()->create($data);

            $this->syncPhoto($menuItem);
            $this->syncVariants($menuItem);

            $menuItem->modifierGroups()->sync(collect($this->modifierGroupIds)
                ->values()
                ->mapWithKeys(fn ($id, $index) => [(int) $id => ['sort_order' => $index]])
                ->all());

            $this->syncOutlets($menuItem);

            return $menuItem;
        });

        session()->flash('toast', 'Menu '.$menuItem->name.' disimpan');
        $this->redirectRoute('menu.items.index', navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->menuItem, 404);

        $name = $this->menuItem->name;
        $path = $this->menuItem->image_path;

        // Order lama tetap utuh: item order menyimpan salinan nama & harga.
        $this->menuItem->delete();

        if ($path) {
            Storage::disk('public')->delete($path);
        }

        session()->flash('toast', 'Menu '.$name.' dihapus');
        $this->redirectRoute('menu.items.index', navigate: true);
    }

    private function syncPhoto(MenuItem $menuItem): void
    {
        $old = $menuItem->image_path;

        if ($this->photo) {
            $menuItem->update(['image_path' => $this->photo->store('menu/'.$menuItem->business_id, 'public')]);
        } elseif ($this->removePhoto) {
            $menuItem->update(['image_path' => null]);
        }

        if ($old && $old !== $menuItem->image_path) {
            Storage::disk('public')->delete($old);
        }
    }

    private function syncVariants(MenuItem $menuItem): void
    {
        $keep = [];

        foreach (array_values($this->variants) as $index => $variant) {
            $attributes = [
                'name' => $variant['name'],
                'price_adjustment' => (int) $variant['price_adjustment'],
                'sort_order' => $index,
            ];

            $model = $variant['id'] ? $menuItem->variants()->find($variant['id']) : null;
            $model = $model ? tap($model)->update($attributes) : $menuItem->variants()->create($attributes);
            $keep[] = $model->id;
        }

        $menuItem->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * Simpan pengaturan untuk outlet yang boleh diakses. Outlet tanpa perubahan
     * (tersedia & harga dasar) tidak perlu baris; outlet lain tidak disentuh.
     */
    private function syncOutlets(MenuItem $menuItem): void
    {
        foreach ($this->outlets as $outlet) {
            $setting = $this->outletSettings[$outlet->id] ?? ['available' => true, 'price' => ''];
            $price = $setting['price'] === '' || $setting['price'] === null ? null : (int) $setting['price'];

            if ($setting['available'] && $price === null) {
                $menuItem->outlets()->detach($outlet->id);
            } else {
                $menuItem->outlets()->syncWithoutDetaching([
                    $outlet->id => ['is_available' => (bool) $setting['available'], 'price' => $price],
                ]);
            }
        }
    }
}; ?>

<form wire:submit="save" class="flex flex-col gap-5 max-w-4xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('menu.items.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Daftar Menu</a>
        <span>/</span>
        <span>{{ $menuItem ? $menuItem->name : 'Menu baru' }}</span>
    </div>

    {{-- ── Informasi menu ── --}}
    <div class="card animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-mug-hot"></i>Informasi Menu</div>
        </div>
        <div class="card-body grid gap-5 sm:grid-cols-[150px_1fr]">
            <div class="flex flex-col items-center gap-2">
                @php($preview = $photo ? $photo->temporaryUrl() : (! $removePhoto ? $menuItem?->imageUrl() : null))
                <div class="w-[150px] h-[150px] rounded-2xl overflow-hidden border border-pb-border grid place-items-center bg-pb-surface2">
                    @if ($preview)
                        <img src="{{ $preview }}" alt="Foto menu" class="w-full h-full object-cover">
                    @else
                        <i class="fa-solid fa-image text-3xl text-pb-muted"></i>
                    @endif
                </div>
                <label class="btn-ghost font-semibold cursor-pointer text-[12.5px]">
                    <i class="fa-solid fa-upload mr-1"></i>{{ $preview ? 'Ganti foto' : 'Unggah foto' }}
                    <input type="file" wire:model="photo" accept="image/*" class="hidden">
                </label>
                @if ($preview)
                    <button type="button" wire:click="clearPhoto" class="btn-ghost text-[12px]" style="color:#f87171">Hapus foto</button>
                @endif
                <div wire:loading wire:target="photo" class="text-[11px] text-pb-muted">Mengunggah…</div>
                <x-input-error :messages="$errors->get('photo')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 content-start">
                <div class="sm:col-span-2">
                    <x-input-label for="name" value="Nama menu" />
                    <x-text-input wire:model="name" id="name" placeholder="mis. Kopi Susu Gula Aren" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="categoryId" value="Kategori" />
                    <select wire:model="categoryId" id="categoryId" class="f-input">
                        <option value="">— Tanpa kategori —</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-1.5" />
                    @if ($this->categories->isEmpty())
                        <p class="f-hint"><a href="{{ route('menu.categories') }}" wire:navigate class="link">Buat kategori</a> dulu agar menu mudah dicari di kasir.</p>
                    @endif
                </div>
                <div>
                    <x-input-label for="sku" value="SKU (opsional)" />
                    <x-text-input wire:model="sku" id="sku" placeholder="mis. KSGA-01" />
                    <x-input-error :messages="$errors->get('sku')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="price" value="Harga dasar (Rp)" />
                    <x-text-input wire:model="price" id="price" type="number" min="0" step="500" required />
                    <x-input-error :messages="$errors->get('price')" class="mt-1.5" />
                    <p class="f-hint">Berlaku di semua outlet, kecuali diatur khusus di bawah.</p>
                </div>
                <div>
                    <x-input-label for="sortOrder" value="Urutan tampil" />
                    <x-text-input wire:model="sortOrder" id="sortOrder" type="number" min="0" />
                    <x-input-error :messages="$errors->get('sortOrder')" class="mt-1.5" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="description" value="Deskripsi (opsional)" />
                    <textarea wire:model="description" id="description" rows="2" class="f-input resize-none"></textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
                </div>
                <div class="sm:col-span-2">
                    <x-toggle wire:model="isActive" label="Menu aktif" icon="fa-power-off"
                              description="Nonaktif = diarsipkan dan tidak tampil di kasir mana pun" />
                </div>
            </div>
        </div>
    </div>

    {{-- ── Varian ── --}}
    <div class="card animate-fadeUp d1">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-up-right-and-down-left-from-center"></i>Varian</div>
                <div class="section-desc">Opsional, mis. ukuran Regular / Large. Bila ada varian, kasir wajib memilih salah satu.</div>
            </div>
        </div>
        <div class="card-body flex flex-col gap-2">
            @forelse ($variants as $i => $variant)
                <div class="grid grid-cols-[1fr_170px_auto] gap-2 items-start" wire:key="variant-{{ $i }}-{{ $variant['id'] ?? 'new' }}">
                    <div>
                        <input type="text" wire:model="variants.{{ $i }}.name" class="f-input" placeholder="mis. Large" aria-label="Nama varian">
                        <x-input-error :messages="$errors->get('variants.'.$i.'.name')" class="mt-1" />
                    </div>
                    <div>
                        <input type="number" wire:model="variants.{{ $i }}.price_adjustment" class="f-input" step="500" aria-label="Selisih harga">
                        <x-input-error :messages="$errors->get('variants.'.$i.'.price_adjustment')" class="mt-1" />
                    </div>
                    <button type="button" wire:click="removeVariant({{ $i }})" class="icon-btn !w-9 !h-9" aria-label="Hapus varian"><i class="fa-solid fa-xmark"></i></button>
                </div>
            @empty
                <p class="text-[12.5px] text-pb-muted">Menu ini tidak memiliki varian.</p>
            @endforelse
            <div>
                <button type="button" wire:click="addVariant" class="btn-ghost font-semibold"><i class="fa-solid fa-plus mr-1"></i>Tambah varian</button>
            </div>
            @if ($variants !== [])
                <p class="f-hint !mt-0">Kolom kanan adalah selisih terhadap harga menu: 0 = sama, 5000 = lebih mahal Rp 5.000, -2000 = lebih murah.</p>
            @endif
        </div>
    </div>

    {{-- ── Modifier ── --}}
    <div class="card animate-fadeUp d2">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-layer-group"></i>Modifier &amp; Topping</div>
                <div class="section-desc">Pilihan tambahan yang ditawarkan kasir untuk menu ini.</div>
            </div>
            <a href="{{ route('menu.modifiers') }}" wire:navigate class="btn-ghost font-semibold text-[12.5px]">Kelola grup</a>
        </div>
        <div class="card-body">
            @if ($this->modifierGroups->isEmpty())
                <p class="text-[12.5px] text-pb-muted">Belum ada grup modifier. <a href="{{ route('menu.modifiers') }}" wire:navigate class="link">Buat grup</a> seperti "Level Gula" atau "Topping".</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($this->modifierGroups as $group)
                        <x-toggle wire:model="modifierGroupIds" value="{{ $group->id }}" :label="$group->name"
                                  :description="$group->ruleLabel().' · '.$group->modifiers->pluck('name')->take(4)->join(', ')" />
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ── Per outlet ── --}}
    <div class="card overflow-hidden animate-fadeUp d3">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-shop"></i>Harga &amp; Ketersediaan per Outlet</div>
                <div class="section-desc">Kosongkan harga untuk memakai harga dasar. Matikan untuk menandai habis / tidak dijual di outlet tersebut.</div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Outlet</th><th>Dijual</th><th class="w-56">Harga khusus (Rp)</th></tr></thead>
                <tbody>
                    @foreach ($this->outlets as $outlet)
                        <tr wire:key="outlet-{{ $outlet->id }}">
                            <td class="td-main">{{ $outlet->name }} <span class="text-[11px] text-pb-muted font-normal">{{ $outlet->code }}</span></td>
                            <td><input type="checkbox" wire:model="outletSettings.{{ $outlet->id }}.available" class="switch" aria-label="Dijual di {{ $outlet->name }}"></td>
                            <td>
                                <input type="number" wire:model="outletSettings.{{ $outlet->id }}.price" min="0" step="500" class="f-input !py-1.5"
                                       placeholder="{{ $price !== '' ? Money::plain((int) $price).' (dasar)' : 'Harga dasar' }}" aria-label="Harga di {{ $outlet->name }}">
                                <x-input-error :messages="$errors->get('outletSettings.'.$outlet->id.'.price')" class="mt-1" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex items-center justify-between gap-2.5 pb-4">
        <div>
            @if ($menuItem)
                <button type="button" wire:click="delete" wire:confirm="Hapus menu {{ $menuItem->name }}? Riwayat transaksi tetap tersimpan. Pertimbangkan menonaktifkan (arsip) saja."
                        class="btn btn-secondary" style="color:#f87171"><i class="fa-solid fa-trash"></i>Hapus</button>
            @endif
        </div>
        <div class="flex gap-2.5">
            <a href="{{ route('menu.items.index') }}" wire:navigate class="btn btn-secondary">Batal</a>
            <x-primary-button wire:loading.attr="disabled" wire:target="save, photo">
                <i class="fa-solid fa-check"></i>{{ $menuItem ? 'Simpan Perubahan' : 'Simpan Menu' }}
            </x-primary-button>
        </div>
    </div>
</form>
