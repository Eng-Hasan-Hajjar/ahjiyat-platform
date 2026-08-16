@extends('layouts.app')

@section('title', 'الأحجيات')

@section('content')

    <div class="anim-fade-up mb-8">
        <h1 class="font-display font-black text-3xl md:text-4xl text-gradient-gem">
            {{ $category?->name ?? 'كل الأحجيات' }}
        </h1>
        <p class="text-slate-400 mt-2">اختر تحديك، فكّر بعمق، واجمع الجواهر الثمينة 💎</p>
    </div>

    {{-- ===== فلتر التصنيفات ===== --}}
    <div class="anim-fade-up d-1 flex flex-wrap gap-2 mb-8">
        <a href="{{ route('puzzles.index') }}"
           class="chip {{ ! $category ? 'chip-active' : '' }}">
            الكل
        </a>
        @foreach ($categories as $cat)
            <a href="{{ route('puzzles.category', $cat) }}"
               class="chip {{ $category?->id === $cat->id ? 'chip-active' : '' }}">
                {{ $cat->name }}
            </a>
        @endforeach
    </div>

    {{-- ===== شبكة الأحجيات ===== --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
        @forelse ($puzzles as $index => $puzzle)
            @php
                $difficultyLabel = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? $puzzle->difficulty;
                $diffStyle = [
                    'easy' => 'bg-emerald/10 text-emerald border-emerald/30',
                    'medium' => 'bg-amber-400/10 text-amber-300 border-amber-400/30',
                    'hard' => 'bg-rose/10 text-rose border-rose/30',
                ][$puzzle->difficulty] ?? 'bg-white/10 text-slate-300 border-white/20';
            @endphp

            <a href="{{ route('puzzles.show', $puzzle) }}"
               class="puzzle-card group block anim-fade-up d-{{ $index % 4 + 1 }}">

                <div class="flex items-center justify-between mb-3">
                    <span class="chip !py-1 !px-3 !text-xs">
                        {{ $puzzle->category->name }}
                    </span>
                    <span class="rounded-full border px-3 py-1 text-xs font-black {{ $diffStyle }}">
                        {{ $difficultyLabel }}
                    </span>
                </div>

                <h3 class="font-display font-black text-lg text-white mb-2 group-hover:text-gradient-gem transition line-clamp-2">
                    {{ Str::limit($puzzle->prompt, 60) }}
                </h3>

                <div class="flex items-center justify-between mt-4 pt-4 border-t border-white/5">
                    <span class="text-sm font-black text-gold">
                        +{{ $puzzle->gem_reward ?? 0 }} 💎
                    </span>
                    <span class="text-sm font-bold text-amethyst opacity-70 group-hover:opacity-100 transition">
                        حلّ الأحجية <span class="group-hover:-translate-x-1 inline-block transition-transform">←</span>
                    </span>
                </div>
            </a>
        @empty
            <div class="glass rounded-2xl p-10 text-center text-slate-400 col-span-full">
                <span class="text-5xl block mb-4">🔮</span>
                <p class="font-bold">لا توجد أحجيات بهذا التصنيف حالياً</p>
                <p class="text-sm mt-2">عد قريباً لتجد تحديات جديدة!</p>
            </div>
        @endforelse
    </div>

    {{-- ===== الترقيم ===== --}}
    <div class="flex justify-center">
        {{ $puzzles->links() }}
    </div>

@endsection