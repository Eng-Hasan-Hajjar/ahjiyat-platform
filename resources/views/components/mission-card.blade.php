{{--
    بطاقة "المهمة الحالية" - وصف آمن عام فقط. CTA يتغيّر حسب Kind
    (narrative/puzzle/reflection). Reward المعروضة (إن وُجدت) تأتي من
    نفس بيانات reward_mode المخزَّنة - لا حساب مستقل هون.
--}}
@props(['campaign', 'step', 'missionNumber' => null])

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
        <h3 class="font-display font-black text-xl text-white mt-1">
            @if ($missionNumber)
                <span class="text-slate-500">#{{ $missionNumber }}</span>
            @endif
            {{ $step->title }}
        </h3>

        <div class="flex items-center gap-2 mt-2 flex-wrap">
            <span class="chip !py-0.5 !px-2 text-[10px]">{{ $kindMeta['label'] }}</span>

            @if ($step->kind === \App\Models\CampaignStep::KIND_PUZZLE)
                @if ($step->reward_mode === \App\Models\CampaignStep::REWARD_MODE_NONE)
                    <span class="text-[11px] font-black text-slate-500">بلا مكافأة</span>
                @elseif ($step->reward_mode === \App\Models\CampaignStep::REWARD_MODE_OVERRIDE)
                    <span class="text-[11px] font-black text-gold">+{{ $step->reward_override_amount }} 💎</span>
                @elseif ($step->puzzle)
                    <span class="text-[11px] font-black text-gold">+{{ $step->puzzle->gem_reward ?? 0 }} 💎</span>
                @endif
            @endif
        </div>
    </div>

    <a href="{{ route('campaigns.steps.show', [$campaign, $step]) }}" class="btn-gem !py-2.5 !px-5 shrink-0">
        {{ $kindMeta['cta'] }} ←
    </a>
</div>