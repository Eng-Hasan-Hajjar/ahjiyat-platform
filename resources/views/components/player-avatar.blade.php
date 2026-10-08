@props(['avatar' => null, 'frame' => null, 'name' => '', 'size' => 'md'])

@php
    $dimensions = match ($size) {
        'sm' => 'w-8 h-8 text-xs',
        'lg' => 'w-16 h-16 text-2xl',
        'xl' => 'w-24 h-24 md:w-28 md:h-28 text-3xl',
        default => 'w-10 h-10 text-sm',
    };
@endphp

<span {{ $attributes->merge(['class' => "relative inline-grid place-items-center shrink-0 {$dimensions}"]) }}>
    @if ($avatar && $avatar->image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($avatar->image_path) }}"
             alt="{{ $name }}"
             class="w-full h-full rounded-full object-cover">
    @else
        <span class="gem-facet w-full h-full grid place-items-center font-black text-white bg-gradient-to-br from-amethyst to-gold">
            {{ mb_substr($name, 0, 1) }}
        </span>
    @endif

    @if ($frame && $frame->image_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::url($frame->image_path) }}"
             alt=""
             class="absolute inset-0 w-full h-full pointer-events-none" style="aspect-ratio: 1 / 1;">
    @endif
</span>