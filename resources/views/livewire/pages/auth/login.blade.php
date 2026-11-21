<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Masuk')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $home = auth()->user()->isSuperAdmin() ? route('admin.dashboard', absolute: false) : route('dashboard', absolute: false);

        $this->redirectIntended(default: $home, navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-pb-text">Masuk ke akunmu</h2>
        <p class="text-[13px] text-pb-muted mt-1">Gunakan email dan password yang terdaftar.</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <x-input-error :messages="$errors->get('google')" class="mb-4" />

    @if (filled(config('services.google.client_id')))
        <x-google-button label="Masuk dengan Google" />

        <div class="flex items-center gap-3 my-5 text-[12px] text-pb-muted">
            <span class="h-px flex-1 bg-pb-border"></span>atau dengan password<span class="h-px flex-1 bg-pb-border"></span>
        </div>
    @endif

    <form wire:submit="login" class="flex flex-col gap-4">
        <!-- Email -->
        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input wire:model="form.email" id="email" type="email" name="email"
                          placeholder="nama@usaha.com" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-1.5" />
        </div>

        <!-- Password -->
        <div x-data="{ show: false }">
            <div class="flex items-center justify-between">
                <x-input-label for="password" value="Password" />
                @if (Route::has('password.request'))
                    <a class="link text-xs mb-[5px]" href="{{ route('password.request') }}" wire:navigate>Lupa password?</a>
                @endif
            </div>
            <div class="relative">
                <x-text-input wire:model="form.password" id="password" name="password" class="pr-10"
                              x-bind:type="show ? 'text' : 'password'" type="password"
                              placeholder="••••••••" required autocomplete="current-password" />
                <button type="button" @click="show = ! show" tabindex="-1"
                        class="absolute inset-y-0 right-0 px-3 text-pb-muted hover:text-pb-text"
                        :aria-label="show ? 'Sembunyikan password' : 'Tampilkan password'">
                    <i class="fa-solid" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.password')" class="mt-1.5" />
        </div>

        <!-- Remember Me -->
        <label for="remember" class="inline-flex items-center gap-2 cursor-pointer w-fit">
            <input wire:model="form.remember" id="remember" type="checkbox" class="f-check" name="remember">
            <span class="text-[13px] text-pb-sub">Ingat saya</span>
        </label>

        <x-primary-button class="w-full mt-1 py-2.5" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login"><i class="fa-solid fa-right-to-bracket"></i></span>
            <span wire:loading wire:target="login"><i class="fa-solid fa-spinner fa-spin"></i></span>
            Masuk
        </x-primary-button>

        @if (Route::has('register'))
            <p class="text-center text-[13px] text-pb-muted">
                Belum punya akun? <a href="{{ route('register') }}" wire:navigate class="link">Daftar sebagai pemilik</a>
            </p>
        @endif
    </form>
</div>
