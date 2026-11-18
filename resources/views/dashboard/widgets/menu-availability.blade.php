@php
    $menus = [
        ['nama' => 'Kopi Susu Gula Aren', 'kategori' => 'Minuman', 'harga' => 'Rp 22.000', 'varian' => 2, 'status' => 'Tersedia'],
        ['nama' => 'Nasi Goreng Spesial', 'kategori' => 'Makanan', 'harga' => 'Rp 28.000', 'varian' => 1, 'status' => 'Tersedia'],
        ['nama' => 'Croissant Cokelat', 'kategori' => 'Camilan', 'harga' => 'Rp 18.000', 'varian' => 0, 'status' => 'Habis'],
        ['nama' => 'Matcha Latte', 'kategori' => 'Minuman', 'harga' => 'Rp 26.000', 'varian' => 2, 'status' => 'Habis'],
        ['nama' => 'Mie Ayam Bakso', 'kategori' => 'Makanan', 'harga' => 'Rp 25.000', 'varian' => 1, 'status' => 'Tersedia'],
        ['nama' => 'Es Teh Lemon', 'kategori' => 'Minuman', 'harga' => 'Rp 12.000', 'varian' => 2, 'status' => 'Habis'],
    ];
@endphp

<div class="card overflow-hidden">
    <div class="card-header">
        <div class="card-title"><i class="fa-solid fa-utensils"></i>Ketersediaan Menu</div>
        <button type="button" class="btn-ghost font-semibold" x-data
                @click="Pabalu.toast('info', 'Modul Daftar Menu segera hadir')">
            Kelola Menu <i class="fa-solid fa-arrow-right text-[10px]"></i>
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="tbl">
            <thead>
                <tr><th>Menu</th><th>Kategori</th><th>Harga</th><th>Varian</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($menus as $m)
                    <tr>
                        <td class="td-main">{{ $m['nama'] }}</td>
                        <td>{{ $m['kategori'] }}</td>
                        <td class="font-semibold" style="color:var(--text)">{{ $m['harga'] }}</td>
                        <td>{{ $m['varian'] ?: '—' }}</td>
                        <td><span class="badge {{ $m['status'] === 'Tersedia' ? 'badge-green' : 'badge-red' }}">{{ $m['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
