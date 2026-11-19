<?php

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\Money;
use App\Support\Reports\FinanceMetrics;
use App\Support\Reports\Period;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Keuangan')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** '' = semua outlet */
    #[Url(as: 'outlet')]
    public string $outletId = '';

    // Form pengeluaran
    public string $expenseOutletId = '';
    public string $categoryId = '';
    public string $newCategory = '';
    public string $amount = '';
    public string $method = 'cash';
    public string $spentAt = '';
    public string $description = '';

    public function mount(): void
    {
        $business = $this->business();

        if (! $business->expenseCategories()->exists()) {
            foreach (ExpenseCategory::DEFAULTS as $name) {
                $business->expenseCategories()->create(['name' => $name]);
            }
        }

        $period = Period::make($this->from ?: null, $this->to ?: null, $business->timezone);
        $this->from = $period->from->toDateString();
        $this->to = $period->to->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    #[Computed]
    public function outlets()
    {
        return auth()->user()->accessibleOutlets();
    }

    #[Computed]
    public function metrics(): FinanceMetrics
    {
        $ids = $this->outlets->pluck('id');
        $selected = $ids->contains((int) $this->outletId) ? [(int) $this->outletId] : $ids->all();

        return new FinanceMetrics($this->business()->id, $selected, Period::make($this->from, $this->to, $this->business()->timezone), $this->outletId === '');
    }

    #[Computed]
    public function expenseRows()
    {
        return $this->metrics->expenses()->with(['category', 'outlet', 'user'])->latest('spent_at')->latest('id')->paginate(15);
    }

    #[Computed]
    public function categories()
    {
        return $this->business()->expenseCategories()->orderBy('name')->get();
    }

    #[Computed]
    public function expenseByCategory()
    {
        return $this->metrics->expenses()->selectRaw('expense_category_id, sum(amount) as total')->groupBy('expense_category_id')
            ->pluck('total', 'expense_category_id')->map(fn ($v) => (int) $v)->sortDesc();
    }

    #[On('expense-open')]
    public function openExpense(): void
    {
        $this->resetValidation();
        $this->reset('categoryId', 'newCategory', 'amount', 'method', 'description');
        $this->expenseOutletId = $this->outletId;
        $this->spentAt = now($this->business()->timezone)->toDateString();
        $this->dispatch('open-modal', name: 'expense-form');
    }

    public function saveExpense(): void
    {
        $business = $this->business();
        $outletIds = $this->outlets->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->validate([
            'expenseOutletId' => ['nullable', Rule::in($outletIds)],
            'categoryId' => ['required_without:newCategory', 'nullable', Rule::exists('expense_categories', 'id')->where('business_id', $business->id)],
            'newCategory' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'spentAt' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
        ], ['categoryId.required_without' => 'Pilih kategori atau tulis kategori baru.'],
            ['amount' => 'nominal', 'spentAt' => 'tanggal', 'description' => 'keterangan']);

        $category = $this->newCategory !== ''
            ? $business->expenseCategories()->firstOrCreate(['name' => trim($this->newCategory)])
            : $business->expenseCategories()->findOrFail($this->categoryId);

        $business->expenses()->create([
            'outlet_id' => $this->expenseOutletId ?: null,
            'expense_category_id' => $category->id,
            'amount' => (int) $this->amount,
            'method' => $this->method,
            'spent_at' => $this->spentAt,
            'description' => $this->description,
            'user_id' => auth()->id(),
        ]);

        $this->dispatch('close-modal', name: 'expense-form');
        $this->dispatch('toast', type: 'success', message: 'Pengeluaran dicatat');
        unset($this->metrics, $this->expenseRows, $this->categories, $this->expenseByCategory);
    }

    public function deleteExpense(int $id): void
    {
        $expense = Expense::where('business_id', $this->business()->id)->find($id);
        abort_unless($expense, 404);
        abort_unless($expense->outlet_id === null || $this->outlets->contains('id', $expense->outlet_id), 403);

        $expense->delete();
        $this->dispatch('toast', type: 'success', message: 'Pengeluaran dihapus');
        unset($this->metrics, $this->expenseRows, $this->expenseByCategory);
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('expense-open')" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Catat Pengeluaran</span></button>
</x-slot>

<div class="flex flex-col gap-5">
    @php
        $s = $this->metrics->summary();
        $cash = $this->metrics->cashFlow();
        $methods = $this->metrics->paymentsByMethod();
        $categories = $this->categories->keyBy('id');
    @endphp

    <div class="flex flex-wrap items-end gap-2">
        <div><x-input-label for="from" value="Dari" /><input type="date" wire:model.live="from" id="from" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        <div><x-input-label for="to" value="Sampai" /><input type="date" wire:model.live="to" id="to" class="f-input !py-1.5 !w-40 !text-[12.5px]"></div>
        <div>
            <x-input-label for="outletId" value="Outlet" />
            <select wire:model.live="outletId" id="outletId" class="f-input !py-1.5 !w-48 !text-[12.5px]">
                <option value="">Semua outlet</option>
                @foreach ($this->outlets as $outlet)
                    <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Penjualan bersih</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ Money::format($s['net_sales']) }}</div><div class="text-[11.5px] text-pb-muted">{{ number_format($s['orders'], 0, ',', '.') }} transaksi</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Laba kotor</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ Money::format($s['gross_profit']) }}</div><div class="text-[11.5px] text-pb-muted">margin {{ number_format($s['gross_margin'], 1, ',', '.') }}%</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Pengeluaran operasional</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ Money::format($s['expenses']) }}</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Laba operasional</div><div @class(['stat-num', 'trend-up' => $s['operating_profit'] > 0, 'trend-down' => $s['operating_profit'] < 0])>{{ Money::format($s['operating_profit']) }}</div></div></div>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="card animate-fadeUp">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-scale-balanced"></i>Laba Rugi</div></div>
            <div class="card-body flex flex-col gap-1.5 text-[13px]">
                <div class="flex justify-between text-pb-sub"><span>Penjualan kotor</span><span>{{ Money::format($s['gross_sales']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Diskon</span><span>-{{ Money::format($s['discount']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Refund (porsi penjualan)</span><span>-{{ Money::format($s['gross_sales'] - $s['discount'] - $s['net_sales']) }}</span></div>
                <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Penjualan bersih</span><span>{{ Money::format($s['net_sales']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>HPP bahan (resep)</span><span>-{{ Money::format($s['cogs']) }}</span></div>
                <div class="flex justify-between font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Laba kotor</span><span>{{ Money::format($s['gross_profit']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pengeluaran operasional</span><span>-{{ Money::format($s['expenses']) }}</span></div>
                <div class="flex justify-between font-display text-base font-bold text-pb-text border-t border-pb-border pt-1.5"><span>Laba operasional</span><span>{{ Money::format($s['operating_profit']) }}</span></div>
                <div class="text-[11.5px] text-pb-muted mt-2">Pajak {{ Money::format($s['tax']) }} &amp; service charge {{ Money::format($s['service_charge']) }} dicatat terpisah. Void: {{ $s['void_count'] }} transaksi ({{ Money::format($s['void_amount']) }}).</div>
            </div>
        </div>

        <div class="card animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-money-bill-transfer"></i>Arus Kas</div></div>
            <div class="card-body flex flex-col gap-1.5 text-[13px]">
                <div class="flex justify-between text-pb-sub"><span>Diterima dari pelanggan</span><span class="trend-up">{{ Money::format($cash['received']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Refund ke pelanggan</span><span>-{{ Money::format($cash['refunded']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pembayaran supplier</span><span>-{{ Money::format($cash['supplier_payments']) }}</span></div>
                <div class="flex justify-between text-pb-sub"><span>Pengeluaran operasional</span><span>-{{ Money::format($cash['expenses']) }}</span></div>
                <div @class(['flex justify-between font-display text-base font-bold border-t border-pb-border pt-1.5', 'text-pb-text' => $cash['net'] >= 0, 'trend-down' => $cash['net'] < 0])><span>Arus kas bersih</span><span>{{ Money::format($cash['net']) }}</span></div>
                <div class="text-[11.5px] text-pb-muted mt-2">Berdasarkan tanggal uang diterima / dibayar, bukan tanggal transaksi.</div>
            </div>
        </div>

        <div class="card animate-fadeUp d2">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-wallet"></i>Metode Pembayaran</div></div>
            <div class="card-body flex flex-col gap-2 text-[13px]">
                @php $methodTotal = max(1, collect($methods)->sum('net')); @endphp
                @forelse ($methods as $method => $row)
                    <div>
                        <div class="flex justify-between"><span class="text-pb-text">{{ PaymentMethod::tryFrom($method)?->label() ?? $method }}</span><span class="font-semibold">{{ Money::format($row['net']) }}</span></div>
                        <div class="prog-bar mt-1"><div class="prog-fill" style="width: {{ max(2, round($row['net'] / $methodTotal * 100)) }}%"></div></div>
                        @if ($row['refunded'])<div class="text-[11px] text-pb-muted mt-0.5">refund {{ Money::format($row['refunded']) }}</div>@endif
                    </div>
                @empty
                    <div class="text-pb-muted">Belum ada pembayaran.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div class="card overflow-hidden animate-fadeUp">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-receipt"></i>Pengeluaran Operasional</div></div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Outlet</th><th>Metode</th><th class="text-right">Nominal</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->expenseRows as $expense)
                            <tr wire:key="ex-{{ $expense->id }}">
                                <td class="whitespace-nowrap">{{ $expense->spent_at->format('d/m/Y') }}</td>
                                <td>{{ $expense->category->name }}</td>
                                <td class="!whitespace-normal">{{ $expense->description }}<div class="text-[11px] text-pb-muted">{{ $expense->user?->name }}</div></td>
                                <td>{{ $expense->outlet?->name ?? 'Bersama' }}</td>
                                <td>{{ $expense->method->label() }}</td>
                                <td class="text-right font-semibold whitespace-nowrap">{{ Money::format($expense->amount) }}</td>
                                <td class="text-right"><button type="button" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Hapus pengeluaran ini?" class="btn-ghost" style="color:#f87171" aria-label="Hapus"><i class="fa-solid fa-trash"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center !py-8">Belum ada pengeluaran pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->expenseRows->hasPages())
                <div class="px-5 py-3 border-t border-pb-border">{{ $this->expenseRows->links() }}</div>
            @endif
        </div>

        <div class="card animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-pie"></i>Per Kategori</div></div>
            <div class="card-body flex flex-col gap-2 text-[13px]">
                @forelse ($this->expenseByCategory as $categoryId => $total)
                    <div class="flex justify-between"><span class="text-pb-sub">{{ $categories[$categoryId]->name ?? '—' }}</span><span class="font-semibold">{{ Money::format($total) }}</span></div>
                @empty
                    <div class="text-pb-muted">—</div>
                @endforelse
            </div>
        </div>
    </div>

    <x-modal name="expense-form" maxWidth="md" focusable>
        <form wire:submit="saveExpense">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Catat Pengeluaran</div>
                <div class="text-xs text-pb-muted mt-px">Pembelian bahan dicatat lewat modul Pembelian, bukan di sini.</div>
            </div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="categoryId" value="Kategori" />
                    <select wire:model="categoryId" id="categoryId" class="f-input">
                        <option value="">— Pilih —</option>
                        @foreach ($this->categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('categoryId')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="newCategory" value="… atau kategori baru" />
                    <x-text-input wire:model="newCategory" id="newCategory" />
                </div>
                <div>
                    <x-input-label for="amount" value="Nominal (Rp)" />
                    <x-text-input wire:model="amount" id="amount" type="number" min="1" required />
                    <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="spentAt" value="Tanggal" />
                    <x-text-input wire:model="spentAt" id="spentAt" type="date" required />
                    <x-input-error :messages="$errors->get('spentAt')" class="mt-1.5" />
                </div>
                <div>
                    <x-input-label for="expenseOutletId" value="Outlet" />
                    <select wire:model="expenseOutletId" id="expenseOutletId" class="f-input">
                        <option value="">Biaya bersama</option>
                        @foreach ($this->outlets as $outlet)
                            <option value="{{ $outlet->id }}">{{ $outlet->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="method" value="Dibayar via" />
                    <select wire:model="method" id="method" class="f-input">
                        @foreach (PaymentMethod::cases() as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2">
                    <x-input-label for="description" value="Keterangan" />
                    <x-text-input wire:model="description" id="description" placeholder="mis. Listrik bulan November" required />
                    <x-input-error :messages="$errors->get('description')" class="mt-1.5" />
                </div>
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="saveExpense"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
