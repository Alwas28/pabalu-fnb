{{--
    Popup sekali tampil setelah data akun Google berhasil diambil (lihat GoogleAuthController).
    Disimpan dengan session()->put lalu diambil (pull) di sini agar tetap muncul walau ada redirect berantai,
    mis. dashboard → onboarding.
--}}
@php $connected = session()->pull(\App\Http\Controllers\Auth\GoogleAuthController::CONNECTED_SESSION); @endphp

@if (is_array($connected))
    <x-modal name="google-connected" :show="true" maxWidth="sm">
        <div class="px-6 pt-7 pb-6 text-center">
            <div class="relative mx-auto w-16 h-16 rounded-full bg-pb-surface2 border border-pb-border grid place-items-center">
                <x-google-icon class="w-8 h-8" />
                <span class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full grid place-items-center text-white text-[11px] bg-emerald-500 ring-2 ring-pb-surface">
                    <i class="fa-solid fa-check"></i>
                </span>
            </div>

            <div class="font-display text-lg font-bold text-pb-text mt-4">Berhasil terhubung dengan Gmail</div>
            <div class="text-[13px] font-semibold text-pb-text mt-1 break-all">{{ $connected['email'] }}</div>
            <p class="text-[13px] text-pb-muted mt-2">
                @if (($connected['mode'] ?? '') === 'register')
                    Nama dan email sudah diambil dari akun Google kamu. Lanjutkan dengan membuat password, lalu siapkan usahamu.
                @else
                    Kamu masuk sebagai <b>{{ $connected['name'] ?? $connected['email'] }}</b>.
                @endif
            </p>

            <x-primary-button type="button" class="w-full mt-5 py-2.5" x-on:click="$dispatch('close')">
                {{ ($connected['mode'] ?? '') === 'register' ? 'Lanjut buat password' : 'Lanjut' }}
            </x-primary-button>
        </div>
    </x-modal>
@endif
