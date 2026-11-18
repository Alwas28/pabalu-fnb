@props(['size' => 38])

<div {{ $attributes->merge(['class' => 'a-grad grid place-items-center shrink-0']) }}
     style="width:{{ $size }}px;height:{{ $size }}px;border-radius:{{ round($size * 0.29) }}px">
    <i class="fa-solid fa-store text-white" style="font-size:{{ round($size * 0.42) }}px"></i>
</div>
