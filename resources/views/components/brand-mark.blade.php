@props(['size' => 38])

{{-- Ikon logo Pabalu (latar transparan, cocok untuk tema gelap & terang). --}}
<img src="{{ asset('img/logo-mark.png') }}" alt="Pabalu" width="{{ $size }}" height="{{ $size }}"
     {{ $attributes->merge(['class' => 'shrink-0 object-contain']) }}
     style="width:{{ $size }}px;height:{{ $size }}px">
