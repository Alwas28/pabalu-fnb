<x-app-layout title="Akun" subtitle="Pengaturan profil & keamanan">
    <div class="flex flex-col gap-5 max-w-3xl">
        <div class="card card-body animate-fadeUp">
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="card card-body animate-fadeUp d1">
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="card card-body animate-fadeUp d2">
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-app-layout>
