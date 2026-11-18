<?php

use App\Livewire\Forms\LoginForm;
use Database\Seeders\UserSeeder;
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

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Akun demo dari UserSeeder — hanya ditampilkan di environment local.
     *
     * @return array<int, array{name: string, email: string, role: string}>
     */
    public function demoAccounts(): array
    {
        if (! app()->isLocal() || ! class_exists(UserSeeder::class)) {
            return [];
        }

        return array_map(fn (array $a) => [
            'name' => $a['name'],
            'email' => $a['email'],
            'role' => $a['role']->label(),
        ], UserSeeder::accounts());
    }
}; ?>

<div>
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-pb-text">Masuk ke akunmu</h2>
        <p class="text-[13px] text-pb-muted mt-1">Gunakan email dan password yang terdaftar.</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

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

    @if ($accounts = $this->demoAccounts())
        <div class="mt-6 pt-5 border-t border-pb-border">
            <div class="flex items-center justify-between mb-2.5">
                <span class="tp-section-label !p-0">Akun demo (local)</span>
                <span class="text-[11px] text-pb-muted">password: <code class="a-text font-semibold">{{ UserSeeder::DEFAULT_PASSWORD }}</code></span>
            </div>
            <div class="grid grid-cols-2 gap-1.5">
                @foreach ($accounts as $acc)
                    <button type="button"
                            x-on:click="$wire.form.email = {{ Js::from($acc['email']) }}; $wire.form.password = {{ Js::from(UserSeeder::DEFAULT_PASSWORD) }}"
                            class="text-left px-3 py-2 rounded-[10px] bg-pb-surface2 border border-pb-border hover:border-pb-ac transition-colors">
                        <div class="text-[12px] font-semibold text-pb-text truncate">{{ $acc['role'] }}</div>
                        <div class="text-[10.5px] text-pb-muted truncate">{{ $acc['email'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
