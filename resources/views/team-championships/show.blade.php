@extends('layouts.app')

@section('title', $championship->title)

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        <section class="glass rounded-3xl p-6 anim-fade-up" aria-labelledby="champ-title">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <span class="chip text-xs">{{ $championship->status === 'completed' ? 'منتهية ومعتمَدة' : ['upcoming' => 'قادمة', 'live' => 'جارية', 'ended' => 'انتهت: بانتظار الاعتماد'][$championship->phase()] }}</span>
                <a href="{{ route('team-championships.index') }}" class="text-xs text-slate-400 hover:text-white">← البطولات</a>
            </div>
            <h1 id="champ-title" class="font-display font-black text-2xl text-white">{{ $championship->title }}</h1>
            <p class="text-sm text-slate-400 mt-1">{{ $championship->starts_at->format('Y-m-d') }} → {{ $championship->ends_at->format('Y-m-d') }}</p>
            @if ($championship->description)<p class="text-sm text-slate-300 mt-3 whitespace-pre-line">{{ $championship->description }}</p>@endif
            @if ($championship->champion)
                <p class="mt-4 text-lg font-bold text-gold">🏆 البطل: <a href="{{ route('teams.show', $championship->champion) }}" class="hover:underline">{{ $championship->champion->name }}</a></p>
            @endif
        </section>

        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="board-title">
            <h2 id="board-title" class="font-display font-black text-lg text-white">{{ $board['final'] ? 'الترتيب النهائي' : 'الترتيب المؤقت (غير نهائي)' }}</h2>
            <p class="text-xs text-slate-500 mt-1 mb-3">نقاط كل حدث معتمَد بحسب مركز الفريق ({{ collect($points)->map(fn ($p, $r) => "المركز {$r} = {$p}")->implode('، ') }}). لا جمع لدرجات خام.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">ترتيب البطولة</caption>
                    <thead class="text-xs text-slate-400"><tr><th scope="col" class="px-3 py-2 text-start">#</th><th scope="col" class="px-3 py-2 text-start">الفريق</th><th scope="col" class="px-3 py-2">النقاط</th><th scope="col" class="px-3 py-2">انتصارات</th><th scope="col" class="px-3 py-2">ضمن الثلاثة</th><th scope="col" class="px-3 py-2">أحداث</th></tr></thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($board['rows'] as $row)
                            <tr>
                                <td class="px-3 py-2 text-slate-400">{{ $row->rank }}</td>
                                <td class="px-3 py-2"><a href="{{ route('teams.show', $row->team) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row->team->name }}</a></td>
                                <td class="px-3 py-2 text-center text-gold font-bold">{{ $row->points }}</td>
                                <td class="px-3 py-2 text-center">{{ $row->event_wins }}</td>
                                <td class="px-3 py-2 text-center">{{ $row->top3_count }}</td>
                                <td class="px-3 py-2 text-center">{{ $row->events_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">لا أحداث معتمَدة تمنح نقاطًا بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="events-title">
            <h2 id="events-title" class="font-display font-black text-lg text-white mb-3">أحداث البطولة</h2>
            <ul class="divide-y divide-white/5 text-sm">
                @foreach ($events as $event)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <a href="{{ route('competitions.show', $event) }}" class="font-bold text-white hover:text-amethyst truncate focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $event->title }}</a>
                        <span class="text-xs text-slate-400 shrink-0">{{ $event->status === 'cancelled' ? 'ملغى: بلا نقاط' : ($event->team_rankings_finalized_at ? 'معتمَد' : 'لم يُعتمد بعد') }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
