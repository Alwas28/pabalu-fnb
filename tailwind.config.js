import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Outfit"', '"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
            },
            // Token tema Pabalu — nilainya diatur lewat CSS variable di resources/css/app.css
            // sehingga otomatis mengikuti mode gelap/terang dan warna aksen pilihan pengguna.
            colors: {
                pb: {
                    bg: 'var(--bg)',
                    surface: 'var(--surface)',
                    surface2: 'var(--surface2)',
                    border: 'var(--border)',
                    text: 'var(--text)',
                    muted: 'var(--muted)',
                    sub: 'var(--sub)',
                    ac: 'var(--ac)',
                    ac2: 'var(--ac2)',
                    'ac-lt': 'var(--ac-lt)',
                },
            },
        },
    },

    plugins: [forms],
};
