{{--
    بطاقة "المهمة الحالية" - وصف آمن عام فقط. CTA يتغيّر حسب Kind
    (narrative/puzzle/reflection).
--}}
@props(['campaign', 'step'])

@php
    $kindMeta = [
        \App\Models\CampaignStep::KIND_NARRATIVE => ['label' => 'سردية', 'cta' => 'تابع القصة'],
        \App\Models\CampaignStep::KIND_PUZZLE => ['label' => 'أحجية', 'cta' => 'ابدأ التحدي'],
        \App\Models\CampaignStep::KIND_REFLECTION => ['label' => 'تأمّل', 'cta' => 'اكتب إجابتك'],
    ][$step->kind] ?? ['label' => $step->kind, 'cta' => 'متابعة'];
@endphp

<div class="puzzle-card !p-6 anim-fade-up flex items-center justify-between gap-4 flex-wrap">
    <div>
        <span class="text-xs font-black text-amethyst uppercase tracking-widest">المهمة الحالية</span>
        <h3 class="font-display font-black text-xl text-white mt-1">{{ $step->title }}</h3>
        <span class="chip !py-0.5 !px-2 mt-2 inline-block text-[10px]">{{ $kindMeta['label'] }}</span>
    </div>

    <a href="{{ route('campaigns.steps.show', [$campaign, $step]) }}" class="btn-gem !py-2.5 !px-5 shrink-0">
        {{ $kindMeta['cta'] }} ←
    </a>
</div>