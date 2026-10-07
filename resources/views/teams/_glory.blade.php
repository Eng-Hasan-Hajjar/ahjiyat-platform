{{-- E20: مجد الفريق (مشتق من النتائج المخزَّنة النهائية). تعرُّف فقط: لا جوائز اقتصادية. --}}
<section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="glory-title">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 id="glory-title" class="font-display font-black text-lg text-white">مجد الفريق</h2>
        <a href="{{ route('team-championships.index') }}" class="text-xs text-slate-400 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">بطولات الفرق ←</a>
    </div>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
        <div><dd class="font-display font-black text-2xl text-gold">{{ $glory['championships']['won'] }}</dd><dt class="text-xs text-slate-400">بطولات</dt></div>
        <div><dd class="font-display font-black text-2xl text-white">{{ $glory['championships']['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة بالبطولات</dt></div>
        <div><dd class="font-display font-black text-2xl text-white">{{ $glory['events']['wins'] }}</dd><dt class="text-xs text-slate-400">انتصارات أحداث</dt></div>
        <div><dd class="font-display font-black text-2xl text-white">{{ $glory['events']['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة بالأحداث</dt></div>
    </dl>
    <dl class="grid grid-cols-4 gap-2 text-center mt-4 pt-4 border-t border-white/5">
        <div><dd class="font-bold text-white">{{ $glory['challenges']['played'] }}</dd><dt class="text-xs text-slate-400">مباريات</dt></div>
        <div><dd class="font-bold text-emerald-400">{{ $glory['challenges']['won'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
        <div><dd class="font-bold text-rose-400">{{ $glory['challenges']['lost'] }}</dd><dt class="text-xs text-slate-400">خسارة</dt></div>
        <div><dd class="font-bold text-slate-300">{{ $glory['challenges']['drawn'] }}</dd><dt class="text-xs text-slate-400">تعادل</dt></div>
    </dl>
    @if ($trophies['championships_won']->isNotEmpty() || $trophies['championship_top3']->isNotEmpty())
        <ul class="mt-4 space-y-1 text-sm">
            @foreach ($trophies['championships_won'] as $t)
                <li class="flex justify-between gap-2"><a href="{{ route('team-championships.show', $t->slug) }}" class="font-bold text-gold hover:underline">🏆 بطل «{{ $t->title }}»</a><span class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($t->finalized_at)->format('Y-m-d') }}</span></li>
            @endforeach
            @foreach ($trophies['championship_top3'] as $t)
                <li class="flex justify-between gap-2"><a href="{{ route('team-championships.show', $t->slug) }}" class="text-white hover:underline">{{ $t->rank === 2 ? '🥈' : '🥉' }} المركز {{ $t->rank }} في «{{ $t->title }}»</a><span class="text-xs text-slate-500">{{ \Illuminate\Support\Carbon::parse($t->finalized_at)->format('Y-m-d') }}</span></li>
            @endforeach
        </ul>
    @endif
    @if ($matches->isNotEmpty())
        <ul class="mt-4 divide-y divide-white/5 text-sm">
            @foreach ($matches as $m)
                <li class="flex items-center justify-between gap-3 py-2">
                    <a href="{{ route('teams.challenges.show', $m->challenge) }}" class="text-white hover:text-amethyst truncate focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">ضد {{ $m->opponent->name }}</a>
                    <span class="shrink-0 text-xs {{ $m->outcome === 'win' ? 'text-emerald-400' : ($m->outcome === 'loss' ? 'text-rose-400' : 'text-slate-300') }}">{{ ['win' => 'فوز', 'loss' => 'خسارة', 'draw' => 'تعادل'][$m->outcome] }} · {{ $m->score }} - {{ $m->their_score }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
