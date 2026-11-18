<?php

use App\Models\Outlet;
use Livewire\Volt\Component;

new class extends Component
{
    public function switchTo(int $outletId): void
    {
        $user = auth()->user();
        $outlet = Outlet::find($outletId);

        // Validasi server-side: outlet harus milik bisnis aktif & ditugaskan ke pengguna.
        abort_unless($outlet && $user->switchOutlet($outlet), 403);

        $this->redirect(url()->previous() ?: route('dashboard'), navigate: true);
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'outlets' => $user->accessibleOutlets(),
            'current' => $user->currentOutlet(),
        ];
    }
}; ?>

<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    @if ($current)
        <button type="button" @click="open = ! open" @disabled($outlets->count() < 2)
                class="flex items-center gap-2 h-[38px] px-3 rounded-[10px] border border-pb-border bg-pb-surface2 text-[12.5px] font-semibold text-pb-text disabled:cursor-default max-w-[180px] sm:max-w-none"
                title="Outlet aktif">
            <i class="fa-solid fa-location-dot a-text"></i>
            <span class="truncate">{{ $current->name }}</span>
            @if ($outlets->count() > 1)
                <i class="fa-solid fa-chevron-down text-[10px] text-pb-muted"></i>
            @endif
        </button>

        @if ($outlets->count() > 1)
            <div class="tp-panel !min-w-[230px]" x-cloak x-show="open" x-transition.opacity>
                <div class="tp-section-label">Pindah outlet</div>
                @foreach ($outlets as $outlet)
                    <button type="button" wire:click="switchTo({{ $outlet->id }})" @click="open = false"
                            @class(['tp-btn', 'active' => $outlet->is($current)])>
                        <i class="fa-solid {{ $outlet->is($current) ? 'fa-circle-check' : 'fa-store' }} w-3.5 text-center"></i>
                        <span class="flex-1 truncate">{{ $outlet->name }}</span>
                        @unless ($outlet->is_active)
                            <span class="nav-soon !ml-0">Nonaktif</span>
                        @endunless
                    </button>
                @endforeach
            </div>
        @endif
    @endif
</div>
