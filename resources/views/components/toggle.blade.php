@props(['label' => null, 'description' => null, 'icon' => null])

{{-- Saklar on/off. Atribut lain (wire:model, value, name, disabled) diteruskan ke <input type="checkbox">. --}}
<label class="toggle-row">
    @if ($icon)
        <span class="toggle-icon"><i class="fa-solid {{ $icon }}"></i></span>
    @endif
    @if ($label)
        <span class="flex-1 min-w-0">
            <span class="block text-[13px] font-semibold text-pb-text">{{ $label }}</span>
            @if ($description)
                <span class="block text-[11.5px] text-pb-muted mt-0.5">{{ $description }}</span>
            @endif
        </span>
    @endif
    <input type="checkbox" {{ $attributes->merge(['class' => 'switch']) }}>
</label>
