@extends('layouts.app')

@section('title', 'الداعمون والرعاة')
@section('meta_description', 'الداعمون والرعاة الذين يساندون أحجيات.')
@section('canonical', route('supporters.index'))
@section('content_width', 'max-w-5xl')

{{--
    E30: لوحة الداعمين العامة (قراءة فقط). المجهولون «داعم مجهول» بلا اسم/شعار/رابط/مستوى. المبالغ الدقيقة لا تظهر إلا للوحة برنامج فعّل ذلك؛ غير ذلك المستوى فقط.
    كل النصوص مهرّبة ({{ }})، والروابط الخارجية (إن وُجدت) تُعرض فقط من صفحة الداعم وبـ rel آمن.
--}}
@section('content')
    @php
        $scopeLabels = ['all' => 'كل الوقت', 'year' => 'هذا العام', 'season' => 'الموسم الحالي'];
    @endphp

    <div class="space-y-8">
        <x-page-header title="الداعمون والرعاة" icon="gift" subtitle="شكرًا لكل من يدعم المنصة ويموّل مكافآتها وفعالياتها. الدعم طوعي ولا يمنح أي أفضلية في اللعب." class="anim-fade-up !mb-0" />

        <x-sponsor-strip type="platform" />

        <section aria-labelledby="board-title" class="space-y-4 anim-fade-up">
            <x-section-header title="لوحة الداعمين" />
            <h2 id="board-title" class="sr-only">لوحة الداعمين</h2>

            <nav aria-label="نطاق اللوحة" class="flex flex-wrap gap-2">
                @foreach ($scopeLabels as $key => $label)
                    <a href="{{ route('supporters.index', ['scope' => $key]) }}" @if ($scope === $key) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $scope === $key ? 'chip-active' : '' }}">{{ $label }}</a>
                @endforeach
                @foreach ($programs as $p)
                    <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" @if ($program && $program->id === $p->id) aria-current="page" @endif class="chip !py-1.5 !px-3 text-xs {{ $program && $program->id === $p->id ? 'chip-active' : '' }}">{{ $p->title }}</a>
                @endforeach
            </nav>

            @if ($leaderboard['entries'] === [])
                <x-empty-state icon="gift" title="لا داعمين في هذا النطاق بعد" message="ستظهر هنا أسماء الداعمين بعد تأكيد مساهماتهم." />
            @else
                <ol class="grid gap-2" aria-label="ترتيب الداعمين">
                    @foreach ($leaderboard['entries'] as $entry)
                        <li class="glass rounded-2xl px-4 py-3 flex items-center gap-3" data-rank="{{ $entry['rank'] }}" @if ($entry['anonymous']) data-anonymous @endif>
                            <span class="grid place-items-center w-9 h-9 rounded-xl bg-amethyst/15 text-amethyst font-black shrink-0">{{ $entry['rank'] }}</span>
                            <div class="min-w-0 flex-1">
                                @if ($entry['anonymous'])
                                    <span class="font-bold text-slate-300">داعم مجهول</span>
                                @else
                                    <a href="{{ route('supporters.show', $entry['supporter']->slug) }}" class="font-display font-black text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $entry['supporter']->display_name }}</a>
                                    <span class="ms-2">@include('supporters._tier', ['tier' => $entry['tier']])</span>
                                @endif
                            </div>
                            @if ($leaderboard['show_amounts'] && $entry['total_minor'] !== null && $program)
                                <span class="font-black text-gold shrink-0">{{ \App\Services\Sponsorship\SponsorshipMoney::format($entry['total_minor'], $program->accounting_currency) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        @if ($programs->isNotEmpty())
            <section aria-labelledby="programs-title" class="anim-fade-up">
                <x-section-header title="برامج الرعاية" />
                <h2 id="programs-title" class="sr-only">برامج الرعاية</h2>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach ($programs as $p)
                        @php($raised = $funding[$p->id] ?? 0)
                        <article class="glass rounded-2xl p-4 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-display font-black text-white break-words">{{ $p->title }}</h3>
                                <x-status-badge :tone="$p->status === 'active' ? 'success' : 'neutral'" small>{{ $p->status === 'active' ? 'نشط' : 'مغلق' }}</x-status-badge>
                            </div>
                            <p class="text-xs text-slate-400">{{ $targets->typeLabel($p->target_type) }}</p>
                            @if ($p->show_goal_progress && (int) $p->goal_amount_minor > 0)
                                @php($pct = (int) min(100, intdiv($raised * 100, (int) $p->goal_amount_minor)))
                                <div>
                                    <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1">
                                        <span>تقدّم الهدف</span>
                                        <span class="text-gold">{{ $pct }}٪</span>
                                    </div>
                                    <div class="h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}">
                                        <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $pct }}%"></div>
                                    </div>
                                    @if ($p->show_public_amounts)
                                        <p class="text-xs text-slate-400 mt-1">{{ \App\Services\Sponsorship\SponsorshipMoney::format($raised, $p->accounting_currency) }} من {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->goal_amount_minor, $p->accounting_currency) }}</p>
                                    @endif
                                </div>
                            @endif
                            <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" class="text-xs font-bold text-amethyst hover:underline">داعمو البرنامج</a>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($organizations !== [])
            <section aria-labelledby="orgs-title" class="anim-fade-up">
                <x-section-header title="المؤسسات الداعمة" />
                <h2 id="orgs-title" class="sr-only">المؤسسات الداعمة</h2>
                <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($organizations as $org)
                        <li class="glass rounded-2xl p-4 flex items-center gap-3">
                            <a href="{{ route('supporters.show', $org['supporter']->slug) }}" class="font-bold text-white hover:underline break-words min-w-0 flex-1 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $org['supporter']->display_name }}</a>
                            @include('supporters._tier', ['tier' => $org['tier']])
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($recent !== [])
            <section aria-labelledby="recent-title" class="anim-fade-up">
                <x-section-header title="أحدث الداعمين" />
                <h2 id="recent-title" class="sr-only">أحدث الداعمين</h2>
                <ul class="grid gap-2">
                    @foreach ($recent as $row)
                        <li class="glass rounded-xl px-4 py-2.5 flex items-center justify-between gap-3 text-sm">
                            @if ($row['anonymous'])
                                <span class="text-slate-300">داعم مجهول</span>
                            @else
                                <a href="{{ route('supporters.show', $row['supporter']->slug) }}" class="font-bold text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $row['supporter']->display_name }}</a>
                            @endif
                            @if ($row['at'])<time datetime="{{ $row['at']->toIso8601String() }}" class="text-xs text-slate-500">{{ $row['at']->format('Y-m-d') }}</time>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($tiers->isNotEmpty())
            <section aria-labelledby="tiers-title" class="anim-fade-up">
                <x-section-header title="مستويات الدعم" />
                <h2 id="tiers-title" class="sr-only">مستويات الدعم</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($tiers as $tier)
                        <li>@include('supporters._tier', ['tier' => $tier])</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <p class="text-xs text-slate-500 anim-fade-up">
            الدعم هبة طوعية تموّل المكافآت والفعاليات داخل المنصة. لا يمنح الداعم مقابلًا ماليًا أو عائدًا، ولا يمنحه أفضلية في الأحجيات أو المنافسات. المكافآت المعزَّزة بالرعاية جواهر داخلية للمحتوى الرسمي فقط، وليست قابلة للسحب نقدًا.
        </p>
    </div>
@endsection
