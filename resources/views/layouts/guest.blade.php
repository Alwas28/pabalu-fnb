<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => $title ?? null])
    </head>
    <body class="antialiased">
        <div id="toast-container"></div>
        <x-google-connected-popup />

        <div class="min-h-screen grid lg:grid-cols-[1fr_minmax(0,560px)]">
            <!-- Panel brand -->
            <aside class="hidden lg:flex relative overflow-hidden flex-col justify-between p-12 a-grad">
                <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-white/10"></div>
                <div class="absolute -bottom-32 -left-20 w-96 h-96 rounded-full bg-black/10"></div>

                {{-- Logo lengkap dengan motto (latar putih) ditaruh di kartu putih. --}}
                <div class="relative self-start rounded-2xl bg-white px-5 py-3 shadow-lg shadow-black/10">
                    <img src="{{ asset('img/logo-header.png') }}" alt="Pabalu — Smart Solution For UMKM" class="h-14 w-auto">
                </div>

                <div class="relative text-white max-w-md">
                    <h2 class="font-display text-4xl font-bold leading-tight">Kelola kafe &amp; restoranmu dalam satu tempat.</h2>
                    <p class="mt-4 text-white/85 text-[15px] leading-relaxed">
                        Kasir, menu, stok bahan, shift, sampai laporan laba untuk semua outlet—terhubung ke dashboard pemilik.
                    </p>
                    <div class="mt-8 grid grid-cols-3 gap-3 text-[12px]">
                        @foreach ([['fa-cash-register', 'Kasir & Shift'], ['fa-utensils', 'Menu & Resep'], ['fa-chart-line', 'Laporan Outlet']] as [$icon, $label])
                            <div class="rounded-xl bg-white/15 px-3 py-3">
                                <i class="fa-solid {{ $icon }} text-base"></i>
                                <div class="mt-1.5 font-semibold">{{ $label }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="relative text-[11px] text-white/70">&copy; {{ date('Y') }} Pabalu</div>
            </aside>

            <!-- Form -->
            <main class="flex flex-col justify-center px-4 py-10 sm:px-10">
                <div class="w-full max-w-[420px] mx-auto">
                    <a href="/" wire:navigate class="lg:hidden inline-flex mb-8 rounded-2xl bg-white px-4 py-2.5 shadow-sm">
                        <img src="{{ asset('img/logo-header.png') }}" alt="Pabalu — Smart Solution For UMKM" class="h-11 w-auto">
                    </a>

                    <div class="card p-6 sm:p-8 animate-fadeUp">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
