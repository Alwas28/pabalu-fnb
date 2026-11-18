<?php

use App\Enums\CashMovementType;
use App\Models\CashShift;
use App\Models\Outlet;
use App\Policies\CashShiftPolicy;
use App\Support\Money;
use App\Support\Pos\ShiftManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Shift Kasir')] class extends Component
{
    use WithPagination;

    // Buka shift
    public string $openingCash = '';
    public string $openingNote = '';

    // Kas masuk / keluar
    public string $movementType = 'in';
    public string $movementAmount = '';
    public string $movementReason = '';

    // Tutup shift
    public string $actualCash = '';
    public string $closingNote = '';

    #[Computed]
    public function outlet(): ?Outlet
    {
        return auth()->user()->currentOutlet();
    }

    #[Computed]
    public function shift(): ?CashShift
    {
        return $this->outlet ? CashShift::currentFor(auth()->user(), $this->outlet)?->load('movements.user') : null;
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->shift ? app(ShiftManager::class)->summary($this->shift) : null;
    }

    #[Computed]
    public function history()
    {
        $user = auth()->user();

        return CashShift::query()
            ->with(['outlet', 'user'])
            ->whereIn('outlet_id', $user->accessibleOutlets()->pluck('id'))
            ->unless(CashShiftPolicy::seesAllShifts($user), fn ($q) => $q->where('user_id', $user->id))
            ->latest('opened_at')
            ->latest('id')
            ->paginate(15);
    }

    public function canOpenHere(): bool
    {
        return $this->outlet && auth()->user()->can('usePos', $this->outlet);
    }

    public function openShift(): void
    {
        abort_unless($this->canOpenHere(), 403);

        app(ShiftManager::class)->open(auth()->user(), $this->outlet, [
            'opening_cash' => Money::parse($this->openingCash) ?? '',
            'opening_note' => $this->openingNote ?: null,
        ]);

        $this->reset('openingCash', 'openingNote');
        unset($this->shift, $this->summary, $this->history);
        $this->dispatch('toast', type: 'success', message: 'Shift dibuka. Selamat bekerja!');
    }

    public function recordMovement(): void
    {
        $shift = $this->shift;
        abort_unless($shift, 404);
        $this->authorize('update', $shift);

        app(ShiftManager::class)->recordMovement(auth()->user(), $shift, [
            'type' => $this->movementType,
            'amount' => Money::parse($this->movementAmount) ?? '',
            'reason' => $this->movementReason,
        ]);

        $this->reset('movementType', 'movementAmount', 'movementReason');
        unset($this->shift, $this->summary);
        $this->dispatch('close-modal', name: 'cash-movement');
        $this->dispatch('toast', type: 'success', message: 'Kas dicatat');
    }

    public function closeShift(): void
    {
        $shift = $this->shift;
        abort_unless($shift, 404);
        $this->authorize('update', $shift);

        app(ShiftManager::class)->close(auth()->user(), $shift, [
            'actual_cash' => Money::parse($this->actualCash) ?? '',
            'closing_note' => $this->closingNote ?: null,
        ]);

        session()->flash('toast', 'Shift ditutup');
        $this->redirectRoute('shifts.show', $shift, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-5 max-w-5xl">
    @if (! $this->outlet)
        <div class="card"><div class="card-body text-center text-pb-muted">Belum ada outlet yang ditugaskan.</div></div>
    @elseif ($shift = $this->shift)
        @php
            $summary = $this->summary;
            $actual = Money::parse($actualCash);
            $difference = $actual === null ? null : $actual - $summary['expected_cash'];
        @endphp
        <div class="card animate-fadeUp">
            <div class="card-header flex-wrap gap-3">
                <div>
                    <div class="card-title"><i class="fa-solid fa-clock"></i>Shift Berjalan · {{ $this->outlet->name }}</div>
                    <div class="section-desc">Dibuka {{ $shift->opened_at->timezone($this->outlet->business->timezone)->translatedFormat('d M Y, H:i') }} oleh {{ $shift->user->name }}</div>
                </div>
                <div class="flex gap-2">
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'cash-movement')" class="btn btn-secondary"><i class="fa-solid fa-right-left"></i>Kas Masuk/Keluar</button>
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'close-shift')" class="btn btn-primary"><i class="fa-solid fa-lock"></i>Tutup Shift</button>
                </div>
            </div>
            <div class="card-body flex flex-col gap-4">
                <x-shift-summary :summary="$summary" :shift="$shift" />

                @if ($shift->movements->isNotEmpty())
                    <div>
                        <div class="f-label">Catatan kas masuk / keluar</div>
                        <div class="flex flex-col divide-y divide-pb-border rounded-xl border border-pb-border text-[13px]">
                            @foreach ($shift->movements as $movement)
                                <div class="px-4 py-2 flex justify-between gap-3" wire:key="mv-{{ $movement->id }}">
                                    <span class="text-pb-sub">{{ $movement->created_at->timezone($this->outlet->business->timezone)->format('H:i') }} · {{ $movement->reason }}</span>
                                    <span @class(['font-semibold', 'trend-up' => $movement->type === CashMovementType::In, 'trend-down' => $movement->type === CashMovementType::Out])>
                                        {{ $movement->type === CashMovementType::In ? '+' : '-' }}{{ Money::format($movement->amount) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @can('pos.use')
                    <a href="{{ route('pos') }}" wire:navigate class="btn btn-secondary self-start"><i class="fa-solid fa-cash-register"></i>Ke Kasir</a>
                @endcan
            </div>
        </div>

        <x-modal name="cash-movement" maxWidth="md" focusable>
            <form wire:submit="recordMovement">
                <div class="px-6 py-5 border-b border-pb-border font-display text-base font-bold text-pb-text">Kas Masuk / Keluar</div>
                <div class="px-6 py-5 flex flex-col gap-4">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (CashMovementType::cases() as $type)
                            <label class="choice-card !p-3 !items-center">
                                <input type="radio" wire:model="movementType" value="{{ $type->value }}" name="movementType">
                                <span class="text-[13px] font-semibold text-pb-text">{{ $type->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div>
                        <x-input-label for="movementAmount" value="Nominal (Rp)" />
                        <x-text-input wire:model="movementAmount" id="movementAmount" type="number" min="1" required />
                        <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                    </div>
                    <div>
                        <x-input-label for="movementReason" value="Alasan" />
                        <x-text-input wire:model="movementReason" id="movementReason" placeholder="mis. Tambah uang kecil, beli es batu, setor ke owner" required />
                        <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="recordMovement"><i class="fa-solid fa-check"></i>Catat</x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal name="close-shift" maxWidth="md" focusable>
            <form wire:submit="closeShift">
                <div class="px-6 py-5 border-b border-pb-border">
                    <div class="font-display text-base font-bold text-pb-text">Tutup Shift</div>
                    <div class="text-xs text-pb-muted mt-px">Hitung uang di laci, lalu masukkan jumlahnya.</div>
                </div>
                <div class="px-6 py-5 flex flex-col gap-4">
                    <div class="rounded-xl bg-pb-surface2 p-4 flex justify-between text-[13px]">
                        <span class="text-pb-sub">Kas ekspektasi</span><span class="font-bold text-pb-text">{{ Money::format($summary['expected_cash']) }}</span>
                    </div>
                    <div>
                        <x-input-label for="actualCash" value="Kas aktual di laci (Rp)" />
                        <x-text-input wire:model.live.debounce.300ms="actualCash" id="actualCash" type="number" min="0" required />
                        <x-input-error :messages="$errors->get('actual_cash')" class="mt-1.5" />
                        @if ($difference !== null)
                            <p @class(['f-hint font-semibold', 'trend-up' => $difference > 0, 'trend-down' => $difference < 0])>
                                {{ $difference === 0 ? 'Kas pas, tidak ada selisih.' : 'Selisih '.($difference > 0 ? '+' : '').Money::format($difference) }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <x-input-label for="closingNote" value="Catatan {{ $difference ? '(wajib karena ada selisih)' : '(opsional)' }}" />
                        <textarea wire:model="closingNote" id="closingNote" rows="2" class="f-input resize-none"></textarea>
                        <x-input-error :messages="$errors->get('closing_note')" class="mt-1.5" />
                    </div>
                    @if ($summary['open_bills'] > 0)
                        <p class="f-hint !mt-0 trend-down"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Masih ada {{ $summary['open_bills'] }} pesanan belum dibayar. Pesanan tetap terbuka dan bisa dilunasi di shift berikutnya.</p>
                    @endif
                </div>
                <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                    <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                    <x-primary-button wire:loading.attr="disabled" wire:target="closeShift"><i class="fa-solid fa-lock"></i>Tutup Shift</x-primary-button>
                </div>
            </form>
        </x-modal>
    @elseif ($this->canOpenHere())
        <form wire:submit="openShift" class="card max-w-lg animate-fadeUp">
            <div class="card-header">
                <div>
                    <div class="card-title"><i class="fa-solid fa-door-open"></i>Buka Shift · {{ $this->outlet->name }}</div>
                    <div class="section-desc">Shift wajib dibuka sebelum menerima transaksi.</div>
                </div>
            </div>
            <div class="card-body flex flex-col gap-4">
                <div>
                    <x-input-label for="openingCash" value="Modal awal kas (Rp)" />
                    <x-text-input wire:model="openingCash" id="openingCash" type="number" min="0" placeholder="mis. 200000" required />
                    <x-input-error :messages="$errors->get('opening_cash')" class="mt-1.5" />
                    <x-input-error :messages="$errors->get('shift')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="openingNote" value="Catatan (opsional)" />
                    <x-text-input wire:model="openingNote" id="openingNote" />
                </div>
                <x-primary-button class="self-start" wire:loading.attr="disabled" wire:target="openShift"><i class="fa-solid fa-door-open"></i>Buka Shift</x-primary-button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden animate-fadeUp d1">
        <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat Shift</div></div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Dibuka</th><th>Kasir</th><th>Outlet</th><th class="text-right">Modal</th><th class="text-right">Selisih</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($this->history as $row)
                        <tr wire:key="sh-{{ $row->id }}">
                            <td class="td-main"><a href="{{ route('shifts.show', $row) }}" wire:navigate class="link">{{ $row->opened_at->timezone(auth()->user()->activeBusiness()->timezone ?: config('app.timezone'))->format('d/m/Y H:i') }}</a></td>
                            <td>{{ $row->user->name }}</td>
                            <td>{{ $row->outlet->name }}</td>
                            <td class="text-right">{{ Money::format($row->opening_cash) }}</td>
                            <td @class(['text-right font-semibold', 'trend-up' => $row->cash_difference > 0, 'trend-down' => $row->cash_difference < 0])>
                                {{ $row->cash_difference === null ? '—' : ($row->cash_difference > 0 ? '+' : '').Money::format($row->cash_difference) }}
                            </td>
                            <td><span class="badge {{ $row->status->badge() }}">{{ $row->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center !py-8">Belum ada shift.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->history->hasPages())
            <div class="px-5 py-3 border-t border-pb-border">{{ $this->history->links() }}</div>
        @endif
    </div>
</div>
