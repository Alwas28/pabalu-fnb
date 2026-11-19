<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => $title])
    </head>
    <body class="antialiased">
        <div id="toast-container"></div>

        <!-- Overlay sidebar (mobile) -->
        <div id="ov" x-data x-cloak x-show="$store.ui.sidebarOpen" x-transition.opacity
             @click="$store.ui.closeSidebar()"></div>

        <!-- Sidebar -->
        <livewire:layout.admin-navigation />

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
                            {{ $subtitle ?? 'Panel Super Admin · Pengelola aplikasi' }}
                        </div>
                        <h1 class="font-display text-lg font-bold text-pb-text truncate">{{ $title ?? 'Dashboard' }}</h1>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    {{ $actions ?? '' }}

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
