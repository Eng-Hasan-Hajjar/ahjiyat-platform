@extends('layouts.app')

@section('title', $article->title)
@section('meta_description', $article->summary)
@section('canonical', route('help.show', $article->slug))
@section('content_width', 'max-w-4xl')

@section('content')
    {{-- E45: مقال دليل المنصة. النص يُعرَض كفقرات/عناوين/نقاط مهرَّبة فقط (HelpArticleBody) - لا HTML خام. --}}
    <x-page-header :title="$article->title" icon="quests" :subtitle="$article->summary" :back="route('help.index')" backLabel="دليل المنصة" class="anim-fade-up">
        <x-slot:actions>
            <x-status-badge tone="primary">{{ $areaLabel }}</x-status-badge>
        </x-slot:actions>
    </x-page-header>

    <article class="legal-card space-y-4">
        @foreach ($blocks as $block)
            @if ($block['type'] === 'h2')
                <h2 class="font-display font-black text-xl text-white pt-2">{{ $block['text'] }}</h2>
            @elseif ($block['type'] === 'ul')
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-[0.9375rem] leading-7">
                    @foreach ($block['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-slate-300 text-[0.9375rem] leading-7">{{ $block['text'] }}</p>
            @endif
        @endforeach

        @if (\App\Services\AiAssistant\Safety\AiLinkPolicy::isInternalPath($article->canonical_path))
            <p class="pt-2">
                <a href="{{ $article->canonical_path }}" class="inline-flex items-center gap-1.5 text-sm font-black text-amethyst hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded-lg">
                    {{ $article->source_label ?: 'اذهب إلى الصفحة' }}<x-ui-icon name="chevron-left" class="w-4 h-4" />
                </a>
            </p>
        @endif
    </article>

    @if ($related->isNotEmpty())
        <nav aria-label="مقالات ذات صلة" class="mt-8">
            <h2 class="font-display font-black text-white text-lg mb-3">اقرأ أيضًا</h2>
            <ul class="flex flex-wrap gap-2">
                @foreach ($related as $item)
                    <li><a href="{{ route('help.show', $item->slug) }}" class="chip !py-1.5 !px-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $item->title }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endif

    <p class="mt-8 text-xs text-slate-500">هذا الدليل مكتوب ومراجَع يدويًا. إن وجدت معلومة قديمة أو غير واضحة فالمرجع الأحدث هو الصفحة الفعلية داخل المنصة.</p>
@endsection
