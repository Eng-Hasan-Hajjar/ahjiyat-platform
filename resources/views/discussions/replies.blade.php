@extends('layouts.app')

@section('title', 'ردود على تعليق - '.$d['subject']['title'])
@section('meta_description', 'ردود على تعليق في نقاش «'.$d['subject']['title'].'».')
@section('content_width', 'max-w-3xl')

{{--
    E36: صفحة ردود جذر واحد (مرقَّمة، الأقدم أولًا). الجذر يُعرض كما في الصفحة الرئيسية (حيًّا أو لحدًا محايدًا إن كانت له ردود قابلة للعرض).
    قراءة عامة؛ الكتابة بحساب موثَّق. noindex: صفحة تفرّع من النقاش لا قيمة لفهرستها مستقلة.
--}}
@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    @php
        $root = $d['root'];
        $subject = $d['subject'];
    @endphp
    <div class="space-y-5">
        <a href="{{ $subject['url'] }}#c-{{ $root['id'] }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst anim-fade-up">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />العودة إلى النقاش: <span class="break-words">{{ $subject['title'] }}</span>
        </a>

        <section class="glass rounded-2xl p-5 md:p-6 space-y-4 anim-fade-up" aria-labelledby="replies-title" data-discussion-replies>
            <h1 id="replies-title" class="font-display font-black text-lg text-white">الردود <span class="text-sm font-bold text-slate-400 tabular-nums">({{ number_format($root['reply_count']) }})</span></h1>

            @if (session('discussion_status'))
                <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('discussion_status') }}</p>
            @endif
            @if (session('discussion_error'))
                <p role="alert" class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-2 text-sm font-bold">{{ session('discussion_error') }}</p>
            @endif
            @if ($d['locked'])
                <p class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-2 text-sm font-bold flex items-center gap-2" role="status"><x-ui-icon name="lock" class="w-4 h-4" />تم إغلاق النقاش من قبل الإدارة.</p>
            @endif

            @include('discussions._comment', ['c' => $root, 'd' => $d, 'nested' => false])

            @if ($d['replies']->isNotEmpty())
                <ol class="space-y-2 border-s-2 border-white/10 ps-3 md:ps-4" aria-label="الردود" data-replies>
                    @foreach ($d['replies'] as $reply)
                        <li>@include('discussions._comment', ['c' => $reply, 'd' => $d, 'nested' => true])</li>
                    @endforeach
                </ol>
                @if ($d['replies']->hasPages())
                    <div class="flex justify-center">{{ $d['replies']->links() }}</div>
                @endif
            @endif
        </section>
    </div>
@endsection
