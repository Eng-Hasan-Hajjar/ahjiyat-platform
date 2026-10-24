@extends('layouts.app')

@php
    /** @var \App\Models\AhjiyatPass $pass */
    $accent = \App\Services\AhjiyatPass\PassTheme::accent($pass);
    $background = \App\Services\AhjiyatPass\PassTheme::background($pass);
    $banner = $pass->bannerUrl();
    $archive = $archive ?? false;
    $difficultyLabels = \App\Models\AhjiyatPassMission::DIFFICULTIES;
    $periodLabels = ['daily' => 'يومية', 'weekly' => 'أسبوعية'];
@endphp

@section('title', $pass->name)
@section('meta_description', 'تقدّم في '.$pass->name.' واستلم مكافآت تجميلية. لا تأثير على الترتيب أو نتائج المنافسات.')

@section('content')
    <x-page-header :title="$pass->name" :subtitle="$pass->subtitle" icon="sparkles" class="anim-fade-up" :back="$archive ? route('pass.archive') : null" back-label="أرشيف المسارات">
        <x-slot:actions>
            @if (! $archive)
                <a href="{{ route('pass.archive') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="calendar" class="w-4 h-4" />الأرشيف</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($frozen)
        <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-bold mb-4" role="alert">حسابك مقيَّد حاليًا؛ لا يمكن كسب النقاط أو استلام المكافآت.</div>
    @endif

    {{-- الترويسة: التقدّم الحالي --}}
    <section class="rounded-3xl border border-white/10 overflow-hidden mb-6 anim-fade-up d-1" style="--pass-accent: {{ $accent }}; background: {{ $background }}" data-pass-hero>
        @if ($banner)
            <img src="{{ $banner }}" alt="" class="w-full h-32 sm:h-44 object-cover" loading="lazy">
        @endif
        <div class="p-5 sm:p-6">
            @if ($pass->tagline())
                <p class="text-sm text-slate-200 mb-3">{{ $pass->tagline() }}</p>
            @endif

            <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
                <div>
                    <p class="text-xs text-slate-300">شريحتك الحالية</p>
                    <p class="font-display font-black text-3xl text-white" data-pass-tier>{{ $tier }} <span class="text-base text-slate-400 font-bold">/ {{ $max_tier }}</span></p>
                </div>
                <div class="text-end">
                    <p class="text-xs text-slate-300">نقاط المسار</p>
                    <p class="font-display font-black text-2xl" style="color: var(--pass-accent)" data-pass-points>{{ number_format($points) }}</p>
                </div>
            </div>

            <div class="w-full h-2.5 rounded-full bg-black/30 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($ratio * 100) }}" aria-label="التقدّم نحو الشريحة التالية">
                <div class="h-full rounded-full" style="width: {{ (int) round($ratio * 100) }}%; background: var(--pass-accent)"></div>
            </div>
            <p class="mt-2 text-xs text-slate-300">
                @if ($next_tier)
                    متبقٍ {{ number_format($next_remaining) }} نقطة للشريحة {{ $next_tier['number'] }}.
                @else
                    بلغتَ آخر شريحة في هذا المسار.
                @endif
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                @if ($has_premium)
                    <span class="chip inline-flex items-center gap-1.5 text-gold"><x-ui-icon name="sparkles" class="w-4 h-4" />المسار المميز مفعّل</span>
                @elseif ($premium['enabled'] && $premium['sales_open'] && ! $archive && ! $frozen)
                    <a href="{{ route('pass.premium') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-pass-premium-link><x-ui-icon name="sparkles" class="w-4 h-4" />تعرّف على المسار المميز</a>
                @endif

                @if ($claim_open && $claimable_count > 0)
                    <form method="POST" action="{{ route('pass.collect_all') }}" class="ms-auto">
                        @csrf
                        <input type="hidden" name="pass" value="{{ $pass->public_id }}">
                        <button type="submit" class="btn-gem !py-2 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-pass-collect-all>استلام الكل ({{ $claimable_count }})</button>
                    </form>
                @endif
            </div>

            <p class="mt-4 text-xs text-slate-300">
                @if ($state === 'active')
                    ينتهي المسار بعد {{ $days_left }} يوم @if ($claim_days_left > 0) · مهلة الاستلام بعدها {{ max(0, $pass->claim_grace_days) }} أيام @endif
                @elseif ($claim_open)
                    انتهى المسار. لا نقاط جديدة، ويمكنك استلام ما كسبته حتى {{ $pass->claim_ends_at->format('Y-m-d') }}.
                @else
                    انتهى هذا المسار وأُغلقت مهلة الاستلام. تقدّمك محفوظ للعرض فقط.
                @endif
            </p>
        </div>
    </section>

    {{-- الشفافية --}}
    <details class="glass rounded-2xl p-5 mb-6 anim-fade-up d-2" data-pass-transparency>
        <summary class="cursor-pointer font-display font-black text-white rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">كيف يعمل المسار؟ (شفافية كاملة)</summary>
        <ul class="mt-3 text-sm text-slate-300 space-y-1.5 list-disc ps-5">
            <li>تكسب «نقاط المسار» بإكمال مهام المسار فقط. هي وحدة مستقلة: ليست خبرة ولا جواهر ولا نقاط موسم.</li>
            <li>المسار المجاني متاح لكل لاعب. المسار المميز شراء لمرة واحدة لهذا المسار فقط، وليس اشتراكًا ولا يتجدد تلقائيًا.</li>
            <li>عند فتح المميز تصبح مكافآته للشرائح التي بلغتها سابقًا قابلة للاستلام فورًا؛ لا تضيع نقاطك.</li>
            <li>المكافآت تجميلية أو وصولية فقط. لا نقد ولا جواهر ولا جوائز حقيقية، ولا أفضلية في الأحجيات أو الترتيب أو المنافسات أو السمعة.</li>
            <li>لا نقاط جديدة بعد انتهاء المسار، وتبقى مهلة استلام محددة لما كسبته.</li>
            <li>لا يوجد شراء شرائح أو نقاط، ولا هدايا، ولا صناديق عشوائية.</li>
        </ul>
    </details>

    {{-- المسار --}}
    <section aria-labelledby="pass-track-h" class="mb-8 anim-fade-up d-2">
        <div class="flex items-center justify-between gap-3 mb-3">
            <x-section-header title="مسار المكافآت" id="pass-track-h" />
        </div>
        <div class="hidden sm:grid grid-cols-[3.5rem_1fr_1fr] gap-3 px-1 mb-2 text-xs font-black text-slate-400" aria-hidden="true">
            <span class="text-center">الشريحة</span><span>المجاني</span><span style="color: var(--pass-accent)">المميز</span>
        </div>
        <ol class="space-y-3" data-pass-tiers>
            @foreach ($tiers as $row)
                <li class="grid grid-cols-[3.5rem_1fr] sm:grid-cols-[3.5rem_1fr_1fr] gap-3 items-stretch {{ $row['milestone'] ? 'rounded-2xl ring-1 ring-white/10 bg-white/[.02] p-2 -m-2' : '' }}" data-tier="{{ $row['number'] }}" @if ($row['reached']) data-reached @endif>
                    <div class="row-span-2 sm:row-span-1 grid place-items-center">
                        <span class="grid place-items-center w-12 h-12 rounded-full font-display font-black text-sm {{ $row['reached'] ? 'text-slate-900' : 'bg-white/5 text-slate-400' }}" @if ($row['reached']) style="background: var(--pass-accent)" @endif title="{{ number_format($row['points']) }} نقطة" aria-label="الشريحة {{ $row['number'] }}، {{ number_format($row['points']) }} نقطة">{{ $row['number'] }}</span>
                    </div>
                    @include('pass._reward-cell', ['cell' => $row['free'], 'lane' => 'free', 'claimOpen' => $claim_open && ! $frozen])
                    @include('pass._reward-cell', ['cell' => $row['premium'], 'lane' => 'premium', 'claimOpen' => $claim_open && ! $frozen])
                </li>
            @endforeach
        </ol>
    </section>

    {{-- المهام --}}
    <section aria-labelledby="pass-missions-h" class="mb-8 anim-fade-up d-3">
        <x-section-header title="مهام المسار" id="pass-missions-h" />
        @php($anyMissions = collect($missions)->flatten(1)->isNotEmpty())
        @if (! $anyMissions)
            <p class="text-sm text-slate-400">لا مهام مرتبطة بهذا المسار.</p>
        @else
            <div class="space-y-6" data-pass-missions>
                @foreach ($missions as $difficulty => $list)
                    @if (count($list) > 0)
                        <div>
                            <h3 class="text-sm font-black text-slate-300 mb-2">{{ $difficultyLabels[$difficulty] ?? $difficulty }}</h3>
                            <ul class="grid gap-3 md:grid-cols-2">
                                @foreach ($list as $m)
                                    <li class="glass rounded-2xl p-4 {{ $m['available'] ? '' : 'opacity-70' }}" data-mission>
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="font-bold text-white truncate">{{ $m['name'] }}@if ($m['featured']) <span class="chip !text-[10px] ms-1">مميزة</span>@endif</p>
                                                @if ($m['description'])<p class="text-xs text-slate-400 mt-0.5 line-clamp-2">{{ $m['description'] }}</p>@endif
                                            </div>
                                            <span class="shrink-0 text-sm font-black" style="color: var(--pass-accent)">+{{ number_format($m['points']) }}</span>
                                        </div>
                                        <div class="mt-3 flex items-center justify-between text-xs mb-1">
                                            <span class="text-slate-400">{{ $periodLabels[$m['period']] ?? '' }} · {{ min($m['value'], $m['target']) }} / {{ $m['target'] }}</span>
                                            <span class="text-slate-500">احتُسبت {{ $m['times_counted'] }} من {{ $m['max_completions'] }}</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $m['target'] }}" aria-valuenow="{{ min($m['value'], $m['target']) }}" aria-label="تقدّم {{ $m['name'] }}">
                                            <div class="h-full rounded-full" style="width: {{ $m['target'] > 0 ? (int) round(min(1, $m['value'] / $m['target']) * 100) : 0 }}%; background: var(--pass-accent)"></div>
                                        </div>
                                        @if ($m['remaining'] === 0)
                                            <p class="mt-2 text-[11px] text-slate-500">بلغتَ الحد الأقصى لاحتساب هذه المهمة.</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    {{-- آخر النقاط --}}
    @if ($recent->isNotEmpty())
        <section aria-labelledby="pass-recent-h" class="mb-8 anim-fade-up d-3" data-pass-recent>
            <x-section-header title="آخر النقاط" id="pass-recent-h" />
            <ul class="glass rounded-2xl divide-y divide-white/5">
                @foreach ($recent as $grant)
                    <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span class="text-slate-200 truncate">{{ $grant->mission?->quest?->name ?? 'مهمة' }}</span>
                        <span class="shrink-0 font-bold" style="color: var(--pass-accent)">+{{ number_format($grant->points) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
