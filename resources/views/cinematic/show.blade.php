@extends('layouts.app')

@section('title', $story->title.' - سلسلة سينمائية')
@section('meta_description', $story->summary ?: 'سلسلة أحجيات تفاعلية بمشاهد سينمائية: '.$story->title)
@section('canonical', route('cinematic.show', $story->slug))
@section('content_width', 'max-w-3xl')

{{--
    CIN-1: صفحة القصة. قراءة فقط. لا تعرض أي مشهد ولا وسيط ولا حل؛ المراحل بعناوينها فقط، وللقصة المميزة تُخفى عن غير المالك.
    الزر الوحيد يحدده القرار الخادمي (دخول/ملكية/متاح). لا اقتصاد.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('cinematic.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />السلاسل السينمائية
        </a>

        @if (session('cinematic_notice') === 'premium_locked')
            <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-3 text-sm font-bold text-gold" role="status">هذه سلسلة مميزة؛ يلزم امتلاك الوصول إليها لمتابعة المشاهد.</div>
        @endif

        <header class="puzzle-card !p-6 md:!p-8" data-cinematic-header>
            @if ($cover)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-5">
                    <img src="{{ $cover }}" alt="{{ $story->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <x-status-badge tone="primary" icon="sparkles">سلسلة سينمائية</x-status-badge>
                <x-status-badge tone="neutral" :small="true">{{ $story->label_kind === 'short' ? 'قصة قصيرة' : 'قصة طويلة' }}</x-status-badge>
                @if ($story->isPremium()) <x-status-badge tone="warning" :small="true" icon="lock">مميزة</x-status-badge> @endif
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $story->title }}</h1>
            @if ($story->summary)
                <p class="text-sm text-slate-200 break-words whitespace-pre-line">{{ $story->summary }}</p>
            @endif

            <div class="mt-5" data-cinematic-cta data-state="{{ $decision['reason'] }}">
                @if ($decision['allowed'])
                    @if ($progress)
                        <div class="flex items-center justify-between text-sm font-bold text-slate-300 mb-2">
                            <span>تقدّمك</span><span>{{ $percent }}%</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-white/5 overflow-hidden mb-4" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="تقدّمك في السلسلة">
                            <div class="h-full rounded-full bg-amethyst" style="width: {{ $percent }}%"></div>
                        </div>
                    @endif
                    <a href="{{ route('cinematic.play', $story->slug) }}" class="btn-gem !px-8 inline-flex items-center gap-2" data-cinematic-play>
                        {{ $progress ? ($progress->status === 'completed' ? 'شاهد من جديد' : 'تابع من حيث توقفت') : 'ابدأ السلسلة' }}<x-ui-icon name="chevron-left" class="w-4 h-4" />
                    </a>
                @elseif ($decision['reason'] === 'login')
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                        <span>سجّل الدخول لتبدأ السلسلة ويُحفظ تقدّمك.</span>
                        <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                    </div>
                @elseif ($decision['reason'] === 'premium_locked')
                    <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-slate-100 flex flex-wrap items-center justify-between gap-3" data-cinematic-locked>
                        <span class="font-bold">هذه سلسلة مميزة؛ يلزم امتلاك الوصول لمتابعة المشاهد.</span>
                        @if ($series)
                            <a href="{{ route('series.show', $series->public_id) }}" class="btn-gem !py-2 !px-5 text-sm">صفحة الوصول</a>
                        @endif
                    </div>
                @endif
            </div>
        </header>

        @if ($stages->isNotEmpty())
            <section aria-label="مراحل السلسلة">
                <h2 class="font-display font-black text-lg text-white mb-3">المراحل</h2>
                <ol class="space-y-2" data-cinematic-stages>
                    @foreach ($stages as $stage)
                        <li class="glass rounded-xl px-4 py-3">
                            <p class="font-black text-white break-words">{{ $loop->iteration }}. {{ $stage->title }}</p>
                            @if ($stage->summary)<p class="text-xs text-slate-400 mt-0.5 break-words">{{ $stage->summary }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif
    </div>
@endsection
