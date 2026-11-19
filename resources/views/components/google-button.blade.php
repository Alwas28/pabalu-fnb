@props(['label' => 'Lanjutkan dengan Google'])

{{-- Tampil hanya bila GOOGLE_CLIENT_ID diatur. Link biasa (bukan wire:navigate) karena menuju halaman Google. --}}
@if (filled(config('services.google.client_id')))
    <a href="{{ route('auth.google.redirect') }}"
       {{ $attributes->merge(['class' => 'btn btn-secondary w-full justify-center py-2.5 !font-semibold']) }}>
        <x-google-icon class="w-[18px] h-[18px]" />
        {{ $label }}
    </a>
@endif
