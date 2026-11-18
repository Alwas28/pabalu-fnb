<?php

use App\Models\AuditLog;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Outlet;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Riwayat Perubahan')] class extends Component
{
    use WithPagination;

    /** '' semua, atau: menu, recipe, ingredient, outlet, access */
    #[Url]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::where('business_id', auth()->user()->activeBusiness()->id)
            ->with('user')
            ->when($this->type === 'menu', fn ($q) => $q->whereIn('auditable_type', [MenuItem::class, \App\Models\MenuVariant::class, \App\Models\Modifier::class])->where('event', '!=', 'recipe'))
            ->when($this->type === 'recipe', fn ($q) => $q->where('event', 'recipe'))
            ->when($this->type === 'ingredient', fn ($q) => $q->where('auditable_type', Ingredient::class))
            ->when($this->type === 'outlet', fn ($q) => $q->where('auditable_type', Outlet::class))
            ->when($this->type === 'access', fn ($q) => $q->whereIn('auditable_type', [\App\Models\BusinessUser::class, \App\Models\Business::class]))
            ->when($this->search !== '', fn ($q) => $q->where('subject', 'like', '%'.$this->search.'%'))
            ->latest('id')
            ->paginate(30);
    }

    public function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => implode(', ', $value),
            is_int($value) || is_float($value) => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ','),
            default => (string) $value,
        };
    }
}; ?>

<div class="flex flex-col gap-5">
    @php $tz = auth()->user()->activeBusiness()->timezone; @endphp
    <div class="flex flex-wrap items-center gap-2">
        @foreach (['' => 'Semua', 'menu' => 'Menu & harga', 'recipe' => 'Resep', 'ingredient' => 'Bahan baku', 'outlet' => 'Pengaturan outlet', 'access' => 'Karyawan & hak akses'] as $key => $label)
            <button type="button" wire:click="$set('type', '{{ $key }}')" @class(['chip cursor-pointer', 'a-bg-lt a-text a-border' => $type === $key])>{{ $label }}</button>
        @endforeach
        <div class="relative ml-auto">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
            <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-52 !text-[12.5px]" placeholder="Cari nama…">
        </div>
    </div>

    <div class="card overflow-hidden animate-fadeUp">
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Waktu</th><th>Oleh</th><th>Objek</th><th>Kejadian</th><th>Perubahan</th></tr></thead>
                <tbody>
                    @forelse ($this->logs as $log)
                        <tr wire:key="log-{{ $log->id }}">
                            <td class="whitespace-nowrap">{{ $log->created_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                            <td>{{ $log->user?->name ?? 'Sistem' }}</td>
                            <td class="td-main !whitespace-normal">{{ $log->subject }}<div class="text-[11px] text-pb-muted font-normal">{{ $log->typeLabel() }}</div></td>
                            <td><span class="badge {{ ['created' => 'badge-green', 'deleted' => 'badge-red'][$log->event] ?? 'badge-blue' }}">{{ $log->eventLabel() }}</span></td>
                            <td class="!whitespace-normal text-[12.5px]">
                                @if ($log->changes)
                                    @foreach ($log->changes as $field => [$old, $new])
                                        <div><span class="text-pb-muted">{{ str_replace('_', ' ', $field) }}:</span>
                                            @if ($log->event !== 'created')<span class="line-through text-pb-muted">{{ $this->show($old) }}</span> → @endif
                                            <span class="text-pb-text font-semibold">{{ $this->show($new) }}</span></div>
                                    @endforeach
                                @else
                                    <span class="text-pb-muted">—</span>
                                @endif
                                @if ($log->note)<div class="text-[11px] text-pb-muted">{{ $log->note }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center !py-8">Belum ada riwayat perubahan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->logs->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->logs->links() }}</div>
        @endif
    </div>
</div>
