@extends('layouts.app')

@section('title', 'الرئيسية')

@section('content')

    <section class="glass rounded-3xl p-8 md:p-12 mb-12 relative overflow-hidden anim-fade-up">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-amethyst/30 gem-facet anim-float"></div>
        <div class="absolute -left-4 bottom-6 w-16 h-16 bg-gold/30 gem-facet anim-pulse-glow"></div>
        <div class="absolute right-10 top-10 w-24 h-24 bg-fuchsia-500/20 gem-facet anim-float"></div>

        <div class="relative max-w-2xl">
            <span class="inline-block text-xs font-black text-gold mb-3 tracking-widest uppercase anim-fade-up d-1">
                ✦ {{ $home['hero_subtitle'] ?: 'أحجية اليوم' }}
            </span>

            @if ($home['hero_title'])
                <h1 class="font-display font-black text-3xl md:text-5xl mb-4 leading-tight text-gradient-gem anim-fade-up d-2">
                    {{ $home['hero_title'] }}
                </h1>
                @if ($dailyPuzzle)
                    <p class="text-slate-300 mb-6 anim-fade-up d-3">
                        تحدّى نفسك اليوم واكسب <span class="text-gold font-black">{{ $dailyPuzzle->gem_reward }} جوهرة</span> 💎
                    </p>
                @endif
                <a href="{{ $dailyPuzzle ? route('puzzles.show', $dailyPuzzle) : route('puzzles.index') }}" class="btn-gem anim-fade-up d-4">
                    {{ $home['cta_text'] }}
                    <span>←</span>
                </a>
            @elseif ($dailyPuzzle)
                <h1 class="font-display font-black text-3xl md:text-5xl mb-4 leading-tight text-gradient-gem anim-fade-up d-2">
                    {{ $dailyPuzzle->prompt }}
                </h1>
                <p class="text-slate-300 mb-6 anim-fade-up d-3">
                    تحدّى نفسك اليوم واكسب <span class="text-gold font-black">{{ $dailyPuzzle->gem_reward }} جوهرة</span> 💎
                </p>
                <a href="{{ route('puzzles.show', $dailyPuzzle) }}" class="btn-gem anim-fade-up d-4">
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
                    {{ $home['cta_text'] }}
                    <span>←</span>
                </a>
            @endif
        </div>




    </section>

    @if ($featuredSeason)
        <section class="mb-12 anim-fade-up d-1">
            <x-season-hero
                :season="$featuredSeason"
                :campaign="$featuredSeason->campaign"
                :availability-label="'مباشر الآن'"
                :current-step="$featuredSeasonCurrentStep"
                :percentage="$featuredSeasonPercentage"
            />
        </section>
    @endif



        @auth
        {{-- E12 (بند 130/273): بطاقة تقدُّم صغيرة - بلا أي CTA دفع بجانبها --}}
        <section class="mb-12 anim-fade-up d-1">
            <a href="{{ route('progress.show') }}" class="puzzle-card flex items-center gap-4 hover:border-amethyst/40 transition">
                <span class="gem-facet w-12 h-12 grid place-items-center text-lg font-black text-white bg-gradient-to-br from-amethyst to-gold shrink-0">
                    {{ $myCurrentLevel->level_number }}
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <span class="font-bold text-white text-sm">{{ $myCurrentLevel->name }}</span>
                        @if ($myNextLevel)
                            <span class="text-xs text-slate-500">{{ $myProgressPercent }}%</span>
                        @else
                            <span class="text-xs text-slate-500">أعلى مستوى</span>
                        @endif
                    </div>
                    <div class="w-full h-1.5 rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $myProgressPercent }}%"></div>
                    </div>
                </div>
                <span class="text-xs text-amethyst font-bold shrink-0">تقدُّمي ←</span>
            </a>
        </section>
    @endauth

    

    @if ($home['show_categories'])
        <section class="anim-fade-up d-2 mb-12">
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
    @endif

    @if ($home['show_leaderboard'] || $home['show_challenges'])
        <section class="anim-fade-up d-3 flex flex-wrap gap-3">
            @if ($home['show_leaderboard'])
                <a href="{{ route('leaderboard.index') }}" class="chip !py-2.5 !px-5">🏆 لوحة الصدارة ←</a>
            @endif
            @if ($home['show_challenges'])
                <a href="{{ route('challenges.index') }}" class="chip !py-2.5 !px-5">⚔️ التحديات الحالية ←</a>
            @endif
        </section>
    @endif

@endsection