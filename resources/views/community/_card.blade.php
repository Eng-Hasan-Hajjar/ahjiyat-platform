@php
    /** @var \App\Models\CreatorPuzzle $work */
    $showCreator = $showCreator ?? false;
    $creatorName = $showCreator && $work->relationLoaded('creator') && $work->creator?->relationLoaded('user') ? $work->creator->user?->name : null;
    // E29: التقييم يُعرض فقط حين تُحمَّل عدّادات العمل بالاستعلام (لا استعلام إضافي ولا «لا تقييمات» كاذبة في أسطح لم تحمّلها). $savedIds: مفضلة المشاهد (دفعة واحدة، اختياري).
    $hasRatings = array_key_exists('ratings_count', $work->getAttributes());
    $isSaved = (bool) (($savedIds ?? [])[$work->id] ?? false);
@endphp
{{-- E28: بطاقة أحجية مجتمع. وسم «من المجتمع» ثابت دائمًا (يميّزها عن الرسمي)، وتصريح أنها بلا جوائز. البطاقة كلها رابط واحد. نصوص مهرَّبة ولا قراءة علاقات غير محمَّلة (لا N+1). --}}
<a href="{{ route('community.puzzles.show', $work->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="puzzle" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true" icon="friends">من المجتمع</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $work->published_title }}</h3>
    <p class="text-xs text-slate-400 mt-1">
        @if ($work->publishedCategory) {{ $work->publishedCategory->name }} @endif
        @if ($creatorName) · {{ $creatorName }} @endif
    </p>
    <div class="mt-auto pt-3 flex flex-wrap items-center gap-2">
        @if ($work->published_difficulty) <x-difficulty-badge :difficulty="$work->published_difficulty" :small="true" /> @endif
        <span class="text-[11px] font-bold text-slate-400">{{ number_format($work->plays_count) }} لاعب</span>
        @if ($hasRatings) @include('engagement._rating_summary', ['count' => (int) $work->ratings_count, 'sum' => (int) $work->ratings_sum]) @endif
        @if ($isSaved)
            <span class="inline-flex items-center gap-1 text-[11px] font-black text-gold"><x-engagement-icon name="bookmark" :filled="true" class="w-3.5 h-3.5" />محفوظة</span>
        @endif
    </div>
</a>
