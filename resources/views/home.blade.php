@extends('layouts.app')

@section('title', 'الرئيسية')

@section('content')

    {{-- ===== Hero Section - أحجية اليوم ===== --}}
    <section class="glass rounded-3xl p-8 md:p-12 mb-12 relative overflow-hidden anim-fade-up">
        {{-- زخارف gem-facet متحركة في الخلفية --}}
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-amethyst/30 gem-facet anim-float"></div>
        <div class="absolute -left-4 bottom-6 w-16 h-16 bg-gold/30 gem-facet anim-pulse-glow"></div>
        <div class="absolute right-10 top-10 w-24 h-24 bg-fuchsia-500/20 gem-facet anim-float"></div>

        <div class="relative max-w-2xl">
            <span class="inline-block text-xs font-black text-gold mb-3 tracking-widest uppercase anim-fade-up d-1">
                ✦ أحجية اليوم
            </span>

            @if ($dailyPuzzle)
                <h1 class="font-display font-black text-3xl md:text-5xl mb-4 leading-tight text-gradient-gem anim-fade-up d-2">
                    {{ $dailyPuzzle->prompt }}
                </h1>
                <p class="text-slate-300 mb-6 anim-fade-up d-3">
                    تحدّى نفسك اليوم واكسب <span class="text-gold font-black">{{ $dailyPuzzle->gem_reward }} جوهرة</span> 💎
                </p>
                <a href="{{ route('puzzles.show', $dailyPuzzle) }}"
                   class="btn-gem anim-fade-up d-4">
                    حلّ الأحجية الآن
                    <span class="w-3 h-3 bg-gold gem-facet inline-block"></span>
                </a>
            @else
                <h1 class="font-display font-black text-3xl md:text-5xl mb-4 leading-tight text-gradient-gem anim-fade-up d-2">
                    حلّ الألغاز، اجمع الجواهر، وتصدّر لوحة الصدارة
                </h1>
                <p class="text-slate-300 mb-6 anim-fade-up d-3">
                    تحديات ذهنية يومية تكافئك بالجواهر الثمينة 💎
                </p>
                <a href="{{ route('puzzles.index') }}" class="btn-gem anim-fade-up d-4">
                    تصفّح الأحجيات
                    <span>←</span>
                </a>
            @endif
        </div>
    </section>

    {{-- ===== التصنيفات ===== --}}
    <section class="anim-fade-up d-2">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-display font-black text-2xl md:text-3xl text-white">التصنيفات</h2>
            <a href="{{ route('puzzles.index') }}" class="text-sm font-bold text-amethyst hover:text-amethyst-700 transition">
                كل الأحجيات ←
            </a>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            @foreach ($categories as $index => $category)
                <a href="{{ route('puzzles.category', $category) }}"
                   class="puzzle-card text-center group anim-fade-up d-{{ $index % 4 + 1 }}">
                    <span class="gem-facet w-12 h-12 mx-auto mb-3 bg-gradient-to-br from-amethyst to-gold grid place-items-center">
                        <span class="text-white font-black">{{ $category->puzzles_count }}</span>
                    </span>
                    <span class="font-display font-black text-white block mb-1 group-hover:text-gradient-gem transition">
                        {{ $category->name }}
                    </span>
                    <span class="text-xs text-slate-400 font-semibold">{{ $category->puzzles_count }} أحجية</span>
                </a>
            @endforeach
        </div>
    </section>

@endsection