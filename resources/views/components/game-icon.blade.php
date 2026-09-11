@props(['name'])

@php
    $paths = [
        'gem' => '<path d="M12 2 4 9l8 13 8-13-8-7Z" fill="currentColor" /><path d="M4 9h16M9 9l3-5 3 5M9 9l3 13 3-13" stroke="currentColor" stroke-opacity="0.35" stroke-width="1" fill="none" />',
        'trophy' => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Z" fill="currentColor" /><path d="M8 5H4v2a4 4 0 0 0 4 4M16 5h4v2a4 4 0 0 1-4 4" /><path d="M9 16h6M12 12v4M8 20h8" />',
        'timer' => '<circle cx="12" cy="13" r="8" /><path d="M12 9v4l3 2M10 2h4" />',
        'heart' => '<path d="M12 20s-7-4.35-9.5-8.5C.7 8.2 2.2 4.5 6 4.5c2 0 3.5 1.2 4 2.3.5-1.1 2-2.3 4-2.3 3.8 0 5.3 3.7 3.5 7C19 15.65 12 20 12 20Z" fill="currentColor" stroke="none" />',
        'hint' => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.6.45.9 1.1.9 1.8v.4h5.4v-.4c0-.7.3-1.35.9-1.8A6 6 0 0 0 12 3Z" />',
        'restart' => '<path d="M3 12a9 9 0 1 1 3 6.7M3 12v5h5" />',
        'pause' => '<rect x="7" y="5" width="4" height="14" rx="1" fill="currentColor" stroke="none" /><rect x="13" y="5" width="4" height="14" rx="1" fill="currentColor" stroke="none" />',
        'play' => '<path d="M7 4.5v15l13-7.5-13-7.5Z" fill="currentColor" stroke="none" />',
        'sound' => '<path d="M4 9v6h4l6 4V5L8 9H4Z" /><path d="M17 8.5a5 5 0 0 1 0 7" />',
        'mute' => '<path d="M4 9v6h4l6 4V5L8 9H4Z" /><path d="m17 9 4 6M21 9l-4 6" />',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" />',
        'star' => '<path d="m12 3 2.8 5.9 6.2.6-4.6 4.4 1.2 6.2L12 17l-5.6 3.1 1.2-6.2L3 9.5l6.2-.6L12 3Z" fill="currentColor" stroke="none" />',
        'fire' => '<path d="M12 22a6 6 0 0 0 6-6c0-3-2-4.5-2-4.5s0 2-1.5 2.5C15 11 14 8 12 6c0 2-1.5 3.5-1.5 5.5S8 12.5 8 14a6 6 0 0 0 4 6Z" fill="currentColor" stroke="none" />',
        'bolt' => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z" fill="currentColor" stroke="none" />',
        'brain' => '<path d="M9 3a3 3 0 0 0-3 3v.3A3.5 3.5 0 0 0 4 9.5a3.5 3.5 0 0 0 1 6.6A3 3 0 0 0 8 20h1V3H9Z" /><path d="M15 3a3 3 0 0 1 3 3v.3a3.5 3.5 0 0 1 2 3.2 3.5 3.5 0 0 1-1 6.6 3 3 0 0 1-3 4h-1V3h0Z" />',
        'puzzle' => '<path d="M9 4h4v2a2 2 0 1 0 0 4v2h4a2 2 0 1 1 0 4h-4v2H9v-4a2 2 0 1 0 0-4V4Z" />',
        'target' => '<circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />',
        'check' => '<path d="m4 12 6 6L20 6" />',
        'wrong' => '<path d="m5 5 14 14M19 5 5 19" />',
    ];

    $inner = $paths[$name] ?? $paths['puzzle'];
@endphp

<svg
    {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
>{!! $inner !!}</svg>