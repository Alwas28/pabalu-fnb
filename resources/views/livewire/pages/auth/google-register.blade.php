<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Lengkapi Pendaftaran')] class extends Component
{
    public string $name = '';

    #[Locked]
    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $signup = $this->signup();

        if ($signup === null) {
            $this->redirectRoute('register', navigate: true);

            return;
        }

        $this->name = $signup['name'];
        $this->email = $signup['email'];
    }

    /** @return array{google_id: string, name: string, email: string, expires_at: int}|null */
    private function signup(): ?array
    {
        $signup = session(GoogleAuthController::SIGNUP_SESSION);

        return is_array($signup) && ($signup['expires_at'] ?? 0) >= now()->timestamp ? $signup : null;
    }

    public function register(): void
    {
        $signup = $this->signup();

        if ($signup === null) {
            Session::flash('status', 'Sesi pendaftaran Google berakhir. Silakan daftar ulang.');
            $this->redirectRoute('register', navigate: true);

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ], [], ['name' => 'nama', 'password' => 'password']);

        if (User::where('email', $signup['email'])->orWhere('google_id', $signup['google_id'])->exists()) {
            session()->forget(GoogleAuthController::SIGNUP_SESSION);
            $this->addError('email', 'Email ini sudah terdaftar. Silakan masuk.');

            return;
        }

        $user = new User(['name' => $validated['name'], 'email' => $signup['email'], 'password' => $validated['password']]);
        // Email sudah dibuktikan Google, tidak perlu verifikasi ulang.
        $user->forceFill(['google_id' => $signup['google_id'], 'email_verified_at' => now()])->save();

        event(new Registered($user));
        session()->forget(GoogleAuthController::SIGNUP_SESSION);

        Auth::login($user, remember: true);
        Session::regenerate();

        $this->redirectRoute('onboarding', navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h2 class="font-display text-2xl font-bold text-pb-text">Lengkapi pendaftaran</h2>
        <p class="text-[13px] text-pb-muted mt-1">Data diambil dari akun Google kamu. Buat password, lalu siapkan usahamu.</p>
    </div>

    <form wire:submit="register" class="flex flex-col gap-4">
        <div>
            <x-input-label for="email" value="Email" />
            <div class="f-input flex items-center gap-2 !bg-pb-surface2 text-pb-sub">
                <x-google-icon class="w-4 h-4 shrink-0" />
                <span class="truncate">{{ $email }}</span>
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="name" value="Nama pemilik" />
            <x-text-input wire:model="name" id="name" type="text" required autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password" value="Buat password" />
            <x-text-input wire:model="password" id="password" type="password" required autofocus autocomplete="new-password" placeholder="Minimal 8 karakter" />
            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Ulangi password" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" type="password" required autocomplete="new-password" />
        </div>

        <p class="text-[12px] text-pb-muted -mt-1">Password dipakai untuk masuk dari aplikasi kasir. Di web kamu tetap bisa masuk dengan Google.</p>

        <x-primary-button class="w-full mt-1 py-2.5" wire:loading.attr="disabled" wire:target="register">
            Lanjut siapkan usaha <i class="fa-solid fa-arrow-right"></i>
        </x-primary-button>
    </form>
</div>
