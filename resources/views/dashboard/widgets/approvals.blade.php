@php
    use App\Enums\RefundType;
    use App\Support\Money;

    $requests = $data->pendingApprovals();
@endphp

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-user-check"></i>Menunggu Persetujuan</div>
        @can('refunds.approve')
            <a href="{{ route('approvals.index') }}" wire:navigate class="btn-ghost font-semibold">Putuskan <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
        @else
            <span class="chip">Diputuskan owner</span>
        @endcan
    </div>
    <div class="py-2">
        @forelse ($requests as $request)
            <div class="list-row">
                <span class="badge {{ $request->type === RefundType::Void ? 'badge-red' : 'badge-amber' }} shrink-0">{{ $request->type->label() }}</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-semibold text-pb-text">
                        @can('view', $request->order)
                            <a href="{{ route('orders.show', $request->order) }}" wire:navigate class="link">{{ $request->order->number }}</a>
                        @else
                            {{ $request->order->number }}
                        @endcan
                        · {{ Money::format($request->amount) }}
                    </div>
                    <div class="text-[11.5px] text-pb-muted truncate">“{{ $request->reason }}” — {{ $request->requester?->name }}</div>
                </div>
                <span class="text-[11px] text-pb-muted whitespace-nowrap">{{ $request->created_at->locale('id')->diffForHumans() }}</span>
            </div>
        @empty
            <div class="px-5 py-6 text-center text-[13px] text-pb-muted">Tidak ada void / refund yang menunggu.</div>
        @endforelse
    </div>
</div>
