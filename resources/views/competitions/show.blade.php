@extends('layouts.app')

@section('title', $event->title)

@section('content')
    @php
        $phase = $event->phase();
        $phaseLabel = ['upcoming' => 'قادمة', 'live' => 'مباشرة الآن', 'ended' => 'انتهت (بانتظار اعتماد النتائج)', 'completed' => 'انتهت · النتائج نهائية', 'cancelled' => 'مُلغاة'][$phase] ?? '';
        $cap = (int) ($event->puzzle->time_limit_seconds ?: config('competitive.default_time_cap_seconds', 600));
    @endphp

    <div class="max-w-3xl mx-auto space-y-6">
        <a href="{{ route('competitions.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل المنافسات</a>

        <div class="glass rounded-3xl p-6 md:p-8 anim-fade-up">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="chip !py-1 !px-3 text-xs {{ $phase === 'live' ? '!text-emerald-400' : '' }}">{{ $phaseLabel }}</span>
                @if ($event->is_featured)<span class="text-xs font-black text-gold">⭐ مميزة</span>@endif
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white leading-tight">{{ $event->title }}</h1>
            @if ($event->description)
                <p class="text-slate-300 mt-3 whitespace-pre-line">{{ $event->description }}</p>
            @endif

            <dl class="grid gap-3 sm:grid-cols-2 mt-6 text-sm">
                <div><dt class="text-slate-500">البداية</dt><dd class="text-white font-bold">{{ $event->starts_at->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-slate-500">النهاية</dt><dd class="text-white font-bold">{{ $event->ends_at->format('Y-m-d H:i') }}</dd></div>
                <div><dt class="text-slate-500">المشاركون</dt><dd class="text-white font-bold">{{ $event->participants_count }}@if ($event->max_participants !== null) / {{ $event->max_participants }}@endif</dd></div>
                <div><dt class="text-slate-500">الأحجية</dt><dd class="text-white font-bold">{{ $event->puzzle->title }} <x-difficulty-badge :difficulty="$event->puzzle->difficulty" /></dd></div>
            </dl>

            <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300 space-y-1" aria-label="قواعد المنافسة">
                <p class="font-black text-white text-sm mb-1">القواعد</p>
                <p>• محاولة واحدة لكل مشارك، تبدأ من لحظة ضغطك «ابدأ» ويقيس السيرفر زمنها.</p>
                <p>• لا تلميحات ولا رسوم دخول ولا أي أفضلية مشتراة.</p>
                <p>• النقاط: إجابة خاطئة = 0؛ صحيحة = 1000 + مكافأة سرعة حتى 1000 تتناقص حتى {{ $cap }} ثانية.</p>
                <p>• التعادل يُحسم بالسرعة ثم بوقت الإكمال. الترتيب يحسبه السيرفر ولا يُعتمد أي رقم من المتصفح.</p>
            </div>

            @if ($event->rewardRules->isNotEmpty())
                <section class="mt-6" aria-labelledby="rewards-title">
                    <h2 id="rewards-title" class="font-display font-black text-white text-base mb-2">الجوائز</h2>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach ($event->rewardRules as $rule)
                            <li class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm flex items-center justify-between gap-2">
                                <span class="text-slate-300">{{ $rule->placementLabel() }}</span>
                                <span class="font-bold text-gold">🎁 {{ $rule->rewardLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($myGrant)
                        <p class="text-sm text-emerald-400 mt-3">جائزتك: <strong>{{ $myGrant->reward_label }}</strong> (المركز {{ $myGrant->final_rank }})</p>
                    @endif
                </section>
            @endif

            <div class="mt-6">
                @switch($state)
                    @case('guest')
                        <a href="{{ route('login') }}" class="btn-gem !py-2.5 !px-5 text-sm">سجّل الدخول للمشاركة</a>
                        @break
                    @case('register')
                        <form method="POST" action="{{ route('competitions.register', $event) }}">@csrf
                            <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm" aria-label="التسجيل في المنافسة {{ $event->title }}">سجّل في المنافسة</button>
                        </form>
                        @break
                    @case('registered')
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="chip !text-emerald-400">أنت مسجَّل — تبدأ في {{ $event->starts_at->format('Y-m-d H:i') }}</span>
                            <form method="POST" action="{{ route('competitions.leave', $event) }}">@csrf @method('DELETE')
                                <button type="submit" class="chip text-xs" aria-label="الانسحاب من المنافسة">انسحاب</button>
                            </form>
                        </div>
                        @break
                    @case('play')
                        <form method="POST" action="{{ route('competitions.start', $event) }}">@csrf
                            <button type="submit" class="btn-gem !py-2.5 !px-5 text-sm" aria-label="بدء المحاولة الوحيدة">ابدأ المحاولة (لا يمكن إعادتها)</button>
                        </form>
                        @break
                    @case('resume')
                        <a href="{{ route('competitions.play', $event) }}" class="btn-gem !py-2.5 !px-5 text-sm">تابع محاولتك الجارية</a>
                        @break
                    @case('completed')
                        <span class="chip !text-emerald-400">سُجّلت نتيجتك</span>
                        @break
                    @case('full')
                        <span class="chip !text-rose-400">اكتمل عدد المشاركين</span>
                        @break
                    @case('registration_closed')
                        <span class="chip">التسجيل غير متاح الآن</span>
                        @break
                    @case('cancelled')
                        <span class="chip !text-rose-400">أُلغيت هذه المنافسة</span>
                        @break
                    @default
                        <span class="chip">انتهت المنافسة</span>
                @endswitch
            </div>
        </div>

        @if ($hasTeams)
            <nav class="flex gap-2 mb-3" aria-label="عرض الترتيب">
                <a href="{{ route('competitions.show', $event) }}" class="chip {{ $tab === 'players' ? '!border-amethyst !text-white' : '' }}" @if ($tab === 'players') aria-current="page" @endif>اللاعبون</a>
                <a href="{{ route('competitions.show', [$event, 'tab' => 'teams']) }}" class="chip {{ $tab === 'teams' ? '!border-amethyst !text-white' : '' }}" @if ($tab === 'teams') aria-current="page" @endif>الفرق</a>
            </nav>
        @endif

        @if ($tab === 'teams')
            @include('competitions._teams-standings', ['standings' => $teamStandings])
        @else
            @include('competitions._leaderboard', ['board' => $board, 'event' => $event, 'scope' => $scope])
        @endif
    </div>
@endsection
