@php
    // Data asli: jumlah anggota bisnis aktif per role.
    $counts = \App\Models\BusinessUser::query()
        ->where('business_id', auth()->user()->activeBusiness()?->id)
        ->where('is_active', true)
        ->selectRaw('role, count(*) as total')
        ->groupBy('role')
        ->pluck('total', 'role');
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-user-group"></i>Tim</div>
        <span class="chip">{{ $counts->sum() }} Pengguna</span>
    </div>
    <div class="py-2">
        @foreach (\App\Enums\UserRole::cases() as $r)
            <div class="list-row">
                <div class="w-8 h-8 rounded-lg grid place-items-center shrink-0 a-bg-lt a-text text-xs">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text">{{ $r->label() }}</div>
                    <div class="text-[11px] text-pb-muted">{{ count($r->permissions()) }} hak akses</div>
                </div>
                <span class="text-[13px] font-bold text-pb-text">{{ $counts[$r->value] ?? 0 }}</span>
            </div>
        @endforeach
    </div>
</div>
