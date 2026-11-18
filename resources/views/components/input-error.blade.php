@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'f-error space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li><i class="fa-solid fa-circle-exclamation text-[10px] mr-1"></i>{{ $message }}</li>
        @endforeach
    </ul>
@endif
