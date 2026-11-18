@php
    $user = auth()->user();
    $data = new \App\Support\DashboardData($user);
    $role = $data->role;
    $dash = \App\Support\RoleDashboard::for($data);
    $shortcuts = \App\Support\Navigation::shortcuts($user);
    $shortcutColors = ['#818cf8', '#34d399', '#f87171', null];
@endphp

<x-app-layout title="Dashboard">
    <x-slot name="actions">
        @if ($dash['action'] && Route::has($dash['action']['route']))
            <a href="{{ route($dash['action']['route']) }}" wire:navigate class="btn btn-primary !px-3 sm:!px-[18px]" aria-label="{{ $dash['action']['label'] }}">
                <i class="fa-solid {{ $dash['action']['icon'] }}"></i><span class="hidden sm:inline">{{ $dash['action']['label'] }}</span>
            </a>
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
                    <div class="stat-num whitespace-nowrap">{{ $s['value'] }}</div>
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
        <div class="qnav-grid animate-fadeUp d1">
            @foreach ($shortcuts as $i => $q)
                @php $color = $shortcutColors[$i % count($shortcutColors)]; @endphp
                <a href="{{ $q['route'] ? route($q['route']) : '#' }}" wire:navigate class="qnav-item" style="--qc: {{ $color ?? 'var(--ac)' }}">
                    <div class="qnav-icon"
                         style="{{ $color ? "background:{$color}24;color:{$color}" : 'background:var(--ac-lt);color:var(--ac)' }}">
                        <i class="fa-solid {{ $q['icon'] }}"></i>
                    </div>
                    <span class="text-[13px] font-semibold text-pb-text">{{ $q['label'] }}</span>
                    <i class="fa-solid fa-chevron-right ml-auto text-[10px] text-pb-muted"></i>
                </a>
            @endforeach
        </div>
    @endif

    <!-- ── Widget sesuai role ── -->
    @foreach ($dash['rows'] as $i => $row)
        <div class="{{ count($row) > 1 ? 'two-col' : '' }} animate-fadeUp d{{ min($i + 2, 5) }}">
            @foreach ($row as $widget)
                @include('dashboard.widgets.'.$widget, ['role' => $role, 'data' => $data])
            @endforeach
        </div>
    @endforeach
</x-app-layout>
