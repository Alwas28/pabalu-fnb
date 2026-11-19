<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => $title])
    </head>
    <body class="antialiased">
        <div id="toast-container"></div>
        <x-google-connected-popup />

        <!-- Overlay sidebar (mobile) -->
        <div id="ov" x-data x-cloak x-show="$store.ui.sidebarOpen" x-transition.opacity
             @click="$store.ui.closeSidebar()"></div>

        <!-- Sidebar -->
        <livewire:layout.navigation />

        <div id="main">
            <!-- Header -->
            <header id="header">
                <div class="flex items-center gap-3.5 min-w-0">
                    <button type="button" x-data @click="$store.ui.toggleSidebar()"
                            class="lg:hidden text-pb-sub text-lg" aria-label="Buka menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="min-w-0">
                        <div class="text-[11px] text-pb-muted mb-px truncate">
                            {{ $subtitle ?? 'Selamat datang kembali, '.Str::of(auth()->user()->name)->before(' ').' 👋' }}
                        </div>
                        <h1 class="font-display text-lg font-bold text-pb-text truncate">{{ $title ?? 'Dashboard' }}</h1>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    @if (auth()->user()->hasBusiness())
                        <div class="hidden sm:block">
                            <livewire:layout.outlet-switcher />
                        </div>
                    @endif

                    {{ $actions ?? '' }}

                    <button type="button" class="icon-btn" aria-label="Notifikasi"
                            x-data @click="Pabalu.toast('info', 'Belum ada notifikasi baru')">
                        <i class="fa-solid fa-bell"></i>
                    </button>

                    @include('layouts.partials.theme-picker')
                </div>
            </header>

            <!-- Content -->
            <main id="content">
                {{ $slot }}
            </main>

            @if (session('toast'))
                <div x-data x-init="Pabalu.toast('success', @js(session('toast')))"></div>
            @endif
        </div>
    </body>
</html>
