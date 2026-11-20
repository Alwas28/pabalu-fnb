<?php

use App\Livewire\Actions\Logout;
use App\Support\Navigation;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public string $currentRoute = '';

    public function mount(): void
    {
        $this->currentRoute = request()->route()?->getName() ?? '';
    }

    /**
     * Menu sidebar sesuai permission role pengguna (lihat App\Support\Navigation).
     */
    public function menu(): array
    {
        return Navigation::for(auth()->user());
    }

    public function isActive(array $item): bool
    {
        return Str::is($item['active'] ?? $item['route'], $this->currentRoute);
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<aside id="sb" x-data :class="{ open: $store.ui.sidebarOpen }">
    <!-- Logo -->
    <a href="{{ route('dashboard') }}" wire:navigate
       class="flex items-center gap-3 px-5 py-[18px] border-b border-pb-border shrink-0">
        <x-brand-mark />
        <div>
            <div class="font-display text-[17px] font-bold text-pb-text leading-tight">
                Pa<span style="color:#E30100">balu</span> <span class="text-pb-muted font-medium text-sm">F&amp;B</span>
            </div>
            <div class="text-[10px] text-pb-muted tracking-[.3px] mt-px truncate max-w-[170px]">
                {{ auth()->user()->activeBusiness()?->name ?? 'Sistem Manajemen UMKM' }}
            </div>
        </div>
    </a>

    @if (auth()->user()->hasBusiness())
        <!-- Pemilih outlet untuk layar kecil (di layar besar ada di header) -->
        <div class="sm:hidden px-3.5 pt-3">
            <livewire:layout.outlet-switcher />
        </div>
    @endif

    <!-- Nav -->
    <nav id="sb-nav" class="p-2.5">
        @foreach ($this->menu() as $group => $items)
            <p class="nav-group">{{ $group }}</p>

            @foreach ($items as $item)
                @if ($item['route'])
                    <a href="{{ route($item['route']) }}" wire:navigate @click="$store.ui.closeSidebar()"
                       @class(['nav-item', 'nav-active' => $this->isActive($item), 'nav-inactive' => ! $this->isActive($item)])>
                        <i class="fa-solid {{ $item['icon'] }} nav-icon"></i>{{ $item['label'] }}
                    </a>
                @else
                    <button type="button" class="nav-item nav-inactive"
                            @click="Pabalu.toast('info', @js($item['label'].' segera hadir'))">
                        <i class="fa-solid {{ $item['icon'] }} nav-icon"></i>{{ $item['label'] }}
                        <span class="nav-soon">Segera</span>
                    </button>
                @endif
            @endforeach
        @endforeach
    </nav>

    <!-- User card -->
    <div class="px-3.5 py-3 border-t border-pb-border shrink-0">
        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
            <div class="pop-menu" x-cloak x-show="open" x-transition.opacity>
                <a href="{{ route('profile') }}" wire:navigate class="tp-btn">
                    <i class="fa-solid fa-user-gear w-3.5 text-center"></i>Profil Akun
                </a>
                <button type="button" wire:click="logout" class="tp-btn" style="color:#f87171">
                    <i class="fa-solid fa-right-from-bracket w-3.5 text-center"></i>Keluar
                </button>
            </div>

            <button type="button" @click="open = ! open"
                    class="w-full flex items-center gap-2.5 p-2.5 rounded-xl bg-pb-surface2 hover:bg-pb-bg transition-colors text-left">
                <div class="a-grad w-[34px] h-[34px] rounded-[9px] grid place-items-center shrink-0 font-bold text-white text-[13px]"
                     x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                     x-text="name.charAt(0).toUpperCase()"
                     x-on:profile-updated.window="name = $event.detail.name">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text truncate"
                         x-data="{{ json_encode(['name' => auth()->user()->name]) }}"
                         x-text="name"
                         x-on:profile-updated.window="name = $event.detail.name">{{ auth()->user()->name }}</div>
                    <div class="text-[11px] text-pb-muted">{{ auth()->user()->currentRole()?->label() ?? 'Belum ada bisnis' }}</div>
                </div>
                <i class="fa-solid fa-ellipsis-vertical text-pb-muted text-xs"></i>
            </button>
        </div>
    </div>
</aside>
