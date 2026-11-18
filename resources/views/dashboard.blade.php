@php
    $user = auth()->user();
    $role = $user->currentRole();
    $outlet = $user->currentOutlet();
    $dash = \App\Support\RoleDashboard::for($role, $outlet?->name ?? 'outlet');
    $shortcuts = \App\Support\Navigation::shortcuts($user);
    $shortcutColors = ['#818cf8', '#34d399', '#f87171', null];
@endphp

<x-app-layout title="Dashboard">
    <x-slot name="actions">
        <span class="chip hidden md:inline-flex items-center gap-1.5" title="Angka di halaman ini masih data contoh">
            <i class="fa-solid fa-flask"></i>Data contoh
        </span>
        @if ($dash['action'])
            <button type="button" class="btn btn-primary !px-3 sm:!px-[18px]" aria-label="{{ $dash['action']['label'] }}"
                    x-data @click="Pabalu.toast('info', @js($dash['action']['label'].' segera hadir'))">
                <i class="fa-solid {{ $dash['action']['icon'] }}"></i><span class="hidden sm:inline">{{ $dash['action']['label'] }}</span>
            </button>
        @endif
    </x-slot>

    <!-- Konteks role -->
    <div class="flex items-center gap-2 -mb-1 animate-fadeUp">
        <span class="badge a-bg-lt a-text"><i class="fa-solid fa-id-badge"></i>{{ $role->label() }}</span>
        <span class="text-[12.5px] text-pb-muted">{{ $dash['subtitle'] }}</span>
    </div>

    <!-- ── Stat Cards ── -->
    <div class="stat-grid animate-fadeUp">
        @foreach ($dash['stats'] as $s)
            <div class="stat-card">
                <div class="stat-icon"
                     style="{{ $s['color'] ? "background:{$s['color']}24;color:{$s['color']}" : 'background:var(--ac-lt);color:var(--ac)' }}">
                    <i class="fa-solid {{ $s['icon'] }}"></i>
                </div>
                <div class="min-w-0">
                    <div class="stat-num">{{ $s['value'] }}</div>
                    <div class="stat-label">{{ $s['label'] }}</div>
                    @if ($s['up'] === null)
                        <div class="stat-trend text-pb-sub !font-medium">{{ $s['note'] }}</div>
                    @else
                        <div class="stat-trend {{ $s['up'] ? 'trend-up' : 'trend-down' }}">
                            <i class="fa-solid {{ $s['up'] ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' }}"></i>{{ $s['note'] }}
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <!-- ── Pintasan (sesuai hak akses) ── -->
    @if (count($shortcuts) > 1)
        <div class="qnav-grid animate-fadeUp d1" x-data>
            @foreach ($shortcuts as $i => $q)
                @php $color = $shortcutColors[$i % count($shortcutColors)]; @endphp
                <button type="button" class="qnav-item" style="--qc: {{ $color ?? 'var(--ac)' }}"
                        @click="Pabalu.toast('info', @js($q['label'].' segera hadir'))">
                    <div class="qnav-icon"
                         style="{{ $color ? "background:{$color}24;color:{$color}" : 'background:var(--ac-lt);color:var(--ac)' }}">
                        <i class="fa-solid {{ $q['icon'] }}"></i>
                    </div>
                    <span class="text-[13px] font-semibold text-pb-text">{{ $q['label'] }}</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-[10px] text-pb-muted"></i>
                </button>
            @endforeach
        </div>
    @endif

    <!-- ── Widget sesuai role ── -->
    @foreach ($dash['rows'] as $i => $row)
        <div class="{{ count($row) > 1 ? 'two-col' : '' }} animate-fadeUp d{{ min($i + 2, 5) }}">
            @foreach ($row as $widget)
                @include('dashboard.widgets.'.$widget, ['role' => $role])
            @endforeach
        </div>
    @endforeach
</x-app-layout>
