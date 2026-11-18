<?php

use App\Enums\ApprovalStatus;
use App\Enums\RefundType;
use App\Models\RefundRequest;
use App\Support\Money;
use App\Support\Pos\RefundService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Persetujuan')] class extends Component
{
    use WithPagination;

    /** @var array<int, string> catatan keputusan per pengajuan */
    public array $notes = [];

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function pending()
    {
        return RefundRequest::where('business_id', $this->business()->id)
            ->where('status', ApprovalStatus::Pending)
            ->with(['order.payments', 'outlet', 'requester'])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function history()
    {
        return RefundRequest::where('business_id', $this->business()->id)
            ->where('status', '!=', ApprovalStatus::Pending)
            ->with(['order', 'outlet', 'requester', 'decider'])
            ->latest('decided_at')
            ->paginate(15);
    }

    public function approve(int $id): void
    {
        $request = $this->find($id);
        app(RefundService::class)->approve(auth()->user(), $request, $this->notes[$id] ?? null);

        unset($this->pending, $this->history);
        $this->dispatch('toast', type: 'success', message: $request->type->label().' '.$request->order->number.' disetujui');
    }

    public function reject(int $id): void
    {
        $request = $this->find($id);

        try {
            app(RefundService::class)->reject(auth()->user(), $request, (string) ($this->notes[$id] ?? ''));
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('notes.'.$id, 'Tulis alasan penolakan.');

            return;
        }

        unset($this->pending, $this->history);
        $this->dispatch('toast', type: 'success', message: 'Pengajuan '.$request->order->number.' ditolak');
    }

    private function find(int $id): RefundRequest
    {
        $request = RefundRequest::where('business_id', $this->business()->id)->with('order')->find($id);
        abort_unless($request, 404);

        return $request;
    }
}; ?>

<div class="flex flex-col gap-5 max-w-5xl">
    <div class="card animate-fadeUp">
        <div class="card-header">
            <div>
                <div class="card-title"><i class="fa-solid fa-stamp"></i>Menunggu Persetujuan</div>
                <div class="section-desc">Void & refund pesanan yang sudah dibayar wajib disetujui owner.</div>
            </div>
            <span class="chip">{{ $this->pending->count() }} pengajuan</span>
        </div>
        <div class="flex flex-col divide-y divide-pb-border">
            @forelse ($this->pending as $request)
                @php $order = $request->order; $tz = auth()->user()->activeBusiness()->timezone; @endphp
                <div class="px-5 py-4 flex flex-col gap-3 md:flex-row md:items-start" wire:key="rq-{{ $request->id }}">
                    <div class="flex-1 min-w-0 flex flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge {{ $request->type === RefundType::Void ? 'badge-red' : 'badge-amber' }}">{{ $request->type->label() }}</span>
                            <a href="{{ route('orders.show', $order) }}" wire:navigate class="link font-semibold">{{ $order->number }}</a>
                            <span class="text-[12px] text-pb-muted">{{ $request->outlet->name }} · total {{ Money::format($order->total) }}</span>
                        </div>
                        <div class="font-display text-lg font-bold text-pb-text">
                            {{ Money::format($request->amount) }}
                            <span class="text-[12px] font-normal text-pb-muted">
                                {{ $request->type === RefundType::Void ? 'dikembalikan via '.$order->payments->map(fn ($p) => $p->method->label())->unique()->join(' + ') : 'via '.$request->method?->label() }}
                            </span>
                        </div>
                        <div class="text-[13px] text-pb-sub">“{{ $request->reason }}”</div>
                        <div class="text-[11.5px] text-pb-muted">Diajukan {{ $request->requester?->name ?? '—' }} · {{ $request->created_at->timezone($tz)->format('d/m/Y H:i') }}</div>
                    </div>
                    <div class="md:w-72 flex flex-col gap-2">
                        <input type="text" wire:model="notes.{{ $request->id }}" class="f-input !py-1.5 !text-[12.5px]" placeholder="Catatan (wajib bila ditolak)" aria-label="Catatan keputusan">
                        <x-input-error :messages="$errors->get('notes.'.$request->id)" />
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" wire:click="reject({{ $request->id }})" wire:loading.attr="disabled" class="btn btn-secondary !py-2" style="color:#f87171">Tolak</button>
                            <button type="button" wire:click="approve({{ $request->id }})" wire:loading.attr="disabled"
                                    wire:confirm="Setujui {{ strtolower($request->type->label()) }} {{ Money::format($request->amount) }} untuk {{ $order->number }}?" class="btn btn-primary !py-2">Setujui</button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-[13px] text-pb-muted"><i class="fa-solid fa-circle-check text-2xl mb-2 block"></i>Tidak ada pengajuan yang menunggu.</div>
            @endforelse
        </div>
    </div>

    <div class="card overflow-hidden animate-fadeUp d1">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Keputusan</div></div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Order</th><th>Jenis</th><th class="text-right">Nominal</th><th>Diajukan</th><th>Keputusan</th><th>Catatan</th></tr></thead>
                <tbody>
                    @forelse ($this->history as $request)
                        <tr wire:key="rh-{{ $request->id }}">
                            <td class="td-main"><a href="{{ route('orders.show', $request->order) }}" wire:navigate class="link">{{ $request->order->number }}</a></td>
                            <td>{{ $request->type->label() }}</td>
                            <td class="text-right">{{ Money::format($request->amount) }}</td>
                            <td>{{ $request->requester?->name }}</td>
                            <td><span class="badge {{ $request->status->badge() }}">{{ $request->status->label() }}</span> <span class="text-[11.5px] text-pb-muted">{{ $request->decider?->name }}</span></td>
                            <td class="!whitespace-normal text-[12px]">{{ $request->decision_note ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada keputusan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->history->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->history->links() }}</div>
        @endif
    </div>
</div>
