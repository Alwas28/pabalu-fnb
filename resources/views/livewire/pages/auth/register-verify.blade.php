<?php

use App\Support\Auth\PendingRegistration;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Verifikasi Email')] class extends Component
{
    public string $pin = '';

    public string $email = '';

    public function mount(): void
    {
        $pending = PendingRegistration::current();

        if ($pending === null) {
            $this->redirectRoute('register', navigate: true);

            return;
        }

        $this->email = $pending['email'];
    }

    public function verify(): void
    {
        $this->validate(['pin' => ['required', 'digits:6']], ['pin.digits' => 'Kode terdiri dari 6 angka.'], ['pin' => 'kode']);

        $user = PendingRegistration::verify($this->pin);

        event(new Registered($user));
        Auth::login($user, remember: true);
        Session::regenerate();

        $this->redirectRoute('onboarding', navigate: true);
    }

    public function resend(): void
    {
        $this->resetErrorBag();
        PendingRegistration::resend();

        $this->reset('pin');
        $this->dispatch('toast', type: 'success', message: 'Kode baru sudah dikirim ke '.$this->email);
    }

    public function changeEmail(): void
    {
        PendingRegistration::forget();
        $this->redirectRoute('register', navigate: true);
    }

    public function resendWait(): int
    {
        return PendingRegistration::resendWaitSeconds();
    }
}; ?>

<div>
    <div class="mb-6">
        <div class="w-12 h-12 rounded-xl a-bg-lt grid place-items-center mb-4"><i class="fa-solid fa-envelope-open-text a-text text-lg"></i></div>
        <h2 class="font-display text-2xl font-bold text-pb-text">Verifikasi email</h2>
        <p class="text-[13px] text-pb-muted mt-1">
            Kami mengirim kode 6 angka ke <b class="text-pb-text break-all">{{ $email }}</b>. Masukkan kode tersebut untuk menyelesaikan pendaftaran.
        </p>
    </div>

    <form wire:submit="verify" class="flex flex-col gap-4">
        <div>
            <x-input-label for="pin" value="Kode verifikasi" />
            <x-text-input wire:model="pin" id="pin" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus
                          placeholder="••••••" class="text-center !text-2xl !tracking-[0.5em] font-semibold" />
            <x-input-error :messages="$errors->get('pin')" class="mt-1.5" />
            <p class="text-[12px] text-pb-muted mt-1.5">Kode berlaku {{ PendingRegistration::VALID_MINUTES }} menit. Tidak ada di inbox? Periksa folder Spam.</p>
        </div>

        <x-primary-button class="w-full py-2.5" wire:loading.attr="disabled" wire:target="verify">
            Verifikasi &amp; lanjutkan
        </x-primary-button>
    </form>

    <div class="flex items-center justify-between mt-5 text-[13px]"
         x-data="{ wait: {{ $this->resendWait() }} }"
         x-init="setInterval(() => { if (wait > 0) wait-- }, 1000)"
         x-on:toast.window="wait = {{ PendingRegistration::RESEND_COOLDOWN_SECONDS }}">
        <button type="button" wire:click="changeEmail" class="link">Ganti email</button>
        <button type="button" wire:click="resend" class="link disabled:opacity-50 disabled:no-underline" x-bind:disabled="wait > 0" wire:loading.attr="disabled" wire:target="resend">
            <span x-show="wait > 0">Kirim ulang kode (<span x-text="wait"></span> dtk)</span>
            <span x-show="wait <= 0">Kirim ulang kode</span>
        </button>
    </div>
</div>
