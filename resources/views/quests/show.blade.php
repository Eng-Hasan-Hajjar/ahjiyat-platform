@extends('layouts.app')

@section('title', 'المهام')

@section('content')

    <div class="max-w-3xl mx-auto">

        <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-6 text-center anim-fade-up">
            استمر باللعب وحقق أهدافك اليومية والأسبوعية - كلها مجانية بالكامل.
        </div>

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
                    <span class="block text-2xl font-black text-gold">{{ $streak->current_streak }}</span>
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

        {{-- بند 371-373: ملخَّص السلسلة - بلا عدّاد تنازلي، بلا تقويم كامل --}}
        <div class="puzzle-card !p-6 anim-fade-up d-4">
            <h2 class="font-display font-black text-lg text-white mb-3">سلسلة نشاطك</h2>
            <p class="text-sm text-slate-400">
                سلسلتك الحالية <span class="text-gold font-bold">{{ $streak->current_streak }}</span> يوم متتالٍ من اللعب.
                @if ($streak->longest_streak > $streak->current_streak)
                    أطول سلسلة حققتها: <span class="font-bold text-white">{{ $streak->longest_streak }}</span> يوم.
                @endif
            </p>
            @if ($streak->last_active_date)
                <p class="text-xs text-slate-500 mt-2">آخر نشاط: {{ $streak->last_active_date->format('Y-m-d') }}</p>
            @endif
        </div>

    </div>

@endsection
