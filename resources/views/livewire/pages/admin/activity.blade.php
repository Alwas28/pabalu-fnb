<?php

use App\Models\AdminActivity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Riwayat Tindakan')] class extends Component
{
    use WithPagination;

    #[Computed]
    public function activities()
    {
        return AdminActivity::with('user')->latest('created_at')->latest('id')->paginate(30);
    }
}; ?>

@php $tz = config('app.admin_timezone'); @endphp

<div class="card overflow-hidden animate-fadeUp">
    <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Tindakan Super Admin</div></div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead><tr><th>Waktu</th><th>Tindakan</th><th>Objek</th><th>Detail</th><th>Oleh</th></tr></thead>
            <tbody>
                @forelse ($this->activities as $activity)
                    <tr wire:key="act-{{ $activity->id }}">
                        <td class="whitespace-nowrap text-[12.5px]">{{ $activity->created_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                        <td><span class="badge {{ $activity->actionBadge() }}">{{ $activity->actionLabel() }}</span></td>
                        <td class="td-main">{{ $activity->label }}</td>
                        <td class="text-[12px]">
                            @foreach ($activity->details ?? [] as $key => $value)
                                <div><span class="text-pb-muted">{{ $key }}:</span>
                                    @if (is_array($value))
                                        {{ collect($value)->map(fn ($v, $k) => $k.' '.$v)->join(' · ') }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="text-[12.5px]">{{ $activity->user?->name ?? 'Sistem' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center !py-8">Belum ada tindakan super admin.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->activities->hasPages())
        <div class="px-5 py-3 border-t border-pb-border">{{ $this->activities->links() }}</div>
    @endif
</div>
