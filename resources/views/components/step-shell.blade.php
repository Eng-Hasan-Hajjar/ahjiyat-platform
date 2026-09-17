{{--
    الغلاف المشترك لكل صفحات الخطوة (narrative/puzzle/reflection) - رابط
    العودة، بطاقة العرض، ومرجع Stage/Gate/رقم المهمة. المحتوى الخاص بكل
    نوع (عنوان/نص/نموذج) يبقى بالـSlot - لا نعيد بناء منطق كل نوع هون.
--}}
@props(['campaign', 'step', 'missionNumber' => null])

<div class="max-w-3xl mx-auto">
    <a href="{{ route('campaigns.show', $campaign) }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
        → {{ $campaign->title }}
    </a>

    <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
        <div class="step-shell-meta">
            <span>{{ $step->gate->stage->title }}</span>
            <span class="step-shell-meta-sep">/</span>
            <span>{{ $step->gate->title }}</span>

            @if ($missionNumber)
                <span class="chip !py-0.5 !px-2 text-[10px] step-shell-mission-chip">مهمة #{{ $missionNumber }}</span>
            @endif
        </div>

        {{ $slot }}
    </div>
</div>