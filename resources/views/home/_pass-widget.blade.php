{{--
    E40: ودجت مسار التحدي بلوحة اللاعب. قراءة فقط (لا انضمام ولا استعادة ولا كتابة). لا لغة ضغط ولا عدّاد تنازلي مصطنع: أرقام حقيقية فقط.
    @var array $widget  (PassPageData::widgetFor)
--}}
@php($accent = \App\Services\AhjiyatPass\PassTheme::accent($widget['pass']))
<section data-pass-widget aria-labelledby="pass-widget-h" class="glass rounded-3xl p-5 sm:p-6 anim-fade-up d-1" style="--pass-accent: {{ $accent }}">
    <div class="flex items-center justify-between gap-3 mb-3">
        <h2 id="pass-widget-h" class="font-display font-black text-lg text-white truncate">{{ $widget['pass']->name }}</h2>
        <a href="{{ route('pass.index') }}" class="text-sm font-bold text-amethyst shrink-0 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">فتح المسار ←</a>
    </div>

    <div class="flex items-center justify-between text-sm mb-1.5">
        <span class="font-bold text-white">الشريحة {{ $widget['tier'] }} <span class="text-slate-500 font-normal">/ {{ $widget['max_tier'] }}</span></span>
        <span class="text-xs font-bold text-slate-400">{{ number_format($widget['points']) }} نقطة</span>
    </div>
    <div class="w-full h-2 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($widget['ratio'] * 100) }}" aria-label="التقدّم نحو الشريحة التالية">
        <div class="h-full rounded-full" style="width: {{ (int) round($widget['ratio'] * 100) }}%; background: var(--pass-accent)"></div>
    </div>

    <p class="mt-3 text-xs text-slate-400">
        @if ($widget['active'])
            @if ($widget['next_remaining'] > 0)
                متبقٍ {{ number_format($widget['next_remaining']) }} نقطة للشريحة التالية.
            @else
                بلغتَ آخر شريحة.
            @endif
            @if ($widget['days_left'] > 0) · ينتهي المسار بعد {{ $widget['days_left'] }} يوم @endif
        @else
            انتهى المسار؛ يمكنك استلام ما كسبته خلال مهلة الاستلام.
        @endif
        @if ($widget['claimable_count'] > 0)
            <a href="{{ route('pass.index') }}" class="font-bold text-gold rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">· {{ $widget['claimable_count'] }} مكافأة جاهزة للاستلام</a>
        @endif
    </p>
</section>
