@php
    /** @var \App\Models\CreatorSeries $series */
    $creatorName = $series->relationLoaded('creator') && $series->creator?->relationLoaded('user') ? $series->creator->user?->name : null;
@endphp
{{-- E30: بطاقة سلسلة مجتمع. وسم «من المجتمع» ثابت (يميّزها عن الرسمي). البطاقة كلها رابط واحد. تقرأ لقطة النشر من جدول السلسلة وحده (بلا ضمّ ولا N+1). نصوص مهرَّبة. --}}
<a href="{{ route('series.show', $series->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none" data-series-card>
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="grid" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true" icon="friends">سلسلة من المجتمع</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $series->published_title }}</h3>
    <p class="text-xs text-slate-400 mt-1">
        {{ (int) $series->published_items_count }} أحجية
        @if ($creatorName) · {{ $creatorName }} @endif
    </p>
</a>
