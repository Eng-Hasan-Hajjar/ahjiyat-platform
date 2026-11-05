@extends('layouts.app')

@section('title', 'السلاسل السينمائية')
@section('meta_description', 'سلاسل أحجيات تفاعلية بمشاهد سينمائية: شاهد، استكشف، وحلّ الألغاز لتتقدّم في القصة.')
@section('canonical', route('cinematic.index'))
@section('content_width', 'max-w-5xl')

{{--
    CIN-1: قائمة السلاسل السينمائية المنشورة. قراءة فقط (لا كتابة بـGET). لا اقتصاد: لا جواهر ولا خبرة هنا.
    القصة المميزة تظهر ببطاقتها وملخصها فقط؛ المشاهد والوسائط لا تُعرض إلا لمن يملك الوصول (صفحة القصة تقرر).
    نصوص المؤلف تُطبع دائمًا بالإخراج المهرَّب.
--}}
@section('content')
    <div class="space-y-5">
        <x-page-header title="السلاسل السينمائية" subtitle="قصص تفاعلية بمشاهد مصوَّرة وألغاز؛ تقدّمك يُحفظ تلقائيًا." icon="sparkles" />

        @if ($items->isEmpty())
            <x-empty-state icon="sparkles" title="لا توجد سلاسل منشورة بعد" message="سيظهر هنا ما يُنشر من السلاسل السينمائية. عد قريبًا." />
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-cinematic-list>
                @foreach ($items as $item)
                    @php($story = $item['story'])
                    <li>
                        <a href="{{ route('cinematic.show', $story->slug) }}" class="glass rounded-2xl overflow-hidden flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none" data-cinematic-card="{{ $story->slug }}">
                            @if ($item['cover'])
                                <img src="{{ $item['cover'] }}" alt="" class="w-full aspect-video object-cover" loading="lazy" width="640" height="360">
                            @else
                                <div class="w-full aspect-video grid place-items-center bg-amethyst/10 text-amethyst" aria-hidden="true"><x-ui-icon name="sparkles" class="w-10 h-10" /></div>
                            @endif
                            <div class="p-4 flex flex-col gap-2 grow">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-status-badge tone="neutral" :small="true">{{ $story->label_kind === 'short' ? 'قصة قصيرة' : 'قصة طويلة' }}</x-status-badge>
                                    @if ($story->isPremium()) <x-status-badge tone="warning" :small="true" icon="lock">مميزة</x-status-badge> @endif
                                    @if ($item['completed']) <x-status-badge tone="success" :small="true" icon="check-circle">مكتملة</x-status-badge> @endif
                                </div>
                                <h2 class="font-display font-black text-white leading-snug break-words">{{ $story->title }}</h2>
                                @if ($story->summary)
                                    <p class="text-xs text-slate-400 break-words line-clamp-3">{{ $story->summary }}</p>
                                @endif
                                @if ($item['started'] && ! $item['completed'])
                                    <div class="mt-auto pt-2">
                                        <div class="h-1.5 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $item['percent'] }}" aria-label="تقدّمك في {{ $story->title }}">
                                            <div class="h-full rounded-full bg-amethyst" style="width: {{ $item['percent'] }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
