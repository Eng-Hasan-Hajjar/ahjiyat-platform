@php
    /** @var int $count @var int $sum */
    $count = (int) ($count ?? 0);
    $sum = (int) ($sum ?? 0);
    $average = app(\App\Services\Engagement\CommunityQualityService::class)->displayAverage($count, $sum);
@endphp
{{-- E29: ملخّص التقييم العلني. عيّنة صغيرة (< display_min) تُعرض كعدد فقط لا كمتوسط؛ لا «5.0» من تقييم واحد. المتوسط من العدّادين المجمَّعين بلا استعلام. --}}
@if ($count === 0)
    <span class="text-[11px] font-bold text-slate-500">لا تقييمات بعد</span>
@elseif ($average !== null)
    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-gold" aria-label="متوسط التقييم {{ number_format($average, 1) }} من 5 بحسب {{ $count }} تقييم">
        <x-engagement-icon name="star" :filled="true" class="w-3.5 h-3.5" />{{ number_format($average, 1) }}<span class="text-slate-400 font-bold">({{ number_format($count) }})</span>
    </span>
@else
    <span class="text-[11px] font-bold text-slate-400">{{ number_format($count) }} {{ $count === 1 ? 'تقييم' : 'تقييمات' }}</span>
@endif
