<?php

use App\Livewire\Actions\Logout;
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
     * @return array<string, list<array{label: string, icon: string, route: string, active?: string}>>
     */
    public function menu(): array
    {
        return [
            'Platform' => [
                ['label' => 'Ringkasan', 'icon' => 'fa-gauge-high', 'route' => 'admin.dashboard'],
                ['label' => 'Bisnis', 'icon' => 'fa-store', 'route' => 'admin.businesses.index', 'active' => 'admin.businesses.*'],
                ['label' => 'Pengguna', 'icon' => 'fa-users', 'route' => 'admin.users.index'],
            ],
            'Kontrol' => [
                ['label' => 'Hak Akses Role', 'icon' => 'fa-user-shield', 'route' => 'admin.roles'],
                ['label' => 'Kategori Pengeluaran', 'icon' => 'fa-tags', 'route' => 'admin.expense-categories'],
                ['label' => 'Riwayat Tindakan', 'icon' => 'fa-clock-rotate-left', 'route' => 'admin.activity'],
            ],
            'Developer' => [
                ['label' => 'Dokumentasi API', 'icon' => 'fa-code', 'route' => 'admin.api-docs'],
            ],
        ];
    }

    public function isActive(array $item): bool
    {
        return Str::is($item['active'] ?? $item['route'], $this->currentRoute);
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<aside id="sb" x-data :class="{ open: $store.ui.sidebarOpen }">
    <a href="{{ route('admin.dashboard') }}" wire:navigate
       class="flex items-center gap-3 px-5 py-[18px] border-b border-pb-border shrink-0">
        <x-brand-mark />
        <div>
            <div class="font-display text-[17px] font-bold text-pb-text leading-tight">
                Pa<span class="a-text">balu</span> <span class="text-pb-muted font-medium text-sm">Admin</span>
            </div>
            <div class="text-[10px] text-pb-muted tracking-[.3px] mt-px">Pengelola aplikasi</div>
        </div>
    </a>

    <nav id="sb-nav" class="p-2.5">
        @foreach ($this->menu() as $group => $items)
            <p class="nav-group">{{ $group }}</p>

            @foreach ($items as $item)
                <a href="{{ route($item['route']) }}" wire:navigate @click="$store.ui.closeSidebar()"
                   @class(['nav-item', 'nav-active' => $this->isActive($item), 'nav-inactive' => ! $this->isActive($item)])>
                    <i class="fa-solid {{ $item['icon'] }} nav-icon"></i>{{ $item['label'] }}
                </a>
            @endforeach
        @endforeach
    </nav>

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
                <div class="a-grad w-[34px] h-[34px] rounded-[9px] grid place-items-center shrink-0 font-bold text-white text-[13px]">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text truncate">{{ auth()->user()->name }}</div>
                    <div class="text-[11px] text-pb-muted">Super Admin</div>
                </div>
                <i class="fa-solid fa-ellipsis-vertical text-pb-muted text-xs"></i>
            </button>
        </div>
    </div>
</aside>
