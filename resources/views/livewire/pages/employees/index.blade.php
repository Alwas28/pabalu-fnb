<?php

use App\Enums\UserRole;
use App\Models\BusinessUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Karyawan')] class extends Component
{
    /** null = tambah anggota baru; selain itu = id business_users yang diedit. */
    public ?int $editingId = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = '';
    /** @var list<int|string> */
    public array $outletIds = [];
    public bool $isActive = true;

    public string $search = '';

    private function business()
    {
        return auth()->user()->activeBusiness();
    }

    private function actorRole(): UserRole
    {
        return auth()->user()->currentRole();
    }

    #[Computed]
    public function members()
    {
        return $this->business()->memberships()
            ->with(['user', 'outlets'])
            ->when($this->search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->get()
            ->sortBy([
                fn ($a, $b) => array_search($a->role, UserRole::cases()) <=> array_search($b->role, UserRole::cases()),
                fn ($a, $b) => strcmp($a->user->name, $b->user->name),
            ])
            ->values();
    }

    #[Computed]
    public function outlets()
    {
        return $this->business()->outlets()->get();
    }

    /** @return list<UserRole> */
    #[Computed]
    public function assignableRoles(): array
    {
        return $this->actorRole()->assignableRoles();
    }

    public function roleNeedsOutlets(): bool
    {
        $role = UserRole::tryFrom($this->role);

        return $role !== null && ! $role->coversAllOutlets();
    }

    /**
     * Dipicu tombol "Tambah Anggota" di header — tombol itu berada di slot layout
     * (di luar komponen), sehingga memanggil lewat event browser, bukan wire:click.
     */
    #[On('employee-create')]
    public function create(): void
    {
        $this->authorize('create', BusinessUser::class);

        $this->resetForm();
        $this->role = $this->assignableRoles[array_key_last($this->assignableRoles)]?->value ?? '';
        $this->dispatch('open-modal', name: 'member-form');
    }

    public function edit(int $id): void
    {
        $member = $this->findMember($id);
        $this->authorize('update', $member);

        $this->resetForm();
        $this->fill([
            'editingId' => $member->id,
            'name' => $member->user->name,
            'email' => $member->user->email,
            'role' => $member->role->value,
            'outletIds' => $member->outlets->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'isActive' => $member->is_active,
        ]);
        $this->dispatch('open-modal', name: 'member-form');
    }

    public function save(): void
    {
        $editing = $this->editingId ? $this->findMember($this->editingId) : null;
        $this->authorize($editing ? 'update' : 'create', $editing ?? BusinessUser::class);

        $existingUser = $editing ? null : User::where('email', $this->email)->first();

        $this->validate([
            'name' => [$editing || $existingUser ? 'nullable' : 'required', 'string', 'max:120'],
            'email' => [$editing ? 'nullable' : 'required', 'email', 'max:255'],
            'password' => [$editing || $existingUser ? 'nullable' : 'required', Password::defaults()],
            'role' => ['required', Rule::in(array_map(fn (UserRole $r) => $r->value, $this->assignableRoles))],
            'outletIds' => [Rule::requiredIf($this->roleNeedsOutlets()), 'array'],
            'outletIds.*' => [Rule::in($this->outlets->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'isActive' => ['boolean'],
        ], [
            'outletIds.required' => 'Pilih minimal satu outlet untuk role ini.',
            'role.in' => 'Kamu tidak berhak memberikan role ini.',
        ]);

        if ($existingUser && $this->business()->memberships()->where('user_id', $existingUser->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah terdaftar sebagai anggota bisnis.']);
        }

        $role = UserRole::from($this->role);
        $outletIds = $role->coversAllOutlets() ? [] : array_map('intval', $this->outletIds);

        DB::transaction(function () use ($editing, $existingUser, $role, $outletIds) {
            if ($editing) {
                $member = $editing;
                $member->update(['role' => $role, 'is_active' => $this->isActive]);
            } else {
                // Email baru → buat akun dengan password awal; email terdaftar → tautkan akun yang ada.
                $user = $existingUser ?? User::forceCreate([
                    'name' => $this->name,
                    'email' => $this->email,
                    'password' => Hash::make($this->password),
                    'email_verified_at' => now(),
                ]);

                $member = BusinessUser::create([
                    'business_id' => $this->business()->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'is_active' => $this->isActive,
                ]);

                $user->current_business_id ??= $this->business()->id;
                $user->save();
            }

            $member->outlets()->sync($outletIds);

            // Akses dicabut → keluarkan dari aplikasi kasir juga.
            if (! $member->is_active) {
                $member->user->tokens()->delete();
            }
        });

        $label = $editing ? 'Perubahan '.$editing->user->name.' disimpan' : ($existingUser ? 'Akun '.$existingUser->email.' ditambahkan ke tim' : 'Anggota tim ditambahkan');

        $this->dispatch('close-modal', name: 'member-form');
        $this->dispatch('toast', type: 'success', message: $label);
        $this->resetForm();
        unset($this->members);
    }

    public function toggleActive(int $id): void
    {
        $member = $this->findMember($id);
        $this->authorize('update', $member);

        $member->update(['is_active' => ! $member->is_active]);

        if (! $member->is_active) {
            $member->user->tokens()->delete();
        }

        $this->dispatch('toast', type: 'success', message: $member->user->name.($member->is_active ? ' diaktifkan kembali' : ' dinonaktifkan'));
        unset($this->members);
    }

    private function findMember(int $id): BusinessUser
    {
        // Selalu dibatasi pada bisnis aktif; anggota bisnis lain dianggap tidak ada.
        $member = $this->business()->memberships()->with('user')->find($id);
        abort_unless($member, 404);

        return $member;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'password', 'role', 'outletIds', 'isActive');
        $this->resetValidation();
    }
}; ?>

<x-slot name="actions">
    @can('create', App\Models\BusinessUser::class)
        <button type="button" x-data x-on:click="Livewire.dispatch('employee-create')" class="btn btn-primary !px-3 sm:!px-[18px]">
            <i class="fa-solid fa-user-plus"></i><span class="hidden sm:inline">Tambah Anggota</span>
        </button>
    @endcan
</x-slot>

<div class="flex flex-col gap-5">
    <div class="card overflow-hidden animate-fadeUp">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-user-group"></i>Anggota Tim</div>
            <div class="flex items-center gap-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-pb-muted text-xs"></i>
                    <input type="search" wire:model.live.debounce.300ms="search" class="f-input !py-1.5 !pl-8 !w-56 !text-[12.5px]" placeholder="Cari nama / email">
                </div>
                <span class="chip">{{ $this->members->count() }} anggota</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Role</th>
                        <th>Outlet</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->members as $member)
                        <tr wire:key="member-{{ $member->id }}" @class(['opacity-60' => ! $member->is_active])>
                            <td class="td-main">
                                <div class="flex items-center gap-2.5">
                                    <div class="a-grad w-8 h-8 rounded-lg grid place-items-center text-white text-xs font-bold shrink-0">
                                        {{ Str::upper(Str::substr($member->user->name, 0, 1)) }}
                                    </div>
                                    <div>
                                        {{ $member->user->name }}
                                        @if ($member->user_id === auth()->id())
                                            <span class="chip !text-[10px] !py-0.5 ml-1">Kamu</span>
                                        @endif
                                        <div class="text-[11px] text-pb-muted font-normal">{{ $member->user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge {{ $member->role === UserRole::Owner ? 'a-bg-lt a-text' : 'badge-blue' }}">{{ $member->role->label() }}</span></td>
                            <td class="!whitespace-normal">
                                @if ($member->role->coversAllOutlets())
                                    <span class="text-pb-muted">Semua outlet</span>
                                @elseif ($member->outlets->isEmpty())
                                    <span class="trend-down">Belum ditugaskan</span>
                                @else
                                    {{ $member->outlets->pluck('name')->join(', ') }}
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $member->is_active ? 'badge-green' : 'badge-gray' }}">{{ $member->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                            </td>
                            <td class="text-right">
                                @can('update', $member)
                                    <div class="inline-flex gap-1.5">
                                        <button type="button" wire:click="edit({{ $member->id }})" class="btn-ghost font-semibold">
                                            <i class="fa-solid fa-pen mr-1"></i>Ubah
                                        </button>
                                        <button type="button" wire:click="toggleActive({{ $member->id }})"
                                                wire:confirm="{{ $member->is_active ? 'Nonaktifkan '.$member->user->name.'? Ia tidak bisa masuk ke bisnis ini & aplikasi kasir.' : 'Aktifkan kembali '.$member->user->name.'?' }}"
                                                class="btn-ghost font-semibold" style="{{ $member->is_active ? 'color:#f87171' : 'color:#34d399' }}">
                                            {{ $member->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </button>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center !py-8">Tidak ada anggota yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Form tambah / ubah anggota ── --}}
    <x-modal name="member-form" maxWidth="lg" focusable>
        <form wire:submit="save">
            <div class="px-6 py-5 border-b border-pb-border flex items-center justify-between">
                <div>
                    <div class="font-display text-base font-bold text-pb-text">{{ $editingId ? 'Ubah Anggota' : 'Tambah Anggota' }}</div>
                    <div class="text-xs text-pb-muted mt-px">{{ $editingId ? $name.' · '.$email : 'Buat akun staf baru atau tautkan akun yang sudah ada' }}</div>
                </div>
                <button type="button" x-on:click="$dispatch('close')" class="icon-btn !w-8 !h-8" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="px-6 py-5 flex flex-col gap-4">
                @unless ($editingId)
                    <div>
                        <x-input-label for="email" value="Email" />
                        <x-text-input wire:model.blur="email" id="email" type="email" placeholder="nama@gmail.com" required />
                        <x-input-error :messages="$errors->get('email')" class="mt-1.5" />
                        <p class="f-hint">Jika email sudah punya akun Pabalu, akun tersebut langsung ditautkan ke bisnis ini.</p>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="name" value="Nama" />
                            <x-text-input wire:model="name" id="name" />
                            <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
                        </div>
                        <div>
                            <x-input-label for="password" value="Password awal" />
                            <x-text-input wire:model="password" id="password" type="password" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password')" class="mt-1.5" />
                        </div>
                    </div>
                @endunless

                <div>
                    <x-input-label for="role" value="Role" />
                    <select wire:model.live="role" id="role" class="f-input">
                        <option value="">— Pilih role —</option>
                        @foreach ($this->assignableRoles as $r)
                            <option value="{{ $r->value }}">{{ $r->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-1.5" />
                    @if ($r = UserRole::tryFrom($role))
                        <p class="f-hint">{{ count($r->permissions()) }} hak akses: {{ collect($r->permissions())->map->label()->take(4)->join(', ') }}{{ count($r->permissions()) > 4 ? ', …' : '' }}</p>
                    @endif
                </div>

                @if ($this->roleNeedsOutlets())
                    <div>
                        <x-input-label value="Outlet yang ditugaskan" />
                        <div class="grid sm:grid-cols-2 gap-2">
                            @foreach ($this->outlets as $outlet)
                                <x-toggle wire:model="outletIds" value="{{ $outlet->id }}" :label="$outlet->name"
                                          :description="$outlet->is_active ? $outlet->code : $outlet->code.' · nonaktif'" />
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('outletIds')" class="mt-1.5" />
                    </div>
                @elseif ($role !== '')
                    <p class="f-hint !mt-0"><i class="fa-solid fa-circle-info mr-1"></i>Role ini otomatis memiliki akses ke semua outlet.</p>
                @endif

                <x-toggle wire:model="isActive" label="Akun aktif" description="Nonaktif = tidak bisa masuk ke bisnis ini & aplikasi kasir" />
            </div>

            <div class="px-6 py-4 border-t border-pb-border flex justify-end gap-2.5">
                <x-secondary-button x-on:click="$dispatch('close')">Batal</x-secondary-button>
                <x-primary-button wire:loading.attr="disabled" wire:target="save">
                    <i class="fa-solid fa-check"></i>{{ $editingId ? 'Simpan Perubahan' : 'Tambahkan' }}
                </x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
