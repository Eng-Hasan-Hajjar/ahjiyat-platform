@extends('layouts.app')

@section('title', 'كتّاب الموسم — '.$title)
@section('meta_description', 'ترتيب كتّاب موسم «'.$title.'» ونجوم الجمهور.')
@section('content_width', 'max-w-4xl')

{{--
    E44 - كتّاب الموسم: ترتيب حيّ بنجوم الجمهور. كل قرار (الأهلية، الميزانية، النافذة، الإنهاء) على الخادم؛ الواجهة عرض فقط.
    الميزانية: {{ $voter['total'] ?? 10 }} نجوم لكل حساب في الموسم (لا تتضاعف بتعدد الصفات)، ولا يُلزم صرفها كلها، وتُعاد قبل الإغلاق ثم تُجمَّد.
--}}
@php
    $stateLabels = [
        'upcoming' => ['لم يبدأ التصويت بعد', 'warning'],
        'open' => ['التصويت مفتوح', 'success'],
        'closed' => ['أُغلق التصويت — بانتظار الترتيب النهائي', 'neutral'],
        'finalized' => ['صدر الترتيب النهائي', 'primary'],
        'disabled' => ['الدوري غير مفعّل حاليًا', 'neutral'],
        'not_configured' => ['موعد التصويت لم يُحدَّد بعد', 'neutral'],
    ];
    [$stateText, $stateTone] = $stateLabels[$state] ?? ['—', 'neutral'];
@endphp

@section('content')
    <div class="space-y-6">
        <x-page-header :title="'كتّاب الموسم'" :subtitle="$title" icon="trophy" :back="route('seasons.show', $season)" backLabel="رجوع إلى الموسم" class="anim-fade-up !mb-0" />

        <section class="puzzle-card !p-5 md:!p-6 space-y-3 anim-fade-up" aria-labelledby="writers-state">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="writers-state" class="sr-only">حالة التصويت</h2>
                <x-status-badge :tone="$stateTone">{{ $stateText }}</x-status-badge>
                @if ($opens)<span class="text-xs text-slate-400">يبدأ: {{ $opens->translatedFormat('j F Y — H:i') }}</span>@endif
                @if ($closes)<span class="text-xs text-slate-400">يُغلق: {{ $closes->translatedFormat('j F Y — H:i') }}</span>@endif
            </div>
            <p class="text-sm text-slate-300">لكل حساب {{ $voter['total'] ?? ($setting?->writers_stars_per_voter ?? 10) }} نجوم في الموسم يوزّعها كما يشاء على الكتّاب (حتى 10 لكل كاتب)، ويمكن إعادة توزيعها قبل الإغلاق. لا يمكن التصويت لنفسك، ويُشترط لعب إحدى سلاسل الكاتب المرتبطة بالموسم للتصويت له. النجوم تقدير فقط: ليست مالًا ولا جواهر ولا خبرة.</p>
        </section>

        <section class="puzzle-card !p-5 md:!p-6 anim-fade-up" aria-labelledby="writers-standings-title"
                 x-data="seasonalBoard({ url: @js(route('seasonal.writers.standings', $season)), channel: @js('seasonal-writers.'.$season->id), pollSeconds: @js($pollSeconds), debounceMs: @js($debounceMs), realtime: @js($realtime) })">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 id="writers-standings-title" class="font-display font-black text-xl text-white">{{ $board['finalized'] ? 'الترتيب النهائي' : 'الترتيب الحيّ' }}</h2>
                <span class="text-[11px] text-slate-500" x-show="busy" x-cloak>جارٍ التحديث…</span>
            </div>
            <div x-ref="target" aria-live="polite" :aria-busy="busy ? 'true' : 'false'">
                @include('seasonal._writers_standings', ['board' => $board])
            </div>
        </section>

        @auth
            @if ($voter && $voter['rows'] !== [])
                <section class="puzzle-card !p-5 md:!p-6 space-y-4 anim-fade-up" aria-labelledby="writers-vote-title"
                         x-data="seasonalVoter({ total: {{ (int) $voter['total'] }}, spent: {{ (int) $voter['spent'] }}, open: @js((bool) $voter['open']) })">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="writers-vote-title" class="font-display font-black text-xl text-white">نجومك</h2>
                        <p class="text-sm font-bold text-gold tabular-nums" aria-live="polite">المتبقي: <span x-text="remaining"></span> / <span x-text="total"></span></p>
                    </div>

                    @if (! $voter['open'])
                        <p class="text-sm text-slate-400">التصويت غير متاح الآن@if ($state === 'finalized' || $state === 'closed') — تتجمّد توزيعاتك بعد الإغلاق@endif.</p>
                    @endif

                    <ul class="space-y-3">
                        @foreach ($voter['rows'] as $w)
                            <li class="rounded-2xl border border-white/10 bg-white/5 p-4 space-y-2" data-writer="{{ $w['public_id'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <a href="{{ route('creators.show', $w['slug']) }}" class="font-black text-white hover:underline break-words">{{ $w['name'] }}</a>
                                    @if ($w['self'])
                                        <x-status-badge small>أنت</x-status-badge>
                                    @elseif ($w['can_vote'])
                                        <x-status-badge tone="success" small>يمكنك التصويت</x-status-badge>
                                    @else
                                        <x-status-badge tone="warning" small>العب إحدى سلاسله للتصويت</x-status-badge>
                                    @endif
                                </div>

                                @if ($w['series'] !== [])
                                    <p class="text-xs text-slate-400">سلاسله في الموسم:
                                        @foreach ($w['series'] as $s)
                                            <a href="{{ route('series.show', $s['id']) }}" class="text-amethyst hover:underline">{{ $s['title'] }}</a>@if (! $loop->last)، @endif
                                        @endforeach
                                    </p>
                                @endif

                                @if ($w['can_vote'] && ! $w['self'])
                                    <form method="POST" action="{{ route('seasonal.writers.stars', [$season, $w['public_id']]) }}" class="flex flex-wrap items-center gap-2"
                                          data-initial="{{ $w['stars'] }}" @submit.prevent="save($event)">
                                        @csrf
                                        <label class="text-xs font-bold text-slate-300" for="stars-{{ $w['public_id'] }}">نجومك</label>
                                        <select id="stars-{{ $w['public_id'] }}" name="stars" class="input !py-1.5 !w-24" @disabled(! $voter['open'])>
                                            @for ($i = 0; $i <= 10; $i++)
                                                <option value="{{ $i }}" @selected($i === $w['stars'])>{{ $i }}</option>
                                            @endfor
                                        </select>
                                        <button type="submit" class="btn-secondary !py-1.5 !px-4" @disabled(! $voter['open']) :disabled="sending">حفظ</button>
                                        <span class="text-xs" :class="toneFor('{{ $w['public_id'] }}')" x-text="messageFor('{{ $w['public_id'] }}')" role="status" aria-live="polite"></span>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @else
            <p class="text-sm text-slate-400 text-center"><a href="{{ route('login') }}" class="font-bold text-amethyst hover:underline">سجّل الدخول</a> لتوزيع نجومك على الكتّاب.</p>
        @endauth

        @if ($state === 'finalized')
            <p class="text-xs text-slate-500">وفق قاعدة الدوري، من حلّ في ذيل الترتيب لا يُضاف إلى قائمة الموسم التالي المباشر فقط؛ لا يُعدّ ذلك حظرًا ولا إيقافًا، ويبقى ملفه ومحتواه كما هما.</p>
        @endif
    </div>
@endsection
