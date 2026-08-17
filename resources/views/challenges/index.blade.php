@extends('layouts.app')

@section('title', 'التحديات والبطولات')

@section('content')

    <div class="anim-fade-up mb-8">
        <h1 class="font-display font-black text-3xl md:text-4xl text-gradient-gem">التحديات والبطولات</h1>
        <p class="text-slate-400 mt-2">انضم لتحدٍ، نافس بقية اللاعبين، واكسب جواهر إضافية من مجموعة المكافآت 🏆</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        @forelse ($challenges as $index => $challenge)
            @php
                $typeLabel = $challenge->type === 'tournament' ? 'بطولة' : 'تحدي أسبوعي';
            @endphp

            <a href="{{ route('challenges.show', $challenge) }}"
               class="puzzle-card group block anim-fade-up d-{{ $index % 4 + 1 }}">

                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <span class="chip !py-1 !px-3 !text-xs">{{ $typeLabel }}</span>
                    <x-challenge-status-badge :challenge="$challenge" />
                </div>

                <h3 class="font-display font-black text-lg text-white mb-2 group-hover:text-gradient-gem transition line-clamp-2">
                    {{ $challenge->title }}
                </h3>

                @if ($challenge->description)
                    <p class="text-sm text-slate-400 line-clamp-2 mb-4">{{ $challenge->description }}</p>
                @endif

                <div class="flex items-center justify-between mt-4 pt-4 border-t border-white/5">
                    <span class="text-sm font-black text-gold flex items-center gap-1.5">
                        <span class="w-3.5 h-3.5 bg-gold gem-facet inline-block"></span>
                        {{ number_format($challenge->bonus_gem_pool) }}
                    </span>
                    <span class="text-xs font-bold text-slate-500">
                        {{ $challenge->participants_count }} مشارك
                    </span>
                </div>
            </a>
        @empty
            <div class="glass rounded-2xl p-10 text-center text-slate-400 col-span-full">
                <span class="text-5xl block mb-4">🏆</span>
                <p class="font-bold">لا توجد تحديات متاحة حالياً</p>
                <p class="text-sm mt-2">تابعنا، تحدي جديد قادم قريباً!</p>
            </div>
        @endforelse
    </div>

    <div class="flex justify-center mt-8">
        {{ $challenges->links() }}
    </div>

@endsection