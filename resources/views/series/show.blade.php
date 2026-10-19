@extends('layouts.app')

@section('title', $revision->title.' - سلسلة من المجتمع')
@section('meta_description', 'سلسلة أحجيات من المجتمع: '.$revision->title.'. سلاسل المجتمع بلا جوائز اقتصادية.')
@section('canonical', route('series.show', $series->public_id))
@section('content_width', 'max-w-3xl')

{{--
    E30: صفحة سلسلة مبدع. وسم «من المجتمع» وتصريح «بلا جوائز» ظاهران دائمًا. قراءة فقط (لا كتابة بـGET). اللعب يجري في صفحات أحجيات المجتمع القائمة (بلا اقتصاد).
    التقدّم مشتقّ من سجل لعب المجتمع، و«أكمل» يفتح أول عنصر غير محلول. نصوص المبدع مهرَّبة دائمًا (إخراج Blade العادي فقط). RTL.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('creators.show', $creator->slug) }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />{{ $creator->user->name }}
        </a>

        <x-share-button kind="series" :param="$series->public_id" :title="$revision->title" />

        <header class="puzzle-card !p-6 md:!p-8 anim-fade-up d-1" data-series-header>
            @if ($coverUrl)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-5">
                    <img src="{{ $coverUrl }}" alt="{{ $revision->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
                <span class="chip !py-1 !px-3">{{ $items->count() }} أحجية</span>
            </div>
            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $revision->title }}</h1>
            <p class="text-xs text-slate-400 mb-3">
                بقلم <a href="{{ route('creators.show', $creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $creator->user->name }}</a>
                @if ($series->first_published_at) · نُشرت {{ $series->first_published_at->translatedFormat('j F Y') }} @endif
            </p>
            @if ($revision->description)
                <p class="text-sm text-slate-200 break-words whitespace-pre-line">{{ $revision->description }}</p>
            @endif

            <div class="mt-5">
                @guest
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                        <span>سجّل الدخول لتلعب السلسلة ويُحفظ تقدّمك.</span>
                        <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                    </div>
                @else
                    @if ($isOwner)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200" role="status">هذه سلسلتك. لا تُحتسب لك محاولات على أحجياتك. <a href="{{ route('creator.series.index') }}" class="text-amethyst font-bold underline">استوديو السلاسل</a></div>
                    @elseif (! $canPlay)
                        <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-gold font-bold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتلعب أحجيات المجتمع.</div>
                    @else
                        <div data-series-progress>
                            <div class="flex items-center justify-between text-sm font-bold text-slate-300 mb-2">
                                <span>تقدّمك: {{ $progress['done'] }} من {{ $progress['total'] }}</span>
                                <span>{{ $progress['percent'] }}%</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="تقدّمك في السلسلة">
                                <div class="h-full rounded-full bg-amethyst" style="width: {{ $progress['percent'] }}%"></div>
                            </div>
                            @if ($progress['completed'])
                                <p class="mt-3 text-sm font-bold text-emerald flex items-center gap-2" role="status"><x-ui-icon name="check-circle" class="w-5 h-5" />أكملت السلسلة! أحسنت. (أحجيات المجتمع بلا جوائز اقتصادية.)</p>
                            @endif
                        </div>
                    @endif
                @endguest

                @if ($continueId && ! $isOwner && ! ($canPlay && $progress['completed']))
                    <a data-series-continue href="{{ route('community.puzzles.show', ['work' => $continueId, 'series' => $series->public_id]) }}" class="btn-gem !px-8 mt-4 inline-flex items-center gap-2">
                        {{ $canPlay && $progress['done'] > 0 ? 'أكمل السلسلة' : 'ابدأ السلسلة' }}<x-ui-icon name="chevron-left" class="w-4 h-4" />
                    </a>
                @endif
            </div>
        </header>

        <section aria-label="أحجيات السلسلة" class="anim-fade-up d-2">
            <ol class="space-y-2" data-series-items>
                @foreach ($items as $item)
                    @php $solved = (bool) ($progress['solved'][$item->work_id] ?? false); @endphp
                    <li>
                        <a href="{{ route('community.puzzles.show', ['work' => $item->public_id, 'series' => $series->public_id]) }}"
                           class="glass rounded-2xl p-4 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                            <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl font-black {{ $solved ? 'bg-emerald/15 text-emerald' : 'bg-white/5 text-slate-300' }}" aria-hidden="true">{{ $item->position }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-bold text-white break-words">{{ $item->published_title }}</span>
                                <span class="block text-xs text-slate-400 mt-0.5">{{ $item->category }}</span>
                            </span>
                            @if ($item->published_difficulty) <x-difficulty-badge :difficulty="$item->published_difficulty" :small="true" /> @endif
                            @if ($solved)
                                <x-status-badge tone="success" :small="true" icon="check-circle">محلولة</x-status-badge>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
@endsection
