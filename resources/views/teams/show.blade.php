@extends('layouts.app')

@section('title', 'فريق '.$team->name)

@section('content')
    @php
        $roleLabels = ['owner' => 'مالك', 'admin' => 'مشرف', 'member' => 'عضو'];
        $joinLabels = ['open' => 'انضمام مفتوح', 'request' => 'بطلب انضمام', 'invite_only' => 'بدعوة فقط'];
    @endphp
    <div class="max-w-4xl mx-auto space-y-6">
        <section class="glass rounded-3xl p-6 anim-fade-up" aria-labelledby="team-title">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0">
                    @include('teams._avatar', ['team' => $team, 'size' => 'h-16 w-16 text-2xl'])
                    <div class="min-w-0">
                        <h1 id="team-title" class="font-display font-black text-2xl text-white truncate">{{ $team->name }}</h1>
                        <div class="flex flex-wrap gap-2 mt-2 text-xs">
                            <span class="chip">{{ $team->isPublic() ? 'فريق عام' : 'فريق خاص' }}</span>
                            <span class="chip">{{ $joinLabels[$team->join_policy] ?? '' }}</span>
                            <span class="chip">{{ $team->members_count }} / {{ $team->capacity() }} عضو</span>
                            @unless ($team->is_active) <span class="chip !text-rose">غير مفعَّل</span> @endunless
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($state === 'member')
                        <a href="{{ route('teams.chat', $team) }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="chat" class="w-4 h-4" /> دردشة الفريق</a>
                    @endif
                    @if ($canChallenge)
                        <a href="{{ route('teams.challenges.create', ['opponent' => $team->slug]) }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">⚔️ تحدَّ هذا الفريق</a>
                    @endif
                    @switch($state)
                        @case('guest')
                            <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">سجّل الدخول للانضمام</a>
                            @break
                        @case('member')
                            @if (in_array($myRole, ['owner', 'admin'], true))
                                <a href="{{ route('teams.manage', $team) }}" class="btn-gem !py-2 !px-5 text-sm">إدارة الفريق</a>
                            @endif
                            @if ($myRole !== 'owner')
                                <form method="POST" action="{{ route('teams.leave', $team) }}" onsubmit="return confirm('مغادرة الفريق؟')">@csrf @method('DELETE')
                                    <button type="submit" class="chip !text-rose focus:outline-none focus-visible:ring-2 focus-visible:ring-rose">مغادرة الفريق</button>
                                </form>
                            @endif
                            @break
                        @case('open')
                            <form method="POST" action="{{ route('teams.join', $team) }}">@csrf <button type="submit" class="btn-gem !py-2 !px-5 text-sm">انضم الآن</button></form>
                            @break
                        @case('request')
                            <form method="POST" action="{{ route('teams.requests.store', $team) }}">@csrf <button type="submit" class="btn-gem !py-2 !px-5 text-sm">طلب انضمام</button></form>
                            @break
                        @case('requested')
                            <form method="POST" action="{{ route('teams.requests.cancel', $team) }}">@csrf @method('DELETE') <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلغاء طلب الانضمام</button></form>
                            @break
                        @case('invited')
                            <a href="{{ route('teams.invitations') }}" class="btn-gem !py-2 !px-5 text-sm">لديك دعوة لهذا الفريق</a>
                            @break
                        @case('invite_only')
                            <span class="text-sm text-slate-400">الانضمام بدعوة فقط</span>
                            @break
                        @case('full')
                            <span class="text-sm text-slate-400">اكتمل عدد الأعضاء</span>
                            @break
                        @case('in_other_team')
                            <span class="text-sm text-slate-400">أنت عضو في فريق آخر</span>
                            @break
                        @case('inactive')
                            <span class="text-sm text-slate-400">هذا الفريق غير مفعَّل</span>
                            @break
                    @endswitch
                </div>
            </div>

            @if ($team->description)
                <p class="text-sm text-slate-300 mt-4 whitespace-pre-line">{{ $team->description }}</p>
            @endif
        </section>

        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="stats-title">
            <h2 id="stats-title" class="font-display font-black text-lg text-white mb-3">أداء الفريق بالمنافسات</h2>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                <div><dd class="font-display font-black text-2xl text-gold">{{ $stats['wins'] }}</dd><dt class="text-xs text-slate-400">فوز</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['top3'] }}</dd><dt class="text-xs text-slate-400">ضمن الثلاثة الأوائل</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['best_rank'] ?? '—' }}</dd><dt class="text-xs text-slate-400">أفضل مركز</dt></div>
                <div><dd class="font-display font-black text-2xl text-white">{{ $stats['events'] }}</dd><dt class="text-xs text-slate-400">منافسات</dt></div>
            </dl>
            @if ($stats['avg_rank'] !== null)
                <p class="text-xs text-slate-500 mt-3 text-center">متوسط المركز: {{ $stats['avg_rank'] }}</p>
            @endif
            @if ($recent->isNotEmpty())
                <ul class="mt-4 divide-y divide-white/5 text-sm">
                    @foreach ($recent as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <a href="{{ route('competitions.show', $row->event->slug) }}" class="font-bold text-white hover:text-amethyst truncate focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row->event->title }}</a>
                            <span class="text-slate-400 shrink-0">المركز <strong class="text-white">{{ $row->rank }}</strong> · {{ $row->score }} نقطة</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @include('teams._glory')

        <section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="roster-title">
            <h2 id="roster-title" class="font-display font-black text-lg text-white mb-3">الأعضاء</h2>
            @if (! $showRoster)
                <p class="text-sm text-slate-500">قائمة أعضاء هذا الفريق خاصة.</p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($roster as $m)
                        <li class="rounded-2xl border border-white/10 bg-white/5 p-3 flex items-center gap-3">
                            <x-player-avatar :avatar="$m->user->identityAvatar ?? null" :frame="$m->user->identityFrame ?? null" :name="$m->user->name" size="md" />
                            @if ($m->user->profileLinkable ?? false)
                                <a href="{{ route('players.show', $m->user) }}" class="font-bold text-white truncate hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $m->user->name }}</a>
                            @else
                                <span class="font-bold text-white truncate">{{ $m->user->name }}</span>
                            @endif
                            <span class="ms-auto text-xs {{ $m->role === 'owner' ? 'text-gold' : 'text-slate-400' }}">{{ $roleLabels[$m->role] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
