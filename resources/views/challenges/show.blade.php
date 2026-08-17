@extends('layouts.app')

@section('title', $challenge->title)

@section('content')

    @php
        $now = now();
        $isOpen = $challenge->is_active && $now->between($challenge->starts_at, $challenge->ends_at);
        $isUpcoming = $challenge->starts_at->isFuture();
        $statusLabel = $isOpen ? 'مفتوح الآن' : ($isUpcoming ? 'يبدأ قريباً' : 'انتهى');
        $statusStyle = $isOpen
            ? 'bg-emerald/10 text-emerald border-emerald/30'
            : ($isUpcoming ? 'bg-gold/10 text-gold border-gold/30' : 'bg-white/5 text-slate-400 border-white/10');
        $typeLabel = $challenge->type === 'tournament' ? 'بطولة' : 'تحدي أسبوعي';
    @endphp

    <a href="{{ route('challenges.index') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition mb-4 anim-fade-up">
        → كل التحديات
    </a>

    {{-- ===== رأس التحدي ===== --}}
    <div class="glass rounded-3xl p-6 md:p-9 mb-8 anim-fade-up d-1">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="chip !py-1 !px-3">{{ $typeLabel }}</span>
            <span class="rounded-full border px-3 py-1 text-xs font-black {{ $statusStyle }}">{{ $statusLabel }}</span>
        </div>

        <h1 class="font-display font-black text-2xl md:text-4xl text-gradient-gem mb-3">{{ $challenge->title }}</h1>

        @if ($challenge->description)
            <p class="text-slate-300 mb-5">{{ $challenge->description }}</p>
        @endif

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-slate-400 font-semibold mb-6">
            <span>يبدأ: {{ $challenge->starts_at->format('Y-m-d H:i') }}</span>
            <span>ينتهي: {{ $challenge->ends_at->format('Y-m-d H:i') }}</span>
            <span class="text-gold font-black flex items-center gap-1.5">
                <span class="w-3.5 h-3.5 bg-gold gem-facet inline-block"></span>
                مجموع المكافأة: {{ number_format($challenge->bonus_gem_pool) }}
            </span>
        </div>

        @auth
            @if ($participation)
                <div class="inline-flex items-center gap-2 rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-bold">
                    🎯 أنت مشارك في هذا التحدي — نقاطك الحالية: {{ $participation->score }}
                </div>
            @elseif ($isOpen)
                <form method="POST" action="{{ route('challenges.join', $challenge) }}">
                    @csrf
                    <button type="submit" class="btn-gem">انضم إلى التحدي</button>
                </form>
            @elseif ($isUpcoming)
                <span class="text-sm font-bold text-slate-500">سيفتح باب الانضمام عند بدء التحدي.</span>
            @else
                <span class="text-sm font-bold text-slate-500">انتهى هذا التحدي.</span>
            @endif
        @else
            <a href="{{ route('login') }}" class="chip">سجّل الدخول للانضمام إلى التحدي</a>
        @endauth
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        {{-- ===== أحجيات التحدي ===== --}}
        <div class="lg:col-span-2">
            <h2 class="font-display font-black text-xl md:text-2xl text-white mb-4 anim-fade-up d-2">أحجيات التحدي</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @forelse ($challenge->puzzles as $index => $puzzle)
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
                            <span class="chip !py-1 !px-3 !text-xs">{{ $puzzle->category->name }}</span>
                            <span class="rounded-full border px-3 py-1 text-xs font-black {{ $diffStyle }}">{{ $difficultyLabel }}</span>
                        </div>
                        <h3 class="font-display font-black text-white group-hover:text-gradient-gem transition line-clamp-2">
                            {{ Str::limit($puzzle->prompt, 60) }}
                        </h3>
                    </a>
                @empty
                    <div class="glass rounded-2xl p-8 text-center text-slate-400 col-span-full text-sm">
                        لم تُضَف أحجيات لهذا التحدي بعد.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ===== لوحة صدارة التحدي ===== --}}
        <div>
            <h2 class="font-display font-black text-xl md:text-2xl text-white mb-4 anim-fade-up d-2">صدارة التحدي</h2>

            <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-3">
                @forelse ($leaderboard as $index => $entry)
                    <div class="flex items-center gap-3 px-4 py-3 {{ auth()->id() === $entry->user_id ? 'bg-amethyst/10' : '' }}">
                        <span class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-black
                            {{ $index === 0 ? 'bg-gold text-night-950' : ($index < 3 ? 'bg-amethyst/20 text-amethyst border border-amethyst/40' : 'bg-white/5 text-slate-400') }}">
                            {{ $index + 1 }}
                        </span>
                        <span class="flex-1 min-w-0 text-sm font-bold text-white truncate">{{ $entry->user->name }}</span>
                        <span class="shrink-0 text-xs font-black text-gold">{{ $entry->score }}</span>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-slate-500 text-sm">لا يوجد مشاركون بعد. كن أول من ينضم!</p>
                @endforelse
            </div>
        </div>
    </div>

@endsection