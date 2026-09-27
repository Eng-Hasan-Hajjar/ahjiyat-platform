@extends('layouts.app')

@section('title', 'لوحة الصدارة')

@section('content')

    <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-6 anim-fade-up">لوحة الصدارة</h1>

    <div class="glass rounded-2xl divide-y divide-white/5 anim-fade-up d-1">
        @forelse ($topUsers as $index => $user)
            <div class="flex items-center gap-3 md:gap-4 px-4 md:px-5 py-4">
                <span class="shrink-0 w-8 h-8 md:w-9 md:h-9 rounded-full flex items-center justify-center text-sm font-black
                    {{ $index === 0 ? 'bg-gold text-night-950' : ($index < 3 ? 'bg-amethyst/20 text-amethyst border border-amethyst/40' : 'bg-white/5 text-slate-400') }}">
                    {{ $index + 1 }}
                </span>

                @if ($index === 0)
                    <span class="text-lg">🥇</span>
                @elseif ($index === 1)
                    <span class="text-lg">🥈</span>
                @elseif ($index === 2)
                    <span class="text-lg">🥉</span>
                @endif

                <x-player-avatar :avatar="$user->identityAvatar" :frame="$user->identityFrame" :name="$user->name" size="sm" />

                <div class="flex-1 min-w-0">
                    @if ($user->profileLinkable)
                        <a href="{{ route('players.show', $user) }}" class="font-bold text-white truncate hover:text-amethyst transition block">{{ $user->name }}</a>
                    @else
                        <span class="font-bold text-white truncate block">{{ $user->name }}</span>
                    @endif
                    @if ($user->identityTitle && $user->identityTitle->cosmetic_text)
                        <span class="text-xs text-slate-500">{{ $user->identityTitle->cosmetic_text }}</span>
                    @endif
                </div>

                <span class="shrink-0 text-xs md:text-sm font-semibold text-slate-400">
                    {{ $user->solved_count }} أحجية محلولة
                </span>
            </div>
        @empty
            <p class="px-4 py-10 text-center text-slate-500 text-sm">لا توجد بيانات كافية بعد.</p>
        @endforelse
    </div>

@endsection