{{--
    Hero الموسم الرسمي - كل النصوص/الحالة قادمة Server-side (لا حساب
    Countdown/Progress بالـJS). صورة الغلاف والشعار يمرّان عبر فحص وجود
    فعلي (لا Broken Image إن لم تُرفَع بعد).
--}}
@props(['season', 'campaign', 'availabilityLabel', 'currentStep', 'percentage', 'campaignAvailable' => true])

<section class="season-hero {{ $season->themePreset() === 'aseel' ? 'theme-aseel' : '' }} anim-fade-up">
    @if ($season->banner_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->banner_image))
        <div class="absolute inset-0 -z-10 opacity-30">
            <img src="{{ \Illuminate\Support\Facades\Storage::url($season->banner_image) }}" alt="" class="w-full h-full object-cover">
        </div>
    @endif

    <div class="relative max-w-2xl">
        <div class="flex items-center gap-3 mb-4">
            @if ($season->logo_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->logo_image))
                <img
                    src="{{ \Illuminate\Support\Facades\Storage::url($season->logo_image) }}"
                    alt="{{ $campaign->title }}"
                    class="season-logo"
                >
            @endif
            <span class="season-badge">موسم {{ $season->code }}</span>
        </div>

        <h1 class="font-display font-black text-3xl md:text-5xl text-white mb-3 leading-tight">
            {{ $campaign->title }}
        </h1>

        @if ($tagline = $season->heroTagline())
            <p class="text-lg text-slate-300 mb-4">{{ $tagline }}</p>
        @endif

        @if ($campaign->description)
            <p class="text-slate-400 mb-6">{{ $campaign->description }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-3 mb-6">
            <span class="chip !py-1.5">{{ $availabilityLabel }}</span>

            @if ($campaign->starts_at?->isFuture())
                <span class="chip !py-1.5">يبدأ {{ $campaign->starts_at->translatedFormat('j F') }}</span>
            @elseif ($campaign->ends_at && ! $campaign->ends_at->isPast())
                <span class="chip !py-1.5">ينتهي {{ $campaign->ends_at->translatedFormat('j F') }}</span>
            @endif

            @if ($season->grand_prize_description)
                <span class="chip !py-1.5 !text-gold">🏆 {{ $season->grand_prize_description }}</span>
            @else
                <span class="chip !py-1.5 !text-gold">🏆 سيتم الإعلان عن الجائزة الكبرى قريباً</span>
            @endif
        </div>

        @auth
            @if ($percentage > 0)
                <div class="mb-4 max-w-sm">
                    <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1">
                        <span>تقدّمك</span>
                        <span class="text-gold">{{ $percentage }}٪</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $percentage }}%"></div>
                    </div>
                </div>
            @endif

            @if (! $campaignAvailable)
                {{-- الحملة غير متاحة حالياً (قريباً/انتهت) - لا CTA للعب رغم وجود
                     currentStep تقنياً (isStepUnlocked ستمنعه أصلاً عند الدخول). --}}
                <div class="rounded-xl border border-white/10 bg-white/5 text-slate-300 px-5 py-3 font-bold inline-block">
                    {{ $availabilityLabel }}
                </div>
            @elseif ($currentStep)
                <a href="{{ route('campaigns.steps.show', [$campaign, $currentStep]) }}" class="btn-gem !py-3 !px-6">
                    {{ $percentage > 0 ? 'متابعة الرحلة' : 'ابدأ الرحلة' }} ←
                </a>
            @else
                <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-3 font-bold inline-block">
                    🎉 أكملت هذا الموسم بالكامل!
                </div>
            @endif
        @else
            <a href="{{ route('login') }}" class="btn-gem !py-3 !px-6">سجّل الدخول لبدء الرحلة ←</a>
        @endauth
    </div>
</section>