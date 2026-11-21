<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head', ['title' => $title ?? null])
    </head>
    <body class="antialiased">
        <div id="toast-container"></div>
        <x-google-connected-popup />

        <div class="min-h-screen grid lg:grid-cols-[1fr_minmax(0,560px)]">
            <!-- Panel brand -->
            {{-- Panel merek: selalu merah Pabalu (tidak ikut warna aksen) + animasi transaksi kasir yang berulang. --}}
            <aside class="hidden lg:flex relative overflow-hidden flex-col justify-between p-12 pb-brand-panel"
                   x-data="pabaluLoginShowcase">
                <div class="pb-blob absolute -top-24 -right-24 w-80 h-80 rounded-full bg-white/10"></div>
                <div class="pb-blob pb-blob-slow absolute -bottom-32 -left-20 w-96 h-96 rounded-full bg-black/10"></div>

                {{-- Logo lengkap dengan motto (latar putih) ditaruh di kartu putih. --}}
                <div class="relative self-start rounded-2xl bg-white px-5 py-3 shadow-lg shadow-black/10">
                    <img src="{{ asset('img/logo-header.png') }}" alt="Pabalu — Smart Solution For UMKM" class="h-14 w-auto">
                </div>

                <div class="relative mx-auto mb-16 w-full max-w-[400px]">
                    {{-- Kartu pesanan: item muncul satu per satu, total naik, lalu cap LUNAS. --}}
                    <div class="pb-float relative rounded-3xl bg-white p-5 text-slate-800 shadow-2xl shadow-black/25">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <span class="grid h-9 w-9 place-items-center rounded-xl bg-[#E30100] text-white"><i class="fa-solid fa-cash-register text-sm"></i></span>
                                <div>
                                    <div class="text-[13px] font-bold leading-tight">Pesanan #A-1024</div>
                                    <div class="text-[11px] text-slate-400">Meja 4 · Dine-in</div>
                                </div>
                            </div>
                            <span class="rounded-full bg-red-50 px-2.5 py-1 text-[10.5px] font-semibold text-[#E30100]">Shift Pagi</span>
                        </div>

                        <div class="mt-4 space-y-2.5 border-t border-dashed border-slate-200 pt-3.5">
                            <template x-for="(item, i) in items" :key="item.name">
                                <div class="pb-line flex items-center justify-between text-[12.5px]" :class="step > i && 'is-on'">
                                    <span class="flex items-center gap-2">
                                        <span class="grid h-6 w-6 place-items-center rounded-lg bg-red-50 text-[#E30100]"><i class="fa-solid text-[10px]" :class="item.icon"></i></span>
                                        <span class="font-medium" x-text="item.qty + '× ' + item.name"></span>
                                    </span>
                                    <span class="font-semibold tabular-nums" x-text="rupiah(item.qty * item.price)"></span>
                                </div>
                            </template>
                        </div>

                        <div class="mt-3.5 flex items-end justify-between border-t border-slate-100 pt-3">
                            <span class="text-[11.5px] text-slate-400">Total</span>
                            <span class="font-display text-2xl font-bold tabular-nums text-[#E30100]" x-text="rupiah(total)"></span>
                        </div>

                        <div class="pb-stamp absolute left-[38%] top-[42%] rounded-lg border-[3px] border-emerald-500 px-3 py-0.5 font-display text-xl font-bold tracking-widest text-emerald-500"
                             :class="paid && 'is-on'">LUNAS</div>
                    </div>

                    {{-- Lencana yang melayang di sekitar kartu. --}}
                    <div class="pb-chip pb-float-alt absolute -bottom-5 -left-8" :class="paid && 'is-on'">
                        <i class="fa-solid fa-receipt text-[#E30100]"></i> Struk tercetak
                    </div>
                    <div class="pb-chip pb-float absolute -right-8 -top-5 [animation-delay:-2s]" :class="step >= 2 && 'is-on'">
                        <i class="fa-solid fa-boxes-stacked text-emerald-500"></i> Stok terpotong otomatis
                    </div>
                    <div class="pb-float-alt absolute -bottom-24 -right-10 w-44 rounded-2xl bg-white p-3 text-slate-800 shadow-xl shadow-black/20 [animation-delay:-1s]">
                        <div class="flex items-center justify-between text-[10.5px] text-slate-400">
                            <span>Penjualan hari ini</span><i class="fa-solid fa-arrow-trend-up text-emerald-500"></i>
                        </div>
                        <div class="mt-0.5 text-[15px] font-bold tabular-nums" x-text="rupiah(1250000 + (paid ? total : 0))"></div>
                        <div class="mt-2 flex h-9 items-end gap-1">
                            <template x-for="(h, i) in bars" :key="i">
                                <span class="pb-bar flex-1 rounded-t bg-[#E30100]" :style="`height:${h}%; opacity:${i === bars.length - 1 ? 1 : 0.35}`"></span>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="relative text-white">
                    <h2 class="font-display text-[34px] font-bold leading-tight">
                        Jualan lebih
                        <span class="relative inline-block min-w-[9ch]">
                            <template x-for="(word, i) in words" :key="word">
                                <span class="pb-word absolute left-0 top-0 whitespace-nowrap" :class="i === wordIndex && 'is-on'" x-text="word"></span>
                            </template>
                            <span class="invisible">terkendali.</span>
                        </span>
                    </h2>
                    <p class="mt-2 text-[14px] text-white/80">Smart Solution For UMKM — kasir, stok, dan laporan dalam satu aplikasi.</p>
                    <div class="mt-6 text-[11px] text-white/70">&copy; {{ date('Y') }} Pabalu</div>
                </div>
            </aside>


            <!-- Form -->
            <main class="flex flex-col justify-center px-4 py-10 sm:px-10">
                <div class="w-full max-w-[420px] mx-auto">
                    <a href="/" wire:navigate class="lg:hidden inline-flex mb-8 rounded-2xl bg-white px-4 py-2.5 shadow-sm">
                        <img src="{{ asset('img/logo-header.png') }}" alt="Pabalu — Smart Solution For UMKM" class="h-11 w-auto">
                    </a>

                    <div class="card p-6 sm:p-8 animate-fadeUp">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>
    </body>
</html>
