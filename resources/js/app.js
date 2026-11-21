import Chart from 'chart.js/auto';

/* ════════ ACCENT PALETTE ════════ */
// Didefinisikan sekali di resources/views/layouts/partials/head.blade.php
// (dipakai juga oleh skrip anti-flash sebelum CSS/JS dimuat).
const accentPalette = window.PABALU_ACCENTS ?? [
    { id: 'pabalu', label: 'Pabalu', ac: '#E30100', ac2: '#FF5A36', rgb: '227,1,0' },
];

const storage = {
    get(key, fallback) {
        try { return localStorage.getItem(key) ?? fallback; } catch { return fallback; }
    },
    set(key, value) {
        try { localStorage.setItem(key, value); } catch { /* storage tidak tersedia */ }
    },
};

function applyAccent(id) {
    const c = accentPalette.find((x) => x.id === id) ?? accentPalette[0];
    const root = document.documentElement.style;
    root.setProperty('--ac', c.ac);
    root.setProperty('--ac2', c.ac2);
    root.setProperty('--ac-rgb', c.rgb);
    root.setProperty('--ac-lt', `rgba(${c.rgb},.14)`);
    root.setProperty('--ac-lt2', `rgba(${c.rgb},.08)`);
    return c;
}

function applyTheme(theme) {
    document.documentElement.classList.toggle('light', theme === 'light');
}

/**
 * Terapkan ulang tema & aksen yang tersimpan.
 *
 * Diperlukan karena wire:navigate menyalin atribut <html> dari halaman baru
 * (yang dirender server tanpa class "light" / style aksen), sehingga pilihan
 * pengguna akan hilang setiap pindah halaman bila tidak diterapkan ulang.
 */
function applySavedPreferences() {
    applyTheme(storage.get('pabalu-theme', 'dark'));
    applyAccent(storage.get('pabalu-accent', 'pabalu'));
}

document.addEventListener('livewire:navigating', (e) => {
    // Dijalankan tepat setelah halaman ditukar, sebelum dirender → tidak berkedip.
    e.detail?.onSwap?.(applySavedPreferences);
});
document.addEventListener('livewire:navigated', applySavedPreferences);

/* ════════ TOAST ════════ */
function toast(type, msg) {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    const icon = document.createElement('i');
    icon.className = `fa-solid ${icons[type] || icons.info} ti`;
    icon.style.cssText = 'font-size:16px;flex-shrink:0';
    const text = document.createElement('span');
    text.textContent = msg;
    el.append(icon, text);
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; }, 2700);
    setTimeout(() => el.remove(), 3000);
}

window.Pabalu = { accentPalette, toast };
window.addEventListener('toast', (e) => toast(e.detail?.type ?? 'info', e.detail?.message ?? ''));

/* ════════ ALPINE ════════ */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Animasi panel merek di halaman login/daftar: item pesanan muncul satu per satu,
     * total dihitung naik, cap LUNAS, lalu berulang. Menghormati prefers-reduced-motion.
     */
    Alpine.data('pabaluLoginShowcase', () => ({
        items: [
            { name: 'Kopi Susu Aren', qty: 2, price: 18000, icon: 'fa-mug-hot' },
            { name: 'Nasi Goreng Spesial', qty: 1, price: 28000, icon: 'fa-bowl-food' },
            { name: 'Air Mineral', qty: 1, price: 5000, icon: 'fa-bottle-water' },
        ],
        words: ['cepat.', 'rapi.', 'terkendali.', 'untung.'],
        bars: [35, 55, 42, 68, 50, 78, 92],
        step: 0,
        paid: false,
        hold: 0,
        wordIndex: 0,
        total: 0,
        timers: [],

        get target() {
            return this.items.slice(0, this.step).reduce((sum, item) => sum + item.qty * item.price, 0);
        },

        rupiah(value) {
            return 'Rp ' + Math.round(value).toLocaleString('id-ID');
        },

        init() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                this.step = this.items.length;
                this.total = this.target;
                this.paid = true;
                return;
            }
            this.timers.push(setInterval(() => this.tick(), 1100));
            this.timers.push(setInterval(() => { this.wordIndex = (this.wordIndex + 1) % this.words.length; }, 2400));
            this.timers.push(setInterval(() => {
                // Total "berjalan" mendekati target agar terlihat seperti sedang dihitung.
                const diff = this.target - this.total;
                this.total = Math.abs(diff) < 500 ? this.target : this.total + diff * 0.25;
            }, 40));
        },

        tick() {
            if (this.step < this.items.length) {
                this.step++;
            } else if (! this.paid) {
                this.paid = true;
                this.hold = 0;
            } else if (++this.hold >= 3) {
                this.paid = false;
                this.step = 0;
            }
        },

        destroy() {
            this.timers.forEach(clearInterval);
        },
    }));

    Alpine.store('ui', {
        sidebarOpen: false,
        theme: storage.get('pabalu-theme', 'dark'),
        accent: storage.get('pabalu-accent', 'pabalu'),

        toggleSidebar() { this.sidebarOpen = !this.sidebarOpen; },
        closeSidebar() { this.sidebarOpen = false; },

        setTheme(theme) {
            this.theme = theme;
            applyTheme(theme);
            storage.set('pabalu-theme', theme);
            window.dispatchEvent(new CustomEvent('pabalu:theme-changed'));
        },

        setAccent(id) {
            const c = applyAccent(id);
            this.accent = c.id;
            storage.set('pabalu-accent', c.id);
            window.dispatchEvent(new CustomEvent('pabalu:theme-changed'));
            toast('success', `Warna diubah ke ${c.label}`);
        },
    });

    /**
     * Grafik penjualan (line chart) yang mengikuti tema & warna aksen.
     * @param {Record<string, {labels: string[], data: number[]}>} datasets
     */
    Alpine.data('salesChart', (datasets, initial = Object.keys(datasets)[0]) => {
        // Instance Chart sengaja TIDAK disimpan di state reaktif Alpine:
        // proxy Alpine merusak internal Chart.js.
        let chart = null;
        let onTheme = null;

        return {
            period: initial,

            init() {
                this.build();
                onTheme = () => this.build();
                window.addEventListener('pabalu:theme-changed', onTheme);
            },

            destroy() {
                window.removeEventListener('pabalu:theme-changed', onTheme);
                chart?.destroy();
                chart = null;
            },

            setPeriod(p) {
                this.period = p;
                this.build();
            },

            build() {
                const d = datasets[this.period];
                const css = getComputedStyle(document.documentElement);
                const v = (name) => css.getPropertyValue(name).trim();
                const isDark = !document.documentElement.classList.contains('light');
                const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
                const tickColor = isDark ? '#64748b' : '#94a3b8';
                const ac = v('--ac');
                // Sumbu ringkas mengikuti skala: rb / Jt / M. Tooltip menampilkan nominal penuh.
                const rupiah = (n) => {
                    const abs = Math.abs(n);
                    const [div, unit] = abs >= 1e9 ? [1e9, ' M'] : abs >= 1e6 ? [1e6, ' Jt'] : abs >= 1e3 ? [1e3, ' rb'] : [1, ''];
                    return 'Rp ' + (n / div).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + unit;
                };
                const fullRupiah = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

                chart?.destroy();
                chart = new Chart(this.$refs.canvas, {
                    type: 'line',
                    data: {
                        labels: d.labels,
                        datasets: [{
                            label: 'Penjualan',
                            data: d.data,
                            borderColor: ac,
                            backgroundColor: `rgba(${v('--ac-rgb')},.12)`,
                            pointBackgroundColor: ac,
                            pointBorderColor: v('--surface'),
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            fill: true,
                            borderWidth: 2.5,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: v('--surface2'),
                                borderColor: v('--border'),
                                borderWidth: 1,
                                titleColor: tickColor,
                                bodyColor: v('--text'),
                                padding: 12,
                                cornerRadius: 12,
                                displayColors: false,
                                callbacks: { label: (ctx) => fullRupiah(ctx.raw) },
                            },
                        },
                        scales: {
                            x: { grid: { color: gridColor }, ticks: { color: tickColor, font: { size: 11, family: 'Plus Jakarta Sans' } } },
                            y: {
                                grid: { color: gridColor },
                                ticks: { color: tickColor, font: { size: 11, family: 'Plus Jakarta Sans' }, callback: (n) => rupiah(n), maxTicksLimit: 6 },
                            },
                        },
                    },
                });
            },
        };
    });
});
