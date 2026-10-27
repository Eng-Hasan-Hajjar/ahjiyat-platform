{{--
    E42: بطاقة موسم موحَّدة للفهرس والأرشيف. كل النصوص مهرَّبة؛ الحالة والتواريخ من الخادم (SeasonLifecycleService) لا من الواجهة.
    المتغيرات: $item (model/state/label/starts_at/ends_at) · $wide (اختياري: بطاقة المبرَز) · $userCompleted (اختياري: ملخص الأرشيف للمستخدم المسجَّل).
--}}
@php
    $season = $item->model;
    $rtl = app()->getLocale() === 'ar';
    $wide = $wide ?? false;
    $userCompleted = $userCompleted ?? null;
@endphp
<a href="{{ route('seasons.show', $season) }}"
   class="puzzle-card !p-0 overflow-hidden anim-fade-up hover:border-amethyst/40 transition motion-reduce:transition-none block focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ $season->is_featured ? 'season-card--featured' : '' }} {{ $wide ? 'md:col-span-2' : '' }}">
    <div class="relative {{ $wide ? 'h-48' : 'h-36' }} bg-gradient-to-br from-amethyst/30 to-night-800">
        @if ($season->banner_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->banner_image))
            <img src="{{ \Illuminate\Support\Facades\Storage::url($season->banner_image) }}"
                 alt="{{ $season->campaign->title }}" class="w-full h-full object-cover" loading="lazy">
        @endif

        <span class="season-badge absolute top-3 {{ $rtl ? 'right-3' : 'left-3' }}">
            موسم {{ $season->code }}
        </span>
        @if ($season->is_featured)
            <span class="chip absolute top-3 {{ $rtl ? 'left-3' : 'right-3' }} !text-gold">مُبرَز</span>
        @endif

        @if ($season->logo_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->logo_image))
            <img
                src="{{ \Illuminate\Support\Facades\Storage::url($season->logo_image) }}"
                alt=""
                class="season-logo absolute -bottom-6 {{ $rtl ? 'right-4' : 'left-4' }}"
            >
        @endif
    </div>

    <div class="p-5 {{ $season->logo_image ? 'pt-8' : '' }}">
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <h2 class="font-display font-black text-xl text-white break-words min-w-0">{{ $season->campaign->title }}</h2>
            <x-status-badge :tone="$item->state === 'live' ? 'success' : ($item->state === 'finished' ? 'neutral' : 'warning')" small>{{ $item->label }}</x-status-badge>
        </div>

        @if ($tagline = $season->heroTagline())
            <p class="text-sm text-slate-400 line-clamp-2 mb-3">{{ $tagline }}</p>
        @elseif ($season->campaign->description)
            <p class="text-sm text-slate-400 line-clamp-2 mb-3">{{ $season->campaign->description }}</p>
        @endif

        @if ($item->state === 'upcoming' && $item->starts_at)
            <p class="text-xs font-bold text-slate-400 mb-2">يبدأ {{ $item->starts_at->translatedFormat('j F Y') }}</p>
        @elseif ($item->state === 'live' && $item->ends_at)
            <p class="text-xs font-bold text-slate-400 mb-2">ينتهي {{ $item->ends_at->translatedFormat('j F Y') }}</p>
        @elseif ($item->state === 'finished' && $item->ends_at)
            <p class="text-xs font-bold text-slate-400 mb-2">انتهى {{ $item->ends_at->translatedFormat('j F Y') }}</p>
        @endif

        @if ($userCompleted === true)
            <p class="text-xs font-black text-emerald mb-2">أكملتَ رحلة هذا الموسم</p>
        @endif

        <div class="flex items-center justify-between text-xs font-bold">
            <span class="text-gold">🏆 {{ $season->grand_prize_description ?: 'الجائزة الكبرى قريباً' }}</span>
            <span class="text-amethyst">
                @if ($item->state === 'live') ابدأ الرحلة ←
                @elseif ($item->state === 'upcoming') التفاصيل ←
                @else شاهد النتائج ← @endif
            </span>
        </div>
    </div>
</a>
