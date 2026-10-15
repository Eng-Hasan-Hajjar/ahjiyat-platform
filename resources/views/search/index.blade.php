@extends('layouts.app')

@section('title', $query !== '' ? 'نتائج البحث: '.$query : 'البحث')
@section('meta_description', 'ابحث في الأحجيات والحملات والمواسم والمنافسات وبطولات الفرق والفرق واللاعبين.')

@section('content')
    @php
        $hasQuery = $state !== 'empty';
        $tabUrl = fn (?string $t) => route('search.index', array_filter(['q' => $query === '' ? null : $query, 'type' => $t]));
    @endphp
    <div class="max-w-3xl mx-auto space-y-5">

        <x-page-header title="البحث" subtitle="الأحجيات والحملات والمواسم والمنافسات والفرق واللاعبون في مكان واحد." icon="search" class="anim-fade-up !mb-0" />

        <form method="GET" action="{{ route('search.index') }}" role="search" class="glass rounded-3xl p-4 sm:p-5 flex flex-wrap items-center gap-3 anim-fade-up">
            <label for="search-page-q" class="sr-only">كلمة البحث</label>
            <input id="search-page-q" type="search" name="q" value="{{ $query }}" maxlength="64" autocomplete="off" enterkeyhint="search" placeholder="ابحث عن أحجية أو فريق أو لاعب…"
                class="flex-1 min-w-[12rem] rounded-xl bg-white/5 border border-white/10 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            @if ($type)
                <input type="hidden" name="type" value="{{ $type }}">
            @endif
            <button type="submit" class="btn-gem !py-2 !px-5 text-sm">بحث</button>
        </form>

        <nav aria-label="نوع النتائج" class="flex flex-wrap gap-2">
            <a href="{{ $tabUrl(null) }}" @if ($type === null) aria-current="page" @endif
                class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ $type === null ? '!border-amethyst/60 !text-white' : '' }}">الكل</a>
            @foreach ($types as $key => $label)
                <a href="{{ $tabUrl($key) }}" @if ($type === $key) aria-current="page" @endif
                    class="chip !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ $type === $key ? '!border-amethyst/60 !text-white' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <div aria-live="polite">
            @if ($state === 'empty')
                <x-empty-state icon="search" title="ابدأ بكتابة ما تبحث عنه" message="اكتب حرفين على الأقل، أو تصفّح ما هو جديد ورائج في مركز الاكتشاف." :action="route('discover.index')" actionLabel="افتح مركز الاكتشاف" />
            @elseif ($state === 'too_short')
                <x-empty-state icon="search" title="الكلمة قصيرة جدًا" :message="'اكتب '.$minLength.' أحرف على الأقل لتظهر النتائج.'" />
            @elseif ($mode === 'type')
                @if ($results->isEmpty())
                    <x-empty-state icon="search" :title="'لا نتائج في «'.$typeLabel.'»'" message="جرّب كلمة أقصر أو إملاءً مختلفًا، أو ابحث في كل الأقسام." :action="$tabUrl(null)" actionLabel="ابحث في الكل" />
                @else
                    <section aria-label="{{ $typeLabel }}" class="glass rounded-2xl overflow-hidden anim-fade-up">
                        <h2 class="px-4 pt-4 pb-2 text-sm font-black text-slate-300">{{ $typeLabel }}</h2>
                        <ul class="divide-y divide-white/5">
                            @foreach ($results as $r)
                                @include('search._result', ['r' => $r])
                            @endforeach
                        </ul>
                    </section>
                    <div class="flex items-center justify-between gap-3 text-sm font-bold">
                        @if ($results->previousPageUrl())
                            <a href="{{ $results->previousPageUrl() }}" rel="prev" class="chip !py-1.5 !px-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">السابق</a>
                        @else
                            <span></span>
                        @endif
                        @if ($results->nextPageUrl())
                            <a href="{{ $results->nextPageUrl() }}" rel="next" class="chip !py-1.5 !px-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">التالي</a>
                        @endif
                    </div>
                    @if ($capped)
                        <p class="text-xs text-slate-500 text-center">عرضنا أعلى النتائج صلة. حدّد كلمة البحث أكثر لتصل لما تريد.</p>
                    @endif
                @endif
            @else
                @forelse ($groups as $group)
                    <section aria-label="{{ $group['label'] }}" class="glass rounded-2xl overflow-hidden mb-4 anim-fade-up">
                        <div class="flex items-center justify-between gap-3 px-4 pt-4 pb-2">
                            <h2 class="text-sm font-black text-slate-300">{{ $group['label'] }}</h2>
                            @if ($group['has_more'])
                                <a href="{{ $tabUrl($group['type']) }}" class="inline-flex items-center gap-1 text-xs font-bold text-amethyst hover:underline rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                                    عرض المزيد <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
                                </a>
                            @endif
                        </div>
                        <ul class="divide-y divide-white/5">
                            @foreach ($group['results'] as $r)
                                @include('search._result', ['r' => $r])
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <x-empty-state icon="search" :title="'لا نتائج لـ «'.$query.'»'" message="جرّب كلمة أقصر أو إملاءً مختلفًا. البحث يتجاهل التشكيل ويوحّد الألف والهمزة والتاء المربوطة." :action="route('discover.index')" actionLabel="تصفّح الاكتشاف" />
                @endforelse

                @if (in_array('players', $notes, true))
                    <p class="text-xs text-slate-500 text-center">للبحث عن لاعبين اكتب {{ config('search.min_length_guest_players', 3) }} أحرف على الأقل.</p>
                @endif
            @endif
        </div>
    </div>
@endsection
