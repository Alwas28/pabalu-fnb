<?php

use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessUser;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Mulai')] class extends Component
{
    #[Validate('required|string|max:120')]
    public string $businessName = '';

    #[Validate('nullable|string|max:32')]
    public string $phone = '';

    #[Validate('required|string|max:120')]
    public string $outletName = '';

    #[Validate('nullable|string|max:500')]
    public string $outletAddress = '';

    public function mount(): void
    {
        // Sudah punya bisnis → tidak perlu onboarding.
        if (auth()->user()->hasBusiness()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function save(): void
    {
        $this->validate();

        $user = auth()->user();

        DB::transaction(function () use ($user) {
            $business = new Business([
                'name' => $this->businessName,
                'phone' => $this->phone ?: null,
            ]);
            $business->owner()->associate($user)->save();

            $outlet = $business->outlets()->create([
                'code' => 'OUT01',
                'name' => $this->outletName,
                'address' => $this->outletAddress ?: null,
            ]);

            BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => UserRole::Owner,
            ]);

            $user->forceFill([
                'current_business_id' => $business->id,
                'current_outlet_id' => $outlet->id,
            ])->save();
        });

        $user->forgetMembership();

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <span class="badge a-bg-lt a-text mb-3"><i class="fa-solid fa-store"></i>Langkah pertama</span>
        <h2 class="font-display text-2xl font-bold text-pb-text">Buat bisnis F&amp;B kamu</h2>
        <p class="text-[13px] text-pb-muted mt-1">
            Kamu akan menjadi <span class="text-pb-text font-semibold">Owner</span>. Pengaturan layanan, meja, dapur, pajak &amp; pembayaran bisa diubah nanti di menu Bisnis &amp; Outlet.
        </p>
    </div>

    <form wire:submit="save" class="flex flex-col gap-4">
        <div>
            <x-input-label for="businessName" value="Nama bisnis" />
            <x-text-input wire:model="businessName" id="businessName" placeholder="mis. Kopi Senja" required autofocus />
            <x-input-error :messages="$errors->get('businessName')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="phone" value="No. telepon (opsional)" />
            <x-text-input wire:model="phone" id="phone" placeholder="08xx-xxxx-xxxx" />
            <x-input-error :messages="$errors->get('phone')" class="mt-1.5" />
        </div>

        <div class="tp-divider !mx-0"></div>

        <div>
            <x-input-label for="outletName" value="Nama outlet pertama" />
            <x-text-input wire:model="outletName" id="outletName" placeholder="mis. Outlet Kendari" required />
            <x-input-error :messages="$errors->get('outletName')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="outletAddress" value="Alamat outlet (opsional)" />
            <textarea wire:model="outletAddress" id="outletAddress" rows="2" class="f-input resize-none" placeholder="Jalan, kota"></textarea>
            <x-input-error :messages="$errors->get('outletAddress')" class="mt-1.5" />
        </div>

        <x-primary-button class="w-full mt-1 py-2.5" wire:loading.attr="disabled">
            <i class="fa-solid fa-arrow-right"></i>Mulai Gunakan Pabalu
        </x-primary-button>
    </form>
</div>
