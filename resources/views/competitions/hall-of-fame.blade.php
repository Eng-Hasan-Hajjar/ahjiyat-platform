@extends('layouts.app')

@section('title', 'قاعة الأمجاد')

@section('content')
    @php $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉']; @endphp
    <div class="max-w-5xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <div>
                <h1 class="font-display font-black text-2xl md:text-3xl text-white">قاعة الأمجاد</h1>
                <p class="text-sm text-slate-400 mt-1">أبطال المنافسات الرسمية المعتمَدة نتائجها.</p>
            </div>
            <a href="{{ route('competitions.index') }}" class="chip">← كل المنافسات</a>
        </div>

        <form method="GET" action="{{ route('competitions.hall-of-fame') }}" role="search" class="glass rounded-3xl p-5 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="q" class="sr-only">عنوان المنافسة</label>
            <input id="q" type="search" name="q" value="{{ $term }}" maxlength="60" placeholder="ابحث بعنوان المنافسة" autocomplete="off"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <label for="year" class="sr-only">السنة</label>
            <select id="year" name="year" class="rounded-xl bg-white/5 border border-white/10 px-3 py-2.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <option value="">كل السنوات</option>
                @foreach ($years as $y)
                    <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">تصفية</button>
        </form>

        @forelse ($events as $event)
            @php $top = $podium->get($event->id, collect()); @endphp
            <article class="glass rounded-3xl p-5 md:p-6 anim-fade-up" aria-labelledby="ev-{{ $event->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                    <h2 id="ev-{{ $event->id }}" class="font-display font-black text-lg text-white"><a href="{{ route('competitions.show', $event) }}" class="hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $event->title }}</a></h2>
                    <span class="text-xs text-slate-400">{{ $event->ends_at->format('Y-m-d') }} · {{ $event->participants_count }} مشارك</span>
                </div>
                @if ($top->isEmpty())
                    <p class="text-sm text-slate-500">لا نتائج صحيحة في هذه المنافسة.</p>
                @else
                    <ol class="grid gap-2 sm:grid-cols-3">
                        @foreach ($top as $result)
                            <li class="rounded-2xl border border-white/10 bg-white/5 p-3 flex items-center gap-3 {{ $result->final_rank === 1 ? 'sm:col-span-3 !border-gold/40' : '' }}">
                                <span class="text-2xl" aria-label="المركز {{ $result->final_rank }}">{{ $medals[$result->final_rank] }}</span>
                                <x-player-avatar :avatar="$result->user->identityAvatar ?? null" :frame="$result->user->identityFrame ?? null" :name="$result->user->name" size="md" />
                                @if ($result->user->profileLinkable ?? false)
                                    <a href="{{ route('players.show', $result->user) }}" class="font-bold text-white truncate hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $result->user->name }}</a>
                                @else
                                    <span class="font-bold text-white truncate">{{ $result->user->name }}</span>
                                @endif
                                <span class="ms-auto text-xs text-slate-400">{{ $result->score }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </article>
        @empty
            <p class="glass rounded-2xl px-4 py-12 text-center text-slate-500 text-sm">لا منافسات معتمَدة مطابقة بعد.</p>
        @endforelse

        <div>{{ $events->links() }}</div>
    </div>
@endsection
