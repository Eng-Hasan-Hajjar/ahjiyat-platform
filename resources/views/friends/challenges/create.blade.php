@extends('layouts.app')

@section('title', 'تحدَّ '.$opponent->name)

@section('content')
    <div class="max-w-3xl mx-auto space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <h1 class="font-display font-black text-2xl text-white">تحدَّ {{ $opponent->name }}</h1>
            <a href="{{ route('friends.index') }}" class="chip">← الأصدقاء</a>
        </div>
        <p class="text-xs text-slate-400">اختر أحجية واحدة. لكل طرف محاولة واحدة، والنقاط تُحسب من صحة الإجابة وسرعتها بالسيرفر. الأحجيات ذات التلميح غير متاحة.</p>

        <form method="GET" action="{{ route('friends.challenges.create', $opponent) }}" role="search" class="glass rounded-3xl p-5 flex flex-wrap items-center gap-3">
            <label for="q" class="sr-only">ابحث عن أحجية</label>
            <input id="q" type="search" name="q" value="{{ $term }}" maxlength="50" placeholder="ابحث بعنوان الأحجية"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" autocomplete="off">
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        <div class="glass rounded-2xl divide-y divide-white/5">
            @forelse ($puzzles as $puzzle)
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="font-bold text-white truncate">{{ $puzzle->title }}</div>
                        <div class="text-[11px] text-slate-500 mt-0.5"><x-difficulty-badge :difficulty="$puzzle->difficulty" />@if ($puzzle->time_limit_seconds) · {{ $puzzle->time_limit_seconds }} ث @endif</div>
                    </div>
                    <form method="POST" action="{{ route('friends.challenges.store', $opponent) }}">
                        @csrf
                        <input type="hidden" name="puzzle_id" value="{{ $puzzle->id }}">
                        <button type="submit" class="btn-gem !py-1.5 !px-4 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="تحدَّ {{ $opponent->name }} بأحجية {{ $puzzle->title }}">تحدَّ بهذه</button>
                    </form>
                </div>
            @empty
                <p class="px-4 py-10 text-center text-slate-500 text-sm">لا أحجيات مطابقة صالحة للتحدي.</p>
            @endforelse
        </div>
        <div>{{ $puzzles->links() }}</div>
    </div>
@endsection
