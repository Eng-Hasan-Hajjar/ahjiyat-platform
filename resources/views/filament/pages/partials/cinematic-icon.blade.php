{{--
    CIN-1 UX - أيقونات صغيرة موحّدة (خطية، تأخذ لون النص الحالي). الاستعمال: @include('filament.pages.partials.cinematic-icon', ['name' => 'video']).
    زخرفية فقط (aria-hidden): كل معنى مهم يُكتب نصًّا بجانبها.
--}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('video')<path d="M8 5v14l11-7z"/>@break
        @case('narrative')<path d="M5 6h14M5 10h14M5 14h9M5 18h6"/>@break
        @case('puzzle')<path d="M4 4h6v2a2 2 0 1 0 4 0V4h6v6h-2a2 2 0 1 0 0 4h2v6H4z"/>@break
        @case('interaction')<path d="M5 3l14 7-6 2-2 6z"/>@break
        @case('exploration')<circle cx="10" cy="10" r="6"/><path d="M15 15l5 5"/>@break
        @case('transition')<path d="M4 12h16M14 6l6 6-6 6"/>@break
        @case('chevron')<path d="M6 9l6 6 6-6"/>@break
        @case('check')<path d="M5 13l4 4L19 7"/>@break
        @case('alert')<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>@break
        @case('image')<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/>@break
        @case('upload')<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>@break
        @case('eye')<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>@break
        @default<circle cx="12" cy="12" r="3"/>
    @endswitch
</svg>
