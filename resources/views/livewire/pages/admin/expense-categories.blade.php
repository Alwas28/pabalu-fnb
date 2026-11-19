<?php

use App\Models\Expense;
use App\Models\ExpenseCategoryTemplate;
use App\Support\Admin\PlatformAdmin;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Kategori Pengeluaran')] class extends Component
{
    public string $newName = '';

    public ?int $renamingId = null;

    public string $renameTo = '';

    #[Computed]
    public function templates()
    {
        return ExpenseCategoryTemplate::query()
            ->withCount('categories')
            ->addSelect(['expenses_count' => Expense::selectRaw('count(*)')
                ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->whereColumn('expense_categories.template_id', 'expense_category_templates.id')])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();
    }

    public function create(PlatformAdmin $admin): void
    {
        $template = $admin->createExpenseCategory($this->newName);

        $this->reset('newName');
        unset($this->templates);
        $this->dispatch('toast', type: 'success', message: "Kategori {$template->name} ditambahkan untuk semua bisnis");
    }

    public function openRename(int $id): void
    {
        $template = ExpenseCategoryTemplate::findOrFail($id);

        $this->resetValidation();
        $this->renamingId = $template->id;
        $this->renameTo = $template->name;
        $this->dispatch('open-modal', name: 'expense-category-rename');
    }

    public function rename(PlatformAdmin $admin): void
    {
        $admin->renameExpenseCategory(ExpenseCategoryTemplate::findOrFail($this->renamingId), $this->renameTo);

        $this->dispatch('close-modal', name: 'expense-category-rename');
        unset($this->templates);
        $this->dispatch('toast', type: 'success', message: 'Nama kategori diperbarui di semua bisnis');
    }

    public function toggle(PlatformAdmin $admin, int $id): void
    {
        $template = ExpenseCategoryTemplate::findOrFail($id);
        $admin->setExpenseCategoryActive($template, ! $template->is_active);

        unset($this->templates);
        $this->dispatch('toast', type: 'success', message: $template->is_active ? 'Kategori diaktifkan' : 'Kategori dinonaktifkan');
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="text-[12.5px] text-pb-muted max-w-3xl -mb-1">
        Kategori bawaan untuk <b>semua bisnis</b>. Setiap bisnis mendapat kategori ini saat membuka menu Pengeluaran, dan owner tetap boleh menambah kategori sendiri.
        Kategori yang dinonaktifkan tidak ditawarkan lagi saat mencatat, tetapi riwayat pengeluarannya tetap utuh.
    </div>

    <div class="card overflow-hidden animate-fadeUp max-w-3xl">
        <div class="card-header flex-wrap gap-3">
            <div class="card-title"><i class="fa-solid fa-tags"></i>Kategori Pengeluaran</div>
            <form wire:submit="create" class="flex items-start gap-2">
                <div>
                    <input type="text" wire:model="newName" class="f-input !py-1.5 !w-56 !text-[12.5px]" placeholder="Nama kategori baru" aria-label="Nama kategori baru" maxlength="80">
                    <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                </div>
                <button type="submit" class="btn btn-primary !py-1.5 !px-3" wire:loading.attr="disabled" wire:target="create"><i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Tambah</span></button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead><tr><th>Kategori</th><th class="text-right">Dipakai bisnis</th><th class="text-right">Catatan pengeluaran</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($this->templates as $template)
                        <tr wire:key="tpl-{{ $template->id }}">
                            <td class="td-main">{{ $template->name }}</td>
                            <td class="text-right">{{ number_format($template->categories_count, 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($template->expenses_count, 0, ',', '.') }}</td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge badge-green">Aktif</span>
                                @else
                                    <span class="badge badge-gray">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button" wire:click="openRename({{ $template->id }})" class="btn-ghost" aria-label="Ganti nama"><i class="fa-solid fa-pen"></i></button>
                                <button type="button" wire:click="toggle({{ $template->id }})"
                                    wire:confirm="{{ $template->is_active ? 'Nonaktifkan kategori '.$template->name.'? Kategori tidak ditawarkan lagi di semua bisnis.' : 'Aktifkan kembali kategori '.$template->name.'?' }}"
                                    class="btn-ghost font-semibold text-[12.5px]">{{ $template->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center !py-8">Belum ada kategori bawaan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal name="expense-category-rename" maxWidth="md" focusable>
        <form wire:submit="rename">
            <div class="px-6 py-5 border-b border-pb-border">
                <div class="font-display text-base font-bold text-pb-text">Ganti Nama Kategori</div>
                <div class="text-xs text-pb-muted mt-px">Nama baru berlaku di semua bisnis, termasuk riwayat pengeluarannya.</div>
            </div>
            <div class="px-6 py-5">
                <x-input-label for="renameTo" value="Nama kategori" />
                <x-text-input wire:model="renameTo" id="renameTo" maxlength="80" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
            </div>
            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="rename"><i class="fa-solid fa-check"></i>Simpan</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
