@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'badge badge-green w-full !rounded-xl !px-3.5 !py-2.5 !text-[12.5px]']) }}>
        <i class="fa-solid fa-circle-check"></i>{{ $status }}
    </div>
@endif
