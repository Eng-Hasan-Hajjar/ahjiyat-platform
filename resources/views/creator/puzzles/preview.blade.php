@extends('layouts.app')

@section('title', 'معاينة: '.$puzzle->title)
@section('content_width', 'max-w-3xl')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

{{--
    E28: معاينة أحجية مجتمع (للمبدع صاحبها أو للمراجع). المحرك والعارض والمدقّق نفسها كالأحجية الحقيقية، لكن بلا أي تسجيل: لا محاولات ولا جواهر ولا XP ولا مهام ولا تقدّم.
    الرابط غير عام وغير مفهرَس. لوحة الحل تظهر للمراجع فقط ($solution != null) ولا تصل لصفحة المبدع ولا للعامة. كل النصوص مهرَّبة.
--}}
@section('content')
    <div class="space-y-5">
        @if ($backRoute)
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
                <x-ui-icon name="chevron-right" class="w-4 h-4" />العودة للتحرير
            </a>
        @endif

        <div class="flex items-start gap-3 rounded-2xl border border-amethyst/30 bg-amethyst/10 px-4 py-3 text-sm anim-fade-up" role="note">
            <x-ui-icon name="info" class="w-5 h-5 shrink-0 text-amethyst mt-0.5" />
            <p class="text-slate-200">
                <span class="font-black text-white">وضع المعاينة.</span>
                لا تُسجَّل محاولاتك ولا تُمنح جواهر أو نقاط خبرة، ولا يتأثر أي تقدّم أو مهمة. الفحص يتم بالمدقّق الرسمي نفسه.
            </p>
        </div>

        <div class="puzzle-card !p-6 md:!p-9 anim-fade-up d-1">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <x-status-badge tone="primary" icon="friends">من المجتمع</x-status-badge>
                @if ($revision->category)
                    <span class="chip !py-1 !px-3">{{ $revision->category->name }}</span>
                @endif
                <x-difficulty-badge :difficulty="$puzzle->difficulty" />
                <x-creator-status :status="$revision->status" :small="true" />
                <span class="ms-auto text-xs font-bold text-slate-400">نسخة {{ $revision->revision_number }}</span>
            </div>

            <h1 class="font-display font-black text-2xl md:text-3xl text-white mb-2 leading-tight break-words">{{ $puzzle->title }}</h1>
            @if ($work->creator?->user)
                <p class="text-xs text-slate-400 mb-4">بقلم {{ $work->creator->user->name }}</p>
            @endif

            <p class="font-display font-black text-xl md:text-2xl text-white mb-4 leading-snug break-words whitespace-pre-line">{{ $puzzle->prompt }}</p>

            @if ($revision->imageUrl())
                <div class="rounded-2xl overflow-hidden border border-white/10 mb-6">
                    <img src="{{ $revision->imageUrl() }}" alt="{{ $puzzle->title }}" class="w-full h-auto" loading="lazy">
                </div>
            @endif

            <form method="POST" action="{{ $checkRoute }}" class="mt-6">
                @csrf
                @include($renderer)

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="text-sm font-bold text-slate-400">معاينة بلا حدّ للمحاولات</span>
                    <button type="submit" class="btn-gem !py-3 !px-6">فحص الإجابة</button>
                </div>
            </form>

            @if ($puzzle->hint)
                <details class="mt-6 pt-6 border-t border-white/5">
                    <summary class="cursor-pointer text-sm font-bold text-slate-300 hover:text-gold transition motion-reduce:transition-none rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إظهار التلميح</summary>
                    <p class="mt-3 text-sm text-slate-200 break-words">{{ $puzzle->hint }}</p>
                </details>
            @endif
        </div>

        @if (is_array($solution))
            <section class="rounded-2xl border border-gold/30 bg-gold/10 p-5 anim-fade-up" aria-labelledby="reviewer-solution-title">
                <h2 id="reviewer-solution-title" class="font-display font-black text-gold flex items-center gap-2"><x-ui-icon name="key" class="w-5 h-5" />لوحة الحل (للمراجع فقط)</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach ($solution as $label => $value)
                        <div>
                            <dt class="font-black text-white">{{ $label }}</dt>
                            <dd class="text-slate-200 mt-1">
                                @if (is_array($value))
                                    <ol class="list-decimal ps-5 space-y-0.5">
                                        @foreach ($value as $entry)
                                            <li class="break-words">{{ is_array($entry) ? implode(' / ', array_map('strval', $entry)) : $entry }}</li>
                                        @endforeach
                                    </ol>
                                @else
                                    <span class="break-words">{{ $value }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif
    </div>
@endsection
