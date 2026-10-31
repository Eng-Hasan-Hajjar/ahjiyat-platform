@extends('layouts.app')

@section('title', 'دليل المنصة')
@section('meta_description', 'دليل أحجيات: شروحات مختصرة وموثوقة عن الأحجيات والمواسم والمنافسات والفرق ومسار الأحجيات والمتجر والمبدعين.')
@section('canonical', route('help.index'))

@section('content')
    {{-- E45: دليل المنصة - مقالات منشورة يدويًا، مصدر المساعد نفسه. عرض فقط. --}}
    <x-page-header title="دليل المنصة" icon="quests" subtitle="شروحات مختصرة وموثوقة عن كل ما في أحجيات، مكتوبة ومراجَعة يدويًا." class="anim-fade-up">
        @if ($assistantVisible)
            <x-slot:actions>
                <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('tatawwur:open'))" class="btn-gem !py-2.5 !px-5 text-sm inline-flex items-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <x-ui-icon name="sparkles" class="w-4 h-4" />اسأل تطوّر AI
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('help.index') }}" role="search" class="glass rounded-2xl p-3 sm:p-4 mb-6 flex items-center gap-2">
        <label for="help-q" class="sr-only">ابحث في الدليل</label>
        <x-ui-icon name="search" class="w-5 h-5 text-slate-400 shrink-0 ms-1" />
        <input id="help-q" type="search" name="q" value="{{ $q }}" maxlength="80" autocomplete="off" placeholder="ابحث في دليل المنصة…"
            class="min-w-0 flex-1 bg-transparent border-0 text-white placeholder:text-slate-500 text-sm font-semibold focus:outline-none focus:ring-0">
        <button type="submit" class="btn-gem !py-2 !px-5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">بحث</button>
    </form>

    @if ($found !== null)
        <p class="text-sm text-slate-400 mb-4" role="status">
            @if ($found->isEmpty())
                لا توجد مقالات مطابقة لـ«{{ $q }}». جرّب كلمات أبسط، أو <a href="{{ route('help.index') }}" class="text-amethyst font-bold hover:underline">تصفّح كل المقالات</a>.
            @else
                {{ $found->count() }} نتيجة لـ«{{ $q }}» · <a href="{{ route('help.index') }}" class="text-amethyst font-bold hover:underline">عرض الكل</a>
            @endif
        </p>

        @if ($found->isNotEmpty())
            <ul class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach ($found as $article)
                    <li>
                        <a href="{{ route('help.show', $article->slug) }}" class="glass rounded-2xl p-5 block h-full transition motion-reduce:transition-none hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                            <x-status-badge tone="primary" small>{{ $areas[$article->area] ?? $areas['other'] }}</x-status-badge>
                            <h2 class="font-display font-black text-white text-lg mt-2">{{ $article->title }}</h2>
                            <p class="text-sm text-slate-400 mt-1 leading-6">{{ $article->summary }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @elseif ($total === 0)
        <x-empty-state icon="info" title="الدليل قيد الإعداد" message="لم تُنشر مقالات بعد. سنضيف شروحات المنصة هنا قريبًا." />
    @else
        <div class="space-y-8">
            @foreach ($grouped as $area => $items)
                <section aria-labelledby="area-{{ $area }}">
                    <h2 id="area-{{ $area }}" class="font-display font-black text-white text-xl mb-3">{{ $areas[$area] ?? $areas['other'] }}</h2>
                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach ($items as $article)
                            <li>
                                <a href="{{ route('help.show', $article->slug) }}" class="glass rounded-2xl p-5 block h-full transition motion-reduce:transition-none hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                                    <h3 class="font-display font-black text-white text-base">{{ $article->title }}</h3>
                                    <p class="text-sm text-slate-400 mt-1 leading-6">{{ $article->summary }}</p>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
@endsection
