<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Daftar')] class extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Daftar dengan email & password (alternatif dari daftar dengan Google).
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ], [], ['name' => 'nama', 'email' => 'email', 'password' => 'password']);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-pb-text">Daftar sebagai pemilik usaha</h2>
        <p class="text-[13px] text-pb-muted mt-1">Cukup pakai akun Google, lalu buat password dan siapkan usahamu.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (filled(config('services.google.client_id')))
        <x-google-button label="Daftar dengan Google" />

        <div class="flex items-center gap-3 my-5 text-[12px] text-pb-muted">
            <span class="h-px flex-1 bg-pb-border"></span>atau daftar dengan email<span class="h-px flex-1 bg-pb-border"></span>
        </div>
    @endif

    <form wire:submit="register" class="flex flex-col gap-4">
        <div>
            <x-input-label for="name" value="Nama pemilik" />
            <x-text-input wire:model="name" id="name" type="text" name="name" required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="email" value="Email" />
            <x-text-input wire:model="email" id="email" type="email" name="email" placeholder="nama@usaha.com" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input wire:model="password" id="password" type="password" name="password" placeholder="Minimal 8 karakter" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Ulangi password" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
        </div>

        <x-primary-button class="w-full mt-1 py-2.5" wire:loading.attr="disabled" wire:target="register">
            Daftar
        </x-primary-button>

        <p class="text-center text-[13px] text-pb-muted">
            Sudah punya akun? <a href="{{ route('login') }}" wire:navigate class="link">Masuk</a>
        </p>
    </form>
</div>
