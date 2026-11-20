<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ ! empty($title) ? $title.' — ' : '' }}{{ config('app.name', 'Pabalu') }}</title>

<!-- Ikon Pabalu -->
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
<meta name="theme-color" content="#E30100">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Tema & warna aksen diterapkan sebelum render agar tidak berkedip -->
<script>
    window.PABALU_ACCENTS = [
        { id: 'amber',   label: 'Amber',  ac: '#f59e0b', ac2: '#ef4444', rgb: '245,158,11' },
        { id: 'emerald', label: 'Hijau',  ac: '#10b981', ac2: '#06b6d4', rgb: '16,185,129' },
        { id: 'blue',    label: 'Biru',   ac: '#4f6ef7', ac2: '#7c3aed', rgb: '79,110,247' },
        { id: 'violet',  label: 'Ungu',   ac: '#8b5cf6', ac2: '#ec4899', rgb: '139,92,246' },
        { id: 'rose',    label: 'Merah',  ac: '#f43f5e', ac2: '#f97316', rgb: '244,63,94' },
        { id: 'cyan',    label: 'Cyan',   ac: '#06b6d4', ac2: '#3b82f6', rgb: '6,182,212' },
        { id: 'lime',    label: 'Lime',   ac: '#84cc16', ac2: '#10b981', rgb: '132,204,22' },
        { id: 'pink',    label: 'Pink',   ac: '#ec4899', ac2: '#8b5cf6', rgb: '236,72,153' },
        { id: 'orange',  label: 'Oranye', ac: '#f97316', ac2: '#f59e0b', rgb: '249,115,22' },
        { id: 'sky',     label: 'Sky',    ac: '#38bdf8', ac2: '#6366f1', rgb: '56,189,248' },
    ];
    (function () {
        var theme = 'dark', accent = 'amber';
        try {
            theme = localStorage.getItem('pabalu-theme') || theme;
            accent = localStorage.getItem('pabalu-accent') || accent;
        } catch (e) {}
        var root = document.documentElement;
        if (theme === 'light') root.classList.add('light');
        var c = window.PABALU_ACCENTS.find(function (x) { return x.id === accent; });
        if (c) {
            root.style.setProperty('--ac', c.ac);
            root.style.setProperty('--ac2', c.ac2);
            root.style.setProperty('--ac-rgb', c.rgb);
            root.style.setProperty('--ac-lt', 'rgba(' + c.rgb + ',.14)');
            root.style.setProperty('--ac-lt2', 'rgba(' + c.rgb + ',.08)');
        }
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
