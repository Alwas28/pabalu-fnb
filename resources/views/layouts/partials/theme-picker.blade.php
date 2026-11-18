<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="icon-btn" @click="open = ! open" aria-label="Tema & warna">
        <i class="fa-solid fa-circle-half-stroke"></i>
    </button>

    <div class="tp-panel" x-cloak x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1.5"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <button type="button" class="tp-btn" :class="{ active: $store.ui.theme === 'dark' }"
                @click="$store.ui.setTheme('dark'); open = false">
            <i class="fa-solid fa-moon w-3.5 text-center"></i>Mode Gelap
        </button>
        <button type="button" class="tp-btn" :class="{ active: $store.ui.theme === 'light' }"
                @click="$store.ui.setTheme('light'); open = false">
            <i class="fa-solid fa-sun w-3.5 text-center"></i>Mode Terang
        </button>

        <div class="tp-divider"></div>
        <div class="tp-section-label">Warna Aksen</div>
        <div class="color-swatches">
            <template x-for="c in Pabalu.accentPalette" :key="c.id">
                <button type="button" class="swatch" :class="{ active: $store.ui.accent === c.id }"
                        :style="`background:linear-gradient(135deg,${c.ac},${c.ac2})`"
                        :title="c.label" :aria-label="c.label"
                        @click="$store.ui.setAccent(c.id)">
                    <i class="fa-solid fa-check" x-show="$store.ui.accent === c.id"></i>
                </button>
            </template>
        </div>
    </div>
</div>
