@extends('layouts.app')

@section('title', 'المهام')

@section('content')

    <div class="max-w-3xl mx-auto">

        {{-- E23: عنوان واحد للصفحة (ترويسة موحَّدة). E34: مركز المهام - /missions الأساسي و/quests القديم يخدمان هذه الصفحة نفسها. --}}
        <x-page-header title="المهام" icon="quests" subtitle="استمر باللعب وحقق أهدافك اليومية والأسبوعية - كلها مجانية بالكامل." class="anim-fade-up" />

        {{-- E34: العودة بعد غياب عن اللعب الرسمي - ترحيب ومقترحات سهلة فقط، بلا مكافأة جواهر ولا ضغط. تزول تلقائيًا بعد أول حل رسمي. --}}
        @if ($comeback)
            <section class="glass rounded-3xl p-5 mb-8 anim-fade-up" aria-labelledby="comeback-h">
                <h2 id="comeback-h" class="font-display font-black text-lg text-white mb-1">مرحبًا بعودتك</h2>
                <p class="text-sm text-slate-400 mb-3">اشتقنا لوجودك. ابدأ بخطوة صغيرة وسهلة متى شئت:</p>
                <ul class="space-y-2">
                    @foreach ($comeback['suggestions'] as $s)
                        <li><a href="{{ $s['url'] }}" class="inline-flex items-center gap-2 text-sm font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $s['title'] }} ←</a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- بند 360: الملخَّص --}}
        <div class="puzzle-card !p-6 mb-8 anim-fade-up d-1">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div>
                    <span class="block text-2xl font-black text-white">{{ $daily->where('progress.completed_at', '!=', null)->count() }}/{{ $daily->count() }}</span>
                    <span class="block text-xs font-bold text-slate-500 mt-1">مهام اليوم</span>
                </div>
                <div>
                    <span class="block text-2xl font-black text-white">{{ $weekly->where('progress.completed_at', '!=', null)->count() }}/{{ $weekly->count() }}</span>
                    <span class="block text-xs font-bold text-slate-500 mt-1">أهداف الأسبوع</span>
                </div>
                <div>
                    <span class="block text-2xl font-black text-gold">{{ $milestones['current'] }}</span>
                    <span class="block text-xs font-bold text-slate-500 mt-1">سلسلة نشاطك الحالية</span>
                </div>
                <div>
                    <span class="block text-2xl font-black text-white">{{ $streak->longest_streak }}</span>
                    <span class="block text-xs font-bold text-slate-500 mt-1">أطول سلسلة</span>
                </div>
            </div>
        </div>

        {{-- بند 362: المهام اليومية --}}
        <div class="mb-8 anim-fade-up d-2">
            <h2 class="font-display font-black text-lg text-white mb-3">مهام اليوم</h2>
            <div class="space-y-3">
                @forelse ($daily as $row)
                    @include('quests._quest-card', $row)
                @empty
                    <p class="text-sm text-slate-500">لا توجد مهام يومية متاحة حاليًا.</p>
                @endforelse
            </div>
        </div>

        {{-- بند 363: الأهداف الأسبوعية --}}
        <div class="mb-8 anim-fade-up d-3">
            <h2 class="font-display font-black text-lg text-white mb-3">أهداف هذا الأسبوع</h2>
            <div class="space-y-3">
                @forelse ($weekly as $row)
                    @include('quests._quest-card', $row)
                @empty
                    <p class="text-sm text-slate-500">لا توجد أهداف أسبوعية متاحة حاليًا.</p>
                @endforelse
            </div>
        </div>

        {{-- بند 371-373 + E34: ملخَّص السلسلة ومعالمها - بلا عدّاد تنازلي، بلا تقويم كامل، بلا عقوبة على غياب يوم. --}}
        <div class="puzzle-card !p-6 mb-8 anim-fade-up d-4">
            <h2 class="font-display font-black text-lg text-white mb-3">سلسلة نشاطك</h2>
            <p class="text-sm text-slate-400">
                سلسلتك الحالية <span class="text-gold font-bold">{{ $milestones['current'] }}</span> يوم متتالٍ من اللعب.
                @if ($streak->longest_streak > $milestones['current'])
                    أطول سلسلة حققتها: <span class="font-bold text-white">{{ $streak->longest_streak }}</span> يوم.
                @endif
            </p>
            @if ($streak->last_active_date)
                <p class="text-xs text-slate-500 mt-2">آخر نشاط: {{ $streak->last_active_date->format('Y-m-d') }}</p>
            @endif
            @if (! empty($milestones['reached']) || $milestones['next'])
                <div class="mt-3 flex flex-wrap items-center gap-2" aria-label="معالم السلسلة">
                    @foreach ($milestones['reached'] as $days)
                        <x-status-badge tone="success" small icon="check">{{ $days }} يوم</x-status-badge>
                    @endforeach
                    @if ($milestones['next'])
                        <span class="text-xs text-slate-400">المعلَم التالي: <span class="font-bold text-white">{{ $milestones['next'] }}</span> يوم ({{ $milestones['days_to_next'] }} أيام متبقية على سلسلتك الحالية)</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- E34: إنجازات قاربت الاكتمال (من نظام الإنجازات نفسه) --}}
        @if ($nearAchievements->isNotEmpty())
            <section class="mb-8 anim-fade-up" aria-labelledby="near-ach-h">
                <h2 id="near-ach-h" class="font-display font-black text-lg text-white mb-3">إنجازات قريبة</h2>
                <ul class="space-y-3">
                    @foreach ($nearAchievements as $a)
                        <li class="puzzle-card !p-4">
                            <div class="flex items-center justify-between text-sm mb-1.5">
                                <span class="font-bold text-white">{{ $a['name'] }}</span>
                                <span class="text-xs font-bold text-slate-400">{{ $a['current'] }} / {{ $a['target'] }}</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $a['target'] }}" aria-valuenow="{{ $a['current'] }}" aria-label="تقدُّم {{ $a['name'] }}">
                                <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $a['percent'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- E34: أجندتي وأحداث قادمة (تجميع للقراءة من التقويم؛ التفاصيل بصفحة /events) --}}
        @if ($agenda->isNotEmpty() || $events->isNotEmpty())
            <section class="mb-4 anim-fade-up" aria-labelledby="events-h">
                <div class="flex items-center justify-between mb-3">
                    <h2 id="events-h" class="font-display font-black text-lg text-white">الأحداث</h2>
                    <a href="{{ route('events.index') }}" class="text-sm font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">كل الأحداث ←</a>
                </div>
                @if ($agenda->isNotEmpty())
                    <h3 class="text-xs font-black text-slate-400 mb-2">أجندتي</h3>
                    <ul class="space-y-3 mb-4">
                        @foreach ($agenda as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
                @if ($events->isNotEmpty())
                    <ul class="space-y-3">
                        @foreach ($events as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <p class="text-xs text-slate-500">التوقيت المعتمد: {{ $timezone }}.</p>

    </div>

@endsection
