@extends('layouts.app')

@section('title', 'تحدّي فرق')

@section('content')
    @php
        $labels = ['pending' => 'بانتظار الرد', 'accepted' => 'جارٍ', 'completed' => 'انتهى', 'declined' => 'مرفوض', 'cancelled' => 'مُلغى', 'expired' => 'منتهي'];
        $teams = [$challenge->challenger, $challenge->opponent];
        $elapsedMs = $run ? max(0, (int) now()->getPreciseTimestamp(3) - $startedMs) : 0;
    @endphp
    <div class="max-w-4xl mx-auto space-y-6">
        <section class="glass rounded-3xl p-6 anim-fade-up" aria-labelledby="match-title">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <span class="chip text-xs">{{ $labels[$status] ?? $status }}</span>
                <a href="{{ route('teams.challenges.index') }}" class="text-xs text-slate-400 hover:text-white">← التحدّيات</a>
            </div>
            <h1 id="match-title" class="font-display font-black text-xl md:text-2xl text-white text-center">
                <a href="{{ route('teams.show', $challenge->challenger) }}" class="hover:text-amethyst">{{ $challenge->challenger->name }}</a>
                <span class="text-slate-500 mx-2">ضد</span>
                <a href="{{ route('teams.show', $challenge->opponent) }}" class="hover:text-amethyst">{{ $challenge->opponent->name }}</a>
            </h1>
            <p class="text-center text-sm text-slate-400 mt-2">الأحجية: {{ $challenge->puzzle->title }}</p>

            @if ($completed)
                <div class="grid grid-cols-2 gap-4 mt-6 text-center">
                    @foreach ($teams as $t)
                        @php $r = $challenge->results->firstWhere('team_id', $t->id); @endphp
                        <div class="rounded-2xl border {{ $challenge->winner_team_id === $t->id ? 'border-gold/60' : 'border-white/10' }} bg-white/5 p-4">
                            <div class="font-bold text-white">{{ $t->name }}</div>
                            <div class="font-display font-black text-3xl {{ $challenge->winner_team_id === $t->id ? 'text-gold' : 'text-white' }}">{{ $r?->score ?? 0 }}</div>
                            <div class="text-xs text-slate-400">{{ $r?->eligible_results_count ?? 0 }} نتيجة محتسَبة · المدة الإجمالية {{ number_format(($r?->total_duration_ms ?? 0) / 1000, 1) }} ث</div>
                        </div>
                    @endforeach
                </div>
                <p class="text-center mt-4 font-bold {{ $challenge->is_draw ? 'text-slate-300' : 'text-gold' }}">{{ $challenge->is_draw ? 'تعادل' : '🏆 فاز فريق '.$challenge->winner->name }}</p>
                <p class="text-center text-xs text-slate-500 mt-1">نقاط الفريق = مجموع أفضل {{ config('teams.ranking_top_n') }} نتائج صحيحة (الصيغة نفسها لكلا الفريقين). عند تساوي النقاط يفوز الأسرع زمنًا إجماليًا، ثم الأكثر نتائج محتسَبة، وإلا فتعادل. لا جوائز اقتصادية.</p>
            @elseif ($challenge->status === 'accepted' && $challenge->play_ends_at)
                <p class="text-center text-sm text-amber-400 mt-4">مهلة اللعب تنتهي {{ $challenge->play_ends_at->format('Y-m-d H:i') }}</p>
            @elseif ($challenge->status === 'pending')
                <p class="text-center text-sm text-slate-400 mt-4">ينتهي عرض التحدّي {{ $challenge->expires_at->format('Y-m-d H:i') }}</p>
            @endif
        </section>

        @if ($run)
            <section class="glass rounded-3xl p-6 md:p-9 anim-fade-up" aria-labelledby="play-title">
                <div class="flex items-center justify-between mb-4">
                    <h2 id="play-title" class="font-display font-black text-lg text-white">محاولتك لفريقك</h2>
                    <span class="chip !py-1 !px-3 text-xs" x-data="{ base: {{ $elapsedMs }}, t0: Date.now(), s: 0 }" x-init="setInterval(() => s = Math.floor((base + Date.now() - t0) / 1000), 500)">⏱ <span x-text="s">0</span> ث <span class="text-slate-500">(للعرض فقط)</span></span>
                </div>
                <h3 class="font-display font-black text-2xl text-white mb-4">{{ $puzzle->prompt }}</h3>
                @if ($puzzle->image_path)
                    <div class="rounded-2xl overflow-hidden border border-white/10 mb-6"><img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto"></div>
                @endif
                <form method="POST" action="{{ route('teams.challenges.submit', $challenge) }}">
                    @csrf
                    @include($renderer)
                    <p class="text-xs text-amber-400 mb-3">محاولة واحدة فقط: تُسجَّل نتيجتك لفريقك ولا تُعاد.</p>
                    <button type="submit" class="btn-gem !py-3 !px-6">أرسل إجابتي</button>
                </form>
            </section>
        @elseif ($canStart)
            <section class="glass rounded-3xl p-6 text-center anim-fade-up">
                <p class="text-white font-bold mb-3">أنت ضمن روستر فريقك. محاولة واحدة، والوقت يُقاس من لحظة البدء.</p>
                <form method="POST" action="{{ route('teams.challenges.start', $challenge) }}">@csrf <button type="submit" class="btn-gem">ابدأ المحاولة</button></form>
            </section>
        @elseif ($seat && $seat->completed_at && ! $completed)
            <p class="glass rounded-2xl px-4 py-4 text-center text-sm text-emerald-400">سُجّلت نتيجتك لفريقك. تظهر النتيجة النهائية عند اكتمال المباراة.</p>
        @endif

        @foreach ($teams as $t)
            @php $roster = $participants->where('team_id', $t->id); $isMine = $roster->contains('user_id', $viewer?->id); @endphp
            <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="roster-{{ $t->id }}">
                <h2 id="roster-{{ $t->id }}" class="font-display font-black text-lg text-white mb-3">روستر {{ $t->name }}
                    <span class="text-xs text-slate-400 font-normal">{{ $challenge->status === 'pending' && ! $roster->count() ? '(يُختار عند القبول)' : ($roster->first()?->locked_at ? '(مقفل)' : '(غير مقفل بعد)') }}</span></h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    @forelse ($roster as $p)
                        <li class="rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-sm flex items-center justify-between gap-2">
                            @if ($p->user->profileLinkable ?? false)
                                <a href="{{ route('players.show', $p->user) }}" class="font-bold text-white truncate hover:text-amethyst">{{ $p->user->name }}</a>
                            @else
                                <span class="font-bold text-white truncate">{{ $p->user->name }}</span>
                            @endif
                            <span class="text-xs text-slate-400 shrink-0">
                                @if ($completed)
                                    {{ $p->is_correct ? $p->score.' نقطة' : ($p->completed_at ? 'خاطئة' : 'لم يلعب') }}
                                @else
                                    {{ $p->completed_at ? 'أنهى' : 'لم يلعب بعد' }}
                                @endif
                            </span>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">لا لاعبين بعد.</li>
                    @endforelse
                </ul>
            </section>
        @endforeach

        @if ($canEditRoster)
            <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="edit-roster">
                <h2 id="edit-roster" class="font-display font-black text-lg text-white mb-3">تعديل روستر فريقكم (قبل القبول)</h2>
                <form method="POST" action="{{ route('teams.challenges.roster', $challenge) }}" class="space-y-3">@csrf @method('PUT')
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($candidates as $m)
                            <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm cursor-pointer"><input type="checkbox" name="roster[]" value="{{ $m->user->public_id }}" @checked(in_array($m->user_id, $selectedIds, true)) class="accent-violet-500"><span class="text-white truncate">{{ $m->user->name }}</span></label>
                        @endforeach
                    </div>
                    <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">حفظ الروستر</button>
                </form>
            </section>
        @endif

        @if ($canAccept)
            <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="accept-title">
                <h2 id="accept-title" class="font-display font-black text-lg text-white mb-1">قبول التحدّي</h2>
                <p class="text-xs text-slate-500 mb-3">اختر روستر فريقكم ({{ config('teams.challenges.roster_min') }}–{{ config('teams.challenges.roster_max') }} لاعبين). بالقبول يُقفل الروستران فلا تبديل ولا إضافة بعده.</p>
                <form method="POST" action="{{ route('teams.challenges.accept', $challenge) }}" class="space-y-3">@csrf
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($candidates as $m)
                            <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm cursor-pointer"><input type="checkbox" name="roster[]" value="{{ $m->user->public_id }}" class="accent-violet-500"><span class="text-white truncate">{{ $m->user->name }}</span></label>
                        @endforeach
                    </div>
                    <button type="submit" class="btn-gem !py-2 !px-6 text-sm">قبول وقفل الروستر</button>
                </form>
                <form method="POST" action="{{ route('teams.challenges.decline', $challenge) }}" class="mt-3">@csrf <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">رفض التحدّي</button></form>
            </section>
        @endif

        @if ($canCancel)
            <form method="POST" action="{{ route('teams.challenges.cancel', $challenge) }}" onsubmit="return confirm('إلغاء التحدّي؟')">@csrf @method('DELETE')
                <button type="submit" class="chip !text-rose focus:outline-none focus-visible:ring-2 focus-visible:ring-rose">إلغاء التحدّي</button>
            </form>
        @endif
    </div>
@endsection
