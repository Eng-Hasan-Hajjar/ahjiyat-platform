@extends('layouts.app')

@section('title', 'منافسات '.$player->name)

@section('content')
    @php $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉']; @endphp
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <h1 class="font-display font-black text-2xl text-white">سجل منافسات {{ $player->name }}</h1>
            <a href="{{ route('players.show', $player) }}" class="chip">← الملف</a>
        </div>

        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="stats-title">
            <h2 id="stats-title" class="sr-only">الإحصاءات</h2>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                <div><dd class="font-display font-black text-2xl text-gold">{{ $stats['events_won'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة الأوائل</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['best_rank'] ?? '—' }}</dd><dt class="text-xs text-slate-400">أفضل مركز</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['events_participated'] }}</dd><dt class="text-xs text-slate-400">منافسات شارك بها</dt></div>
            </dl>
            @if ($challenges)
                <p class="text-xs text-slate-400 mt-4 text-center">تحدّيات الأصدقاء (لك وحدك): <strong class="text-emerald-400">{{ $challenges['wins'] }} فوز</strong> · <strong class="text-rose-400">{{ $challenges['losses'] }} خسارة</strong> · <strong>{{ $challenges['draws'] }} تعادل</strong></p>
            @endif
        </section>

        <section class="anim-fade-up" aria-labelledby="cabinet-title">
            <h2 id="cabinet-title" class="font-display font-black text-lg text-white mb-3">خزانة الجوائز</h2>
            @if ($trophies->isEmpty())
                <p class="glass rounded-2xl px-4 py-8 text-center text-slate-500 text-sm">لا جوائز في الخزانة بعد.</p>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($trophies as $trophy)
                        <a href="{{ route('competitions.show', $trophy->event->slug) }}" class="glass rounded-2xl p-4 block hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition"
                           aria-label="المركز {{ $trophy->final_rank }} في {{ $trophy->event->title }}">
                            <div class="flex items-center gap-3">
                                <span class="text-3xl" aria-hidden="true">{{ $medals[$trophy->final_rank] }}</span>
                                <div class="min-w-0">
                                    <div class="font-bold text-white truncate">{{ $trophy->event->title }}</div>
                                    <div class="text-xs text-slate-400">المركز {{ $trophy->final_rank }} · {{ $trophy->event->ends_at->format('Y-m-d') }}</div>
                                    @if ($isOwner && $trophy->reward_label)
                                        <div class="text-xs text-gold mt-1">🎁 {{ $trophy->reward_label }}</div>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
                <div class="mt-3">{{ $trophies->links() }}</div>
            @endif
        </section>

        <section class="anim-fade-up" aria-labelledby="history-title">
            <h2 id="history-title" class="font-display font-black text-lg text-white mb-3">آخر المنافسات</h2>
            <div class="glass rounded-2xl divide-y divide-white/5">
                @forelse ($history as $row)
                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <a href="{{ route('competitions.show', $row->event->slug) }}" class="font-bold text-white hover:text-amethyst truncate focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row->event->title }}</a>
                        <span class="text-slate-400 shrink-0">المركز <strong class="text-white">{{ $row->final_rank }}</strong> · {{ $row->event->ends_at->format('Y-m-d') }}</span>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-slate-500 text-sm">لا منافسات معتمَدة بعد.</p>
                @endforelse
            </div>
            <div class="mt-3">{{ $history->links() }}</div>
        </section>
    </div>
@endsection
