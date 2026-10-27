{{--
    E42 - مركز الموسم (عرض فقط). كل ما هنا مقروء من SeasonHubService: لا كتابة ولا منح ولا اقتصاد. النصوص الحرة (إعلانات/أسماء مراحل/وصف) نص عادي تُهرَّب بـ{{ }}؛
    الألوان تأتي من جدول ثابت بالخادم (SeasonTheme) لا من مدخل حر. الأقسام الفارغة لا تُرسم (موسم بلا بيانات E42 يبدو كما كان).
    المتغيرات: $hubData (مصفوفة المركز) · $season.
--}}
@php
    $tone = fn (string $state) => match ($state) {
        'current', 'live', 'مباشر', 'جارٍ الآن' => 'success',
        'upcoming' => 'warning',
        default => 'neutral',
    };
    $when = fn ($d) => $d ? $d->translatedFormat('j F') : null;
@endphp

@if ($hubData['next_action'])
    <section class="puzzle-card !p-5 md:!p-6 anim-fade-up border-amethyst/40" aria-labelledby="season-next-action">
        <p class="text-xs font-black text-amethyst mb-1">خطوتك التالية في الموسم</p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 id="season-next-action" class="font-display font-black text-lg text-white break-words">{{ $hubData['next_action']['label'] }}</h2>
                <p class="text-sm text-slate-400 break-words">{{ $hubData['next_action']['reason'] }}</p>
            </div>
            @if ($hubData['next_action']['url'])
                <a href="{{ $hubData['next_action']['url'] }}" class="btn-gem !py-2.5 !px-5 shrink-0">انتقل ←</a>
            @endif
        </div>
    </section>
@endif

@if ($hubData['phases'] !== [])
    <section id="phases" class="puzzle-card !p-6 md:!p-8 anim-fade-up" aria-labelledby="season-phases-title">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-5">
            <h2 id="season-phases-title" class="font-display font-black text-xl text-white">مراحل الموسم</h2>
            @if ($hubData['phase_summary'])
                <x-status-badge tone="primary">{{ $hubData['phase_summary'] }}</x-status-badge>
            @endif
        </div>
        <ol class="grid gap-4 md:grid-cols-2">
            @foreach ($hubData['phases'] as $phase)
                <li class="rounded-2xl border border-white/10 bg-white/5 p-4 {{ $phase['state'] === 'ended' ? 'opacity-70' : '' }}"
                    @if ($phase['color']) style="border-inline-start: 4px solid {{ $phase['color'] }}" @endif
                    @if ($phase['state'] === 'current') aria-current="step" @endif>
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <span class="chip !py-0.5 !px-2 text-[11px]">المرحلة {{ $phase['position'] }}</span>
                        <x-status-badge :tone="$tone($phase['state'])" small>{{ $phase['label'] }}</x-status-badge>
                        @if ($phase['is_featured'])
                            <span class="chip !py-0.5 !px-2 text-[11px] !text-gold">مميّزة</span>
                        @endif
                    </div>
                    <h3 class="font-black text-white break-words">{{ $phase['name'] }}</h3>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $when($phase['starts_at']) }} — {{ $when($phase['ends_at']) }}</p>
                    @if ($phase['days_left'] !== null)
                        <p class="text-xs font-bold text-gold mt-1">{{ $phase['days_left'] >= 1 ? 'متبقٍّ '.$phase['days_left'].' يوم' : 'تنتهي اليوم' }}</p>
                    @endif
                    @if ($phase['description'])
                        <p class="text-sm text-slate-300 mt-2 whitespace-pre-line break-words">{{ $phase['description'] }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif

@if ($hubData['announcements'] !== [])
    <section class="puzzle-card !p-6 md:!p-8 anim-fade-up" aria-labelledby="season-announcements-title">
        <h2 id="season-announcements-title" class="font-display font-black text-xl text-white mb-4">إعلانات الموسم</h2>
        <ul class="space-y-4">
            @foreach ($hubData['announcements'] as $a)
                <li class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <h3 class="font-black text-white break-words min-w-0">{{ $a['title'] }}</h3>
                        @if ($a['is_featured'])
                            <span class="chip !py-0.5 !px-2 text-[11px] !text-gold">مُثبَّت</span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-500 mb-2">{{ $a['published_at']->translatedFormat('j F Y') }}</p>
                    <p class="text-sm text-slate-300 whitespace-pre-line break-words">{{ $a['body'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>
@endif

@if ($hubData['has_extras'])
    <section class="space-y-5 anim-fade-up" aria-labelledby="season-extras-title">
        <h2 id="season-extras-title" class="font-display font-black text-xl text-white">في هذا الموسم</h2>

        @if ($hubData['pass'])
            @php $pass = $hubData['pass']; @endphp
            <div class="puzzle-card !p-5">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <x-ui-icon name="gift" class="w-5 h-5 text-gold" />
                    <h3 class="font-black text-white break-words min-w-0">{{ $pass['name'] }}</h3>
                    <x-status-badge :tone="$pass['active'] ? 'success' : 'neutral'" small>{{ $pass['status_label'] }}</x-status-badge>
                </div>
                @if ($pass['subtitle'])
                    <p class="text-sm text-slate-400 break-words">{{ $pass['subtitle'] }}</p>
                @endif
                @if ($pass['user'] && $pass['user']['joined'])
                    <p class="text-sm text-slate-300 mt-2">الشريحة {{ $pass['user']['tier'] }} من {{ $pass['user']['max_tier'] }} · {{ $pass['user']['points'] }} نقطة@if ($pass['user']['next_tier_points']) · الشريحة التالية عند {{ $pass['user']['next_tier_points'] }}@endif</p>
                @endif
                <a href="{{ $pass['url'] }}" class="inline-flex items-center gap-1 mt-3 text-sm font-bold text-amethyst hover:underline">
                    {{ $pass['user'] === null ? 'سجّل الدخول لفتح المسار' : ($pass['active'] ? 'افتح المسار' : 'استعرض المسار') }} <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
                </a>
            </div>
        @endif

        @if ($hubData['campaigns'] !== [])
            <div>
                <h3 class="text-sm font-black text-slate-400 mb-2">حملات إضافية</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($hubData['campaigns'] as $c)
                        <a href="{{ $c['url'] }}" class="puzzle-card !p-4 hover:border-amethyst/40 transition motion-reduce:transition-none block focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="font-black text-white break-words min-w-0">{{ $c['title'] }}</span>
                                <x-status-badge :tone="$c['available'] ? 'success' : 'warning'" small>{{ $c['availability'] }}</x-status-badge>
                                @if ($c['user_state'] === 'completed')<x-status-badge tone="success" small icon="check-circle">مكتملة</x-status-badge>
                                @elseif ($c['user_state'] === 'in_progress')<x-status-badge tone="primary" small>قيد التقدّم</x-status-badge>@endif
                            </div>
                            @if ($c['phase'])<p class="text-xs text-slate-500">ضمن مرحلة: {{ $c['phase'] }}</p>@endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($hubData['competitions'] !== [])
            <div>
                <h3 class="text-sm font-black text-slate-400 mb-2">منافسات الموسم</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($hubData['competitions'] as $e)
                        <a href="{{ $e['url'] }}" class="puzzle-card !p-4 hover:border-amethyst/40 transition motion-reduce:transition-none block focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <x-ui-icon name="trophy" class="w-4 h-4 text-gold" />
                                <span class="font-black text-white break-words min-w-0">{{ $e['title'] }}</span>
                                <x-status-badge :tone="$e['phase'] === 'live' ? 'success' : ($e['phase'] === 'upcoming' ? 'warning' : 'neutral')" small>{{ $e['label'] }}</x-status-badge>
                                @if ($e['registered'] === true)<x-status-badge tone="primary" small>أنت مسجّل</x-status-badge>
                                @elseif ($e['registration_open'] && ! $e['is_full'])<x-status-badge tone="warning" small>التسجيل مفتوح</x-status-badge>@endif
                            </div>
                            <p class="text-xs text-slate-500">{{ $when($e['starts_at']) }} — {{ $when($e['ends_at']) }}@if ($e['season_phase']) · ضمن مرحلة: {{ $e['season_phase'] }}@endif</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($hubData['missions'] !== [])
            <div>
                <h3 class="text-sm font-black text-slate-400 mb-2">مهام موسمية</h3>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($hubData['missions'] as $m)
                        <div class="puzzle-card !p-4">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <x-ui-icon name="quests" class="w-4 h-4 text-amethyst" />
                                <span class="font-black text-white break-words min-w-0">{{ $m['title'] }}</span>
                                <span class="chip !py-0.5 !px-2 text-[11px]">{{ $m['period'] }}</span>
                            </div>
                            @if ($m['description'])<p class="text-xs text-slate-400 mb-2 break-words">{{ $m['description'] }}</p>@endif
                            @auth
                                @forelse ($m['assigned'] as $q)
                                    <div class="flex items-center justify-between gap-2 text-sm text-slate-300 py-1">
                                        <span class="break-words min-w-0">{{ $q['name'] }}</span>
                                        <span class="text-xs font-bold {{ $q['completed'] ? 'text-emerald' : 'text-slate-400' }} shrink-0">{{ $q['completed'] ? 'مكتملة' : $q['current'].' / '.$q['target'] }}</span>
                                    </div>
                                @empty
                                    <p class="text-xs text-slate-500">ستظهر مهامك هنا فور إسنادها لك.</p>
                                @endforelse
                                <a href="{{ route('missions.index') }}" class="inline-flex items-center gap-1 mt-2 text-sm font-bold text-amethyst hover:underline">مركز المهام <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" /></a>
                            @else
                                <p class="text-xs text-slate-500">سجّل الدخول لترى مهامك.</p>
                            @endauth
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endif

@if ($hubData['indicators'] !== [])
    <section class="puzzle-card !p-6 anim-fade-up" aria-labelledby="season-indicators-title">
        <h2 id="season-indicators-title" class="font-display font-black text-xl text-white mb-3">تقدّمك في الموسم</h2>
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach ($hubData['indicators'] as $i)
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                    <dt class="text-xs text-slate-400">{{ $i['label'] }}</dt>
                    <dd class="font-black text-white">{{ $i['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif

@if ($hubData['calendar']->isNotEmpty())
    <section class="puzzle-card !p-6 anim-fade-up" aria-labelledby="season-calendar-title">
        <h2 id="season-calendar-title" class="font-display font-black text-xl text-white mb-3">الجدول الزمني</h2>
        <ul class="divide-y divide-white/5">
            @foreach ($hubData['calendar'] as $item)
                <li>
                    <a href="{{ $item->url }}" class="flex flex-wrap items-center justify-between gap-2 py-2.5 hover:text-white text-slate-300 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="chip !py-0.5 !px-2 text-[11px] shrink-0">{{ $item->badge }}</span>
                            <span class="font-bold break-words min-w-0">{{ $item->title }}</span>
                        </span>
                        <span class="text-xs text-slate-400 shrink-0">{{ $item->statusLabel() }}@if ($item->countdownTo()) · {{ $item->countdownTo()->translatedFormat('j F') }}@endif</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
