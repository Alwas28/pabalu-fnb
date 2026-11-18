<?php

use App\Enums\PaymentMethod;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Support\Money;
use App\Support\Purchasing\PurchaseService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public PurchaseOrder $purchaseOrder;

    /** @var array<int|string, string> */
    public array $receive = [];
    public string $receiveNotes = '';

    public string $payAmount = '';
    public string $payMethod = 'bank_transfer';
    public string $payDate = '';
    public string $payReference = '';

    /** @var array<int|string, string> */
    public array $returnQty = [];
    public string $returnReason = '';

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        abort_unless($purchaseOrder->business_id === auth()->user()->activeBusiness()->id && auth()->user()->canAccessOutlet($purchaseOrder->outlet), 404);
        $this->purchaseOrder = $purchaseOrder;
        $this->payDate = now(auth()->user()->activeBusiness()->timezone)->toDateString();
    }

    public function rendering($view): void
    {
        $view->title($this->purchaseOrder->number);
    }

    private function refreshOrder(): void
    {
        $this->purchaseOrder = $this->purchaseOrder->fresh();
    }

    public function order(): void
    {
        app(PurchaseService::class)->markOrdered($this->purchaseOrder);
        $this->refreshOrder();
        $this->dispatch('toast', type: 'success', message: 'PO dipesan ke supplier');
    }

    public function cancel(): void
    {
        app(PurchaseService::class)->cancel($this->purchaseOrder);
        $this->refreshOrder();
        $this->dispatch('toast', type: 'success', message: 'PO dibatalkan');
    }

    public function openReceive(): void
    {
        $this->resetValidation();
        // Default: terima seluruh sisa pesanan.
        $this->receive = $this->purchaseOrder->items->mapWithKeys(fn ($i) => [
            $i->id => $i->remainingBase() > 0 ? (string) round($i->remainingBase() / (float) $i->unit_size, 3) : '',
        ])->all();
        $this->dispatch('open-modal', name: 'po-receive');
    }

    public function saveReceive(): void
    {
        app(PurchaseService::class)->receive(auth()->user(), $this->purchaseOrder, $this->receive, $this->receiveNotes ?: null);
        $this->refreshOrder();
        $this->reset('receive', 'receiveNotes');
        $this->dispatch('close-modal', name: 'po-receive');
        $this->dispatch('toast', type: 'success', message: 'Barang diterima, stok bertambah');
    }

    public function openPay(): void
    {
        $this->resetValidation();
        $this->payAmount = (string) $this->purchaseOrder->outstanding();
        $this->dispatch('open-modal', name: 'po-pay');
    }

    public function savePay(): void
    {
        app(PurchaseService::class)->pay(auth()->user(), $this->purchaseOrder, [
            'amount' => Money::parse($this->payAmount) ?? '',
            'method' => $this->payMethod,
            'paid_at' => $this->payDate,
            'reference' => $this->payReference ?: null,
        ]);
        $this->refreshOrder();
        $this->reset('payAmount', 'payReference');
        $this->dispatch('close-modal', name: 'po-pay');
        $this->dispatch('toast', type: 'success', message: 'Pembayaran supplier dicatat');
    }

    public function openReturn(): void
    {
        $this->resetValidation();
        $this->returnQty = [];
        $this->dispatch('open-modal', name: 'po-return');
    }

    public function saveReturn(): void
    {
        app(PurchaseService::class)->returnGoods(auth()->user(), $this->purchaseOrder, $this->returnQty, $this->returnReason);
        $this->refreshOrder();
        $this->reset('returnQty', 'returnReason');
        $this->dispatch('close-modal', name: 'po-return');
        $this->dispatch('toast', type: 'success', message: 'Retur dicatat, stok & tagihan berkurang');
    }

    public function with(): array
    {
        $po = $this->purchaseOrder->load(['supplier', 'outlet.business', 'items.ingredient', 'receipts.user', 'receipts.items', 'payments.user', 'returns.user', 'creator']);

        return ['po' => $po, 'tz' => $po->outlet->business->timezone ?: config('app.timezone')];
    }
}; ?>

<x-slot name="actions">
    @if ($purchaseOrder->isEditable())
        <a href="{{ route('purchasing.edit', $purchaseOrder) }}" wire:navigate class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-pen"></i><span class="hidden sm:inline">Ubah</span></a>
        <button type="button" wire:click="order" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-paper-plane"></i><span class="hidden sm:inline">Pesan</span></button>
    @endif
    @if ($purchaseOrder->canReceive())
        <button type="button" wire:click="openReceive" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-dolly"></i><span class="hidden sm:inline">Terima Barang</span></button>
    @endif
    @if ($purchaseOrder->outstanding() > 0)
        <button type="button" wire:click="openPay" class="btn btn-secondary !px-3 sm:!px-[18px]"><i class="fa-solid fa-money-check-dollar"></i><span class="hidden sm:inline">Bayar</span></button>
    @endif
</x-slot>

<div class="flex flex-col gap-5 max-w-5xl">
    <div class="flex items-center gap-2 text-[12.5px] text-pb-muted -mb-1">
        <a href="{{ route('purchasing.index') }}" wire:navigate class="link"><i class="fa-solid fa-arrow-left mr-1"></i>Pembelian</a>
        <span>/</span><span>{{ $po->number }}</span>
    </div>

    <div class="two-col">
        <div class="flex flex-col gap-5 min-w-0">
            <div class="card overflow-hidden animate-fadeUp">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-boxes-stacked"></i>Bahan</div>
                    <span class="badge {{ $po->status->badge() }}">{{ $po->status->label() }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="tbl">
                        <thead><tr><th>Bahan</th><th class="text-right">Dipesan</th><th class="text-right">Diterima</th><th class="text-right">Harga</th><th class="text-right">Jumlah</th></tr></thead>
                        <tbody>
                            @foreach ($po->items as $item)
                                <tr wire:key="pi-{{ $item->id }}">
                                    <td class="td-main">{{ $item->ingredient->name }}</td>
                                    <td class="text-right whitespace-nowrap">{{ Ingredient::qty($item->quantity) }} {{ $item->unit_label }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        {{ Ingredient::qty((float) $item->received_base_quantity / (float) $item->unit_size) }} {{ $item->unit_label }}
                                        @if ((float) $item->returned_base_quantity > 0)
                                            <div class="text-[11px] trend-down">retur {{ Ingredient::qty((float) $item->returned_base_quantity / (float) $item->unit_size) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">{{ Money::format($item->unit_price) }}</td>
                                    <td class="text-right whitespace-nowrap font-semibold">{{ Money::format($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4 border-t border-pb-border flex justify-end font-display text-lg font-bold text-pb-text">Nilai PO {{ Money::format($po->total) }}</div>
            </div>

            @if ($po->receipts->isNotEmpty() || $po->returns->isNotEmpty() || $po->payments->isNotEmpty())
                <div class="card animate-fadeUp d1">
                    <div class="card-header"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i>Riwayat</div></div>
                    <div class="flex flex-col divide-y divide-pb-border text-[13px]">
                        @foreach ($po->receipts as $receipt)
                            <div class="px-5 py-2.5 flex justify-between gap-3" wire:key="gr-{{ $receipt->id }}">
                                <span><i class="fa-solid fa-dolly a-text mr-1.5"></i>Penerimaan · {{ $receipt->items->count() }} bahan{{ $receipt->notes ? ' · '.$receipt->notes : '' }}</span>
                                <span class="text-pb-muted">{{ $receipt->received_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $receipt->user?->name }}</span>
                            </div>
                        @endforeach
                        @foreach ($po->returns as $return)
                            <div class="px-5 py-2.5 flex justify-between gap-3" wire:key="pr-{{ $return->id }}">
                                <span class="trend-down"><i class="fa-solid fa-rotate-left mr-1.5"></i>Retur {{ Money::format($return->total) }} · {{ $return->reason }}</span>
                                <span class="text-pb-muted">{{ $return->created_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $return->user?->name }}</span>
                            </div>
                        @endforeach
                        @foreach ($po->payments as $payment)
                            <div class="px-5 py-2.5 flex justify-between gap-3" wire:key="pp-{{ $payment->id }}">
                                <span class="trend-up"><i class="fa-solid fa-money-check-dollar mr-1.5"></i>Bayar {{ Money::format($payment->amount) }} · {{ $payment->method->label() }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</span>
                                <span class="text-pb-muted">{{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->user?->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-5">
            <div class="card animate-fadeUp d1">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-circle-info"></i>Detail</div></div>
                <div class="card-body flex flex-col gap-2 text-[13px]">
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Supplier</span><span class="text-pb-text text-right">{{ $po->supplier->name }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Outlet</span><span class="text-pb-text">{{ $po->outlet->name }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Tanggal PO</span><span class="text-pb-text">{{ $po->order_date->format('d/m/Y') }}</span></div>
                    @if ($po->expected_date)<div class="flex justify-between gap-3"><span class="text-pb-muted">Perkiraan tiba</span><span class="text-pb-text">{{ $po->expected_date->format('d/m/Y') }}</span></div>@endif
                    @if ($po->due_date)<div class="flex justify-between gap-3"><span class="text-pb-muted">Jatuh tempo</span><span @class(['text-right', 'trend-down font-semibold' => $po->outstanding() > 0 && $po->due_date->isPast(), 'text-pb-text' => ! ($po->outstanding() > 0 && $po->due_date->isPast())])>{{ $po->due_date->format('d/m/Y') }}</span></div>@endif
                    <div class="flex justify-between gap-3"><span class="text-pb-muted">Dibuat</span><span class="text-pb-text">{{ $po->creator?->name }}</span></div>
                    @if ($po->notes)<div class="text-pb-sub">{{ $po->notes }}</div>@endif
                </div>
            </div>
            <div class="card animate-fadeUp d2">
                <div class="card-header"><div class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i>Tagihan</div></div>
                <div class="card-body flex flex-col gap-1.5 text-[13px]">
                    <div class="flex justify-between text-pb-sub"><span>Barang diterima</span><span>{{ Money::format($po->received_total) }}</span></div>
                    <div class="flex justify-between text-pb-sub"><span>Retur</span><span>-{{ Money::format($po->returned_total) }}</span></div>
                    <div class="flex justify-between text-pb-sub"><span>Dibayar</span><span>-{{ Money::format($po->paid_amount) }}</span></div>
                    <div class="flex justify-between font-display text-lg font-bold text-pb-text border-t border-pb-border pt-1.5 mt-1"><span>Sisa</span><span>{{ Money::format($po->outstanding()) }}</span></div>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @if ($po->received_total > $po->returned_total)
                            <button type="button" wire:click="openReturn" class="btn-ghost text-[12px] font-semibold"><i class="fa-solid fa-rotate-left mr-1"></i>Retur barang</button>
                        @endif
                        @if (in_array($po->status->value, ['draft', 'ordered'], true) && $po->received_total === 0)
                            <button type="button" wire:click="cancel" wire:confirm="Batalkan {{ $po->number }}?" class="btn-ghost text-[12px] font-semibold" style="color:#f87171">Batalkan PO</button>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('order')" />
                </div>
            </div>
        </div>
    </div>

    {{-- ── Terima barang ── --}}
    <x-modal name="po-receive" maxWidth="lg" focusable>
        <form wire:submit="saveReceive">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Terima Barang</div>
                <div class="text-xs text-pb-muted mt-px">Periksa jumlah & kualitas. Isi jumlah yang benar-benar diterima; sisanya bisa diterima nanti.</div>
            </div>
            <div class="px-6 py-5 flex flex-col gap-3 max-h-[60vh] overflow-y-auto">
                @foreach ($po->items as $item)
                    @continue($item->remainingBase() <= 0)
                    <div class="grid grid-cols-[1fr_150px] gap-3 items-center" wire:key="rcv-{{ $item->id }}">
                        <div>
                            <div class="text-[13px] font-semibold text-pb-text">{{ $item->ingredient->name }}</div>
                            <div class="text-[11.5px] text-pb-muted">Sisa {{ Ingredient::qty($item->remainingBase() / (float) $item->unit_size) }} {{ $item->unit_label }}</div>
                        </div>
                        <div>
                            <div class="relative">
                                <input type="number" step="0.001" min="0" wire:model="receive.{{ $item->id }}" class="f-input !pr-12" aria-label="Diterima">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-pb-muted">{{ $item->unit_label }}</span>
                            </div>
                            <x-input-error :messages="$errors->get('receive.'.$item->id)" class="mt-1" />
                        </div>
                    </div>
                @endforeach
                <x-input-error :messages="$errors->get('receive')" />
                <div>
                    <x-input-label for="receiveNotes" value="Catatan (opsional)" />
                    <x-text-input wire:model="receiveNotes" id="receiveNotes" placeholder="mis. no. surat jalan, kondisi barang" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveReceive"><i class="fa-solid fa-dolly"></i>Terima</x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- ── Bayar ── --}}
    <x-modal name="po-pay" maxWidth="md" focusable>
        <form wire:submit="savePay">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Bayar Tagihan</div>
                <div class="text-xs text-pb-muted mt-px">Sisa tagihan {{ Money::format($po->outstanding()) }}</div>
            </div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="payAmount" value="Nominal (Rp)" />
                    <x-text-input wire:model="payAmount" id="payAmount" type="number" min="1" required />
                    <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="payDate" value="Tanggal bayar" />
                    <x-text-input wire:model="payDate" id="payDate" type="date" required />
                    <x-input-error :messages="$errors->get('paid_at')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="payMethod" value="Metode" />
                    <select wire:model="payMethod" id="payMethod" class="f-input">
                        @foreach (PaymentMethod::cases() as $method)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="payReference" value="Referensi" />
                    <x-text-input wire:model="payReference" id="payReference" placeholder="No. transfer" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="savePay"><i class="fa-solid fa-check"></i>Catat Pembayaran</x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- ── Retur ── --}}
    <x-modal name="po-return" maxWidth="lg" focusable>
        <form wire:submit="saveReturn">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Retur ke Supplier</div>
                <div class="text-xs text-pb-muted mt-px">Stok outlet berkurang dan tagihan dikurangi sebesar nilai retur.</div>
            </div>
            <div class="px-6 py-5 flex flex-col gap-3 max-h-[60vh] overflow-y-auto">
                @foreach ($po->items as $item)
                    @continue($item->returnableBase() <= 0)
                    <div class="grid grid-cols-[1fr_150px] gap-3 items-center" wire:key="ret-{{ $item->id }}">
                        <div>
                            <div class="text-[13px] font-semibold text-pb-text">{{ $item->ingredient->name }}</div>
                            <div class="text-[11.5px] text-pb-muted">Bisa diretur {{ Ingredient::qty($item->returnableBase() / (float) $item->unit_size) }} {{ $item->unit_label }}</div>
                        </div>
                        <div>
                            <div class="relative">
                                <input type="number" step="0.001" min="0" wire:model="returnQty.{{ $item->id }}" class="f-input !pr-12" aria-label="Retur">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-pb-muted">{{ $item->unit_label }}</span>
                            </div>
                            <x-input-error :messages="$errors->get('return.'.$item->id)" class="mt-1" />
                        </div>
                    </div>
                @endforeach
                <x-input-error :messages="$errors->get('return')" />
                <div>
                    <x-input-label for="returnReason" value="Alasan retur" />
                    <x-text-input wire:model="returnReason" id="returnReason" placeholder="mis. Barang rusak saat diterima" required />
                    <x-input-error :messages="$errors->get('reason')" class="mt-1.5" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveReturn"><i class="fa-solid fa-rotate-left"></i>Catat Retur</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
