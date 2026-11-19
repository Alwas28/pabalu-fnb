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
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Pengeluaran')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** '' = semua outlet */
    #[Url(as: 'outlet')]
    public string $outletId = '';

    #[Url(as: 'kategori')]
    public string $filterCategoryId = '';

    public string $search = '';

    // Form pengeluaran; null = catat baru
    public ?int $editingId = null;
    public string $expenseOutletId = '';
    public string $categoryId = '';
    public string $newCategory = '';
    public string $amount = '';
    public string $method = 'cash';
    public string $spentAt = '';
    public string $description = '';

    public function mount(): void
    {
        ExpenseCategory::syncTemplatesFor($this->business());

        $period = Period::make($this->from ?: null, $this->to ?: null, $this->business()->timezone);
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

    /** Semua kategori bisnis, termasuk bawaan yang sudah dinonaktifkan (untuk filter & riwayat). */
    #[Computed]
    public function categories()
    {
        return $this->business()->expenseCategories()->orderBy('name')->get();
    }

    /** Kategori yang ditawarkan di form; kategori lama dari catatan yang sedang diubah tetap ikut. */
    #[Computed]
    public function formCategories()
    {
        return $this->business()->expenseCategories()
            ->where(fn ($q) => $q->selectable()->when($this->editingCategoryId(), fn ($q, $id) => $q->orWhere('id', $id)))
            ->orderBy('name')->get();
    }

    private function editingCategoryId(): ?int
    {
        return $this->editingId ? Expense::whereKey($this->editingId)->value('expense_category_id') : null;
    }

    /** Pengeluaran pada periode & outlet terpilih (definisi sama dengan halaman Keuangan). */
    private function periodExpenses()
    {
        $ids = $this->outlets->pluck('id');
        $selected = $ids->contains((int) $this->outletId) ? [(int) $this->outletId] : $ids->all();

        return (new FinanceMetrics($this->business()->id, $selected, Period::make($this->from, $this->to, $this->business()->timezone), $this->outletId === ''))
            ->expenses();
    }

    #[Computed]
    public function rows()
    {
        return $this->periodExpenses()
            ->with(['category', 'outlet', 'user'])
            ->when($this->filterCategoryId !== '', fn ($q) => $q->where('expense_category_id', (int) $this->filterCategoryId))
            ->when($this->search !== '', fn ($q) => $q->where('description', 'like', '%'.$this->search.'%'))
            ->latest('spent_at')->latest('id')
            ->paginate(20);
    }

    /** @return array{total: int, count: int, by_category: \Illuminate\Support\Collection<int, int>} */
    #[Computed]
    public function summary(): array
    {
        $byCategory = $this->periodExpenses()->selectRaw('expense_category_id, sum(amount) as total')->groupBy('expense_category_id')
            ->pluck('total', 'expense_category_id')->map(fn ($v) => (int) $v)->sortDesc();

        return [
            'total' => $byCategory->sum(),
            'count' => $this->periodExpenses()->count(),
            'by_category' => $byCategory,
        ];
    }

    private function findExpense(int $id): Expense
    {
        $expense = Expense::where('business_id', $this->business()->id)->find($id);
        abort_unless($expense, 404);
        abort_unless($expense->outlet_id === null || $this->outlets->contains('id', $expense->outlet_id), 403);

        return $expense;
    }

    #[On('expense-open')]
    public function openExpense(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'categoryId', 'newCategory', 'amount', 'method', 'description');
        $this->expenseOutletId = $this->outletId;
        $this->spentAt = now($this->business()->timezone)->toDateString();
        $this->dispatch('open-modal', name: 'expense-form');
    }

    public function editExpense(int $id): void
    {
        $expense = $this->findExpense($id);

        $this->resetValidation();
        $this->editingId = $expense->id;
        $this->expenseOutletId = (string) ($expense->outlet_id ?? '');
        $this->categoryId = (string) $expense->expense_category_id;
        $this->newCategory = '';
        $this->amount = (string) $expense->amount;
        $this->method = $expense->method->value;
        $this->spentAt = $expense->spent_at->toDateString();
        $this->description = $expense->description;
        $this->dispatch('open-modal', name: 'expense-form');
    }

    public function saveExpense(): void
    {
        $business = $this->business();
        $outletIds = $this->outlets->pluck('id')->map(fn ($id) => (string) $id)->all();
        $expense = $this->editingId ? $this->findExpense($this->editingId) : null;

        $this->validate([
            'expenseOutletId' => ['nullable', Rule::in($outletIds)],
            'categoryId' => ['required_without:newCategory', 'nullable', Rule::in($this->formCategories->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'newCategory' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'spentAt' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
        ], ['categoryId.required_without' => 'Pilih kategori atau tulis kategori baru.'],
            ['amount' => 'nominal', 'spentAt' => 'tanggal', 'description' => 'keterangan']);

        $category = trim($this->newCategory) !== ''
            ? $business->expenseCategories()->firstOrCreate(['name' => trim($this->newCategory)])
            : $business->expenseCategories()->findOrFail($this->categoryId);

        $data = [
            'outlet_id' => $this->expenseOutletId ?: null,
            'expense_category_id' => $category->id,
            'amount' => (int) $this->amount,
            'method' => $this->method,
            'spent_at' => $this->spentAt,
            'description' => $this->description,
        ];

        $expense
            ? $expense->update($data)
            : $business->expenses()->create($data + ['user_id' => auth()->id()]);

        $this->dispatch('close-modal', name: 'expense-form');
        $this->dispatch('toast', type: 'success', message: $expense ? 'Pengeluaran diperbarui' : 'Pengeluaran dicatat');
        unset($this->rows, $this->summary, $this->categories, $this->formCategories);
    }

    public function deleteExpense(int $id): void
    {
        $this->findExpense($id)->delete();
        $this->dispatch('toast', type: 'success', message: 'Pengeluaran dihapus');
        unset($this->rows, $this->summary);
    }
}; ?>

<x-slot name="actions">
    <button type="button" x-data x-on:click="Livewire.dispatch('expense-open')" class="btn btn-primary !px-3 sm:!px-[18px]"><i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Catat Pengeluaran</span></button>
</x-slot>

<div class="flex flex-col gap-5">
    @php
        $summary = $this->summary;
        $categories = $this->categories->keyBy('id');
        $topCategoryId = $summary['by_category']->keys()->first();
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
        <div>
            <x-input-label for="filterCategoryId" value="Kategori" />
            <select wire:model.live="filterCategoryId" id="filterCategoryId" class="f-input !py-1.5 !w-48 !text-[12.5px]">
                <option value="">Semua kategori</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Total pengeluaran</div><div class="stat-num order-first whitespace-nowrap !text-[22px] trend-down">{{ Money::format($summary['total']) }}</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Jumlah catatan</div><div class="stat-num order-first whitespace-nowrap !text-[22px]">{{ number_format($summary['count'], 0, ',', '.') }}</div></div></div>
        <div class="stat-card"><div class="min-w-0 flex flex-col gap-1"><div class="stat-label">Kategori terbesar</div><div class="stat-num order-first truncate !text-[18px]">{{ $topCategoryId ? ($categories[$topCategoryId]->name ?? '—') : '—' }}</div>@if ($topCategoryId)<div class="text-[11.5px] text-pb-muted">{{ Money::format($summary['by_category'][$topCategoryId]) }}</div>@endif</div></div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div class="card overflow-hidden animate-fadeUp">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-receipt"></i>Daftar Pengeluaran</div>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-56 !text-[12.5px]" placeholder="Cari keterangan">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="tbl">
                    <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Outlet</th><th>Metode</th><th class="text-right">Nominal</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($this->rows as $expense)
                            <tr wire:key="ex-{{ $expense->id }}">
                                <td class="whitespace-nowrap">{{ $expense->spent_at->format('d/m/Y') }}</td>
                                <td>{{ $expense->category->name }}</td>
                                <td class="!whitespace-normal">{{ $expense->description }}<div class="text-[11px] text-pb-muted">{{ $expense->user?->name }}</div></td>
                                <td>{{ $expense->outlet?->name ?? 'Bersama' }}</td>
                                <td>{{ $expense->method->label() }}</td>
                                <td class="text-right font-semibold whitespace-nowrap">{{ Money::format($expense->amount) }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <button type="button" wire:click="editExpense({{ $expense->id }})" class="btn-ghost" aria-label="Ubah"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="Hapus pengeluaran ini?" class="btn-ghost" style="color:#f87171" aria-label="Hapus"><i class="fa-solid fa-trash"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center !py-8">Belum ada pengeluaran pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->rows->hasPages())
                <div class="px-5 py-3 border-t border-pb-border">{{ $this->rows->links() }}</div>
            @endif
        </div>

        <div class="card animate-fadeUp d1">
            <div class="card-header"><div class="card-title"><i class="fa-solid fa-chart-pie"></i>Per Kategori</div></div>
            <div class="card-body flex flex-col gap-2 text-[13px]">
                @php $categoryMax = max(1, $summary['by_category']->first() ?? 0); @endphp
                @forelse ($summary['by_category'] as $categoryId => $total)
                    <button type="button" wire:click="$set('filterCategoryId', '{{ $categoryId }}')" class="text-left">
                        <div class="flex justify-between"><span class="text-pb-sub">{{ $categories[$categoryId]->name ?? '—' }}</span><span class="font-semibold">{{ Money::format($total) }}</span></div>
                        <div class="prog-bar mt-1"><div class="prog-fill" style="width: {{ max(2, round($total / $categoryMax * 100)) }}%"></div></div>
                    </button>
                @empty
                    <div class="text-pb-muted">—</div>
                @endforelse
            </div>
        </div>
    </div>

    <x-modal name="expense-form" maxWidth="md" focusable>
        <form wire:submit="saveExpense">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Pengeluaran' : 'Catat Pengeluaran' }}</div>
                <div class="text-xs text-pb-muted mt-px">Belanja lewat Purchase Order sudah tercatat di modul Pembelian, jangan dicatat ulang di sini.</div>
            </div>
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="categoryId" value="Kategori" />
                    <select wire:model="categoryId" id="categoryId" class="f-input">
                        <option value="">— Pilih —</option>
                        @foreach ($this->formCategories as $category)
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
