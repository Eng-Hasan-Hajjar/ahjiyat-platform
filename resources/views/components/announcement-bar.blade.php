{{--
    شريط تنبيه عام - نص عادي فقط (لا HTML حر أبدًا)، لذلك {{ }} كافية
    ولا حاجة لأي Sanitizer إضافي. يظهر فقط إذا enabled=true ويوجد نص.
--}}
@props(['announcement'])

@php
    $styles = [
        'info' => 'bg-amethyst/15 border-amethyst/30 text-white',
        'success' => 'bg-emerald/15 border-emerald/30 text-emerald',
        'warning' => 'bg-gold/15 border-gold/30 text-gold',
        'urgent' => 'bg-rose/15 border-rose/30 text-rose',
    ];
    $style = $styles[$announcement['type']] ?? $styles['info'];
@endphp

<div class="border-b {{ $style }} text-sm font-bold">
    <div class="max-w-6xl mx-auto px-4 py-2.5 flex items-center justify-center gap-3 text-center flex-wrap">
        <span>{{ $announcement['text'] }}</span>

        @if ($announcement['url'])
            <a href="{{ $announcement['url'] }}" class="underline underline-offset-2 shrink-0">
                {{ $announcement['cta_label'] ?: 'التفاصيل' }} ←
            </a>
        @endif
    </div>
</div>