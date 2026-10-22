@extends('layouts.app')

@section('title', $puzzle->title.' - من المجتمع')
@section('meta_description', 'أحجية من المجتمع بعنوان «'.$puzzle->title.'». أحجيات المجتمع بلا جوائز اقتصادية.')
@section('canonical', route('community.puzzles.show', $work->public_id))
@section('content_width', 'max-w-3xl')

{{--
    E28: صفحة لعب أحجية مجتمع. الوسم «من المجتمع» وتصريح «بلا جوائز» ظاهران دائمًا. اللعب عبر مسار المجتمع المنفصل (لا /puzzles الرسمي) والمدقّق الرسمي نفسه.
    لا جواهر ولا XP ولا مكافآت ولا مهام ولا سلسلة. المبدع لا يلعب أحجيته (يعاينها). نصوص المبدع مهرَّبة.
--}}
@section('content')
    <div class="space-y-5">
        <a href="{{ route('community.puzzles.index') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />أحجيات المجتمع
        </a>

        <x-share-button kind="community_puzzle" :param="$work->public_id" :title="$puzzle->title" />

        @if (! empty($seriesContext))
            {{-- E30: شريط سياق السلسلة (تنقّل فقط): الموضع والسابق والتالي. لا يغيّر اللعب ولا يمنح شيئًا. --}}
            <nav aria-label="سياق السلسلة" data-series-context class="glass rounded-2xl px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-sm anim-fade-up">
                <a href="{{ $seriesContext['url'] }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst break-words">سلسلة: {{ $seriesContext['title'] }} · {{ $seriesContext['position'] }} من {{ $seriesContext['total'] }}</a>
                <span class="flex items-center gap-2">
                    @if ($seriesContext['previous']) <a href="{{ route('community.puzzles.show', ['work' => $seriesContext['previous']['public_id'], 'series' => $seriesContext['series']]) }}" class="btn-secondary !py-1.5 !px-3 text-xs" rel="prev">السابقة</a> @endif
                    @if ($seriesContext['next']) <a href="{{ route('community.puzzles.show', ['work' => $seriesContext['next']['public_id'], 'series' => $seriesContext['series']]) }}" class="btn-secondary !py-1.5 !px-3 text-xs" rel="next">التالية</a> @endif
                </span>
            </nav>
        @endif

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($work->publishedCategory) <span class="chip !py-1 !px-3">{{ $work->publishedCategory->name }}</span> @endif
                <x-difficulty-badge :difficulty="$puzzle->difficulty" />
                <x-status-badge tone="neutral" :small="true">بلا جوائز</x-status-badge>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $puzzle->title }}</h1>
            <p class="text-xs text-slate-400 mb-4">
                بقلم <a href="{{ route('creators.show', $work->creator->slug) }}" class="font-bold text-slate-200 hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $work->creator->user->name }}</a>
                · {{ number_format($work->plays_count) }} لاعب
            </p>

            <p class="font-display font-black text-xl md:text-2xl text-white mb-4 leading-snug break-words whitespace-pre-line">{{ $puzzle->prompt }}</p>

            @if ($puzzle->image_path)
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($puzzle->image_path) }}" alt="{{ $puzzle->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif

            @guest
                <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200 flex flex-wrap items-center justify-between gap-3">
                    <span>سجّل الدخول لتلعب هذه الأحجية وتحفظ حالة حلّك.</span>
                    <a href="{{ route('login') }}" class="btn-gem !py-2 !px-5 text-sm">تسجيل الدخول</a>
                </div>
            @else
                @if ($isOwner)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-5 py-4 text-sm text-slate-200" role="status">هذه أحجيتك. لا تُحتسب لك محاولات عليها؛ جرّبها من المعاينة في الاستوديو.
                        <a href="{{ route('creator.puzzles.preview', $work->public_id) }}" class="text-amethyst font-bold underline">افتح المعاينة</a></div>
                @elseif (! $canInteract)
                    <div class="rounded-xl border border-gold/30 bg-gold/10 px-5 py-4 text-sm text-gold font-bold" role="status">يلزم بريد إلكتروني موثَّق وحساب غير مجمَّد لتلعب أحجيات المجتمع.</div>
                @elseif ($state['solved'])
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="check-circle" class="w-5 h-5" />أحسنت! سبق أن حللت هذه الأحجية.</div>
                @elseif ($state['left'] <= 0)
                    <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-5 py-4 font-bold flex items-center gap-2" role="status"><x-ui-icon name="alert" class="w-5 h-5" />استنفدت جميع محاولاتك المسموحة لهذه الأحجية.</div>
                @else
                    <form method="POST" action="{{ route('community.puzzles.attempt', $work->public_id) }}" class="mt-2">
                        @csrf
                        @include($renderer)

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-bold text-slate-400">محاولة {{ $state['used'] + 1 }} من {{ $state['max'] }}</span>
                            <button type="submit" class="btn-gem !py-3 !px-6">إرسال الإجابة</button>
                        </div>
                    </form>

                    @if ($puzzle->hint)
                        <form method="POST" action="{{ route('community.puzzles.hint', $work->public_id) }}" class="mt-6 pt-6 border-t border-white/5">
                            @csrf
                            <button type="submit" class="text-sm font-bold text-slate-300 hover:text-gold transition motion-reduce:transition-none underline decoration-gold decoration-2 underline-offset-4 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إظهار التلميح (مجاني)</button>
                        </form>
                    @endif
                @endif
            @endguest
        </div>

        {{-- E29: التقييم والمفضلة والمجموعات (المتوسط والعدّادات علنية للجميع؛ التفاعل للموثَّق غير المجمَّد وغير المالك) --}}
        @include('engagement._work_panel', ['work' => $work, 'isOwner' => $isOwner, 'canInteract' => $canInteract, 'viewer' => $viewer, 'collections' => $collections])

        {{-- E36: النقاش (تعليقات وردود وإعجابات). لا جواهر ولا XP؛ null = معطَّل أو تعذّر تحميله فيُرسَم باقي الصفحة. --}}
        @if (! empty($discussion))
            @include('discussions._section', ['d' => $discussion])
        @endif

        @auth
            @if (! $isOwner && $canInteract)
                <details class="glass rounded-2xl p-5 anim-fade-up">
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 flex items-center gap-2 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="flag" class="w-4 h-4" />الإبلاغ عن هذه الأحجية</summary>
                    <form method="POST" action="{{ route('community.puzzles.report', $work->public_id) }}" class="mt-4 space-y-3">
                        @csrf
                        <div>
                            <label for="report-reason" class="block text-xs font-bold text-slate-300 mb-1">السبب</label>
                            <select id="report-reason" name="reason" required class="input-gem">
                                @foreach ($reasons as $key => $label)
                                    <option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="report-details" class="block text-xs font-bold text-slate-300 mb-1">تفاصيل (اختياري)</label>
                            <textarea id="report-details" name="details" rows="3" maxlength="1000" class="input-gem">{{ old('details') }}</textarea>
                        </div>
                        <button type="submit" class="btn-secondary !py-2 !px-5 text-sm">إرسال البلاغ</button>
                    </form>
                </details>
            @endif
        @endauth
    </div>
@endsection
