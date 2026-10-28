@extends('layouts.app')

@section('title', 'سلاسلي')

{{-- E30: استوديو السلاسل. حاويات الحالة + تحليلات مجمَّعة (بلا هوية لاعب). لا أرباح ولا جوائز: السلاسل محتوى مجتمع. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="سلاسلي" icon="grid" subtitle="قوائم أحجيات مرتّبة من أعمالك المنشورة." :back="route('creator.dashboard')" backLabel="الاستوديو" class="anim-fade-up !mb-0">
 <x-slot:actions>
                <a href="{{ route('creator.premium.index') }}" class="btn-secondary !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="lock" class="w-4 h-4" />السلاسل المميزة</a>
                <a href="{{ route('creator.series.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />سلسلة جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <section aria-label="أرقام سلاسلك" class="grid grid-cols-2 md:grid-cols-4 gap-3 anim-fade-up" data-series-stats>
            <x-stat-card label="لاعبون" :value="number_format($stats['players'])" icon="friends" />
            <x-stat-card label="بدايات الأحجيات" :value="number_format($stats['starts'])" icon="puzzle" />
            <x-stat-card label="أكملوا السلسلة" :value="number_format($stats['completions'])" icon="check-circle" />
            <x-stat-card label="نسبة الإكمال" :value="$stats['completion_rate'].'%'" icon="chart" />
        </section>

        <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 anim-fade-up">
            @foreach (['all' => 'الكل', 'draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                <a href="{{ route('creator.series.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($bucket === $key) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $bucket === $key ? 'chip-active' : '' }}">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <section class="anim-fade-up">
            @forelse ($seriesList as $series)
                @php $rev = $series->latestRevision; $a = $perSeries[$series->id] ?? null; @endphp
                <div class="glass rounded-2xl p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('creator.series.edit', $series->public_id) }}" class="font-display font-black text-white hover:text-amethyst break-words focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $rev?->title ?? $series->published_title }}</a>
                        <p class="text-xs text-slate-400 mt-1">
                            @if ($rev) نسخة {{ $rev->revision_number }} @endif
                            @if ($series->isPublished()) · {{ (int) $series->published_items_count }} أحجية @endif
                            @if ($a) · {{ number_format($a['players']) }} لاعب · إكمال {{ $a['completion_rate'] }}% @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($rev) <x-creator-status :status="$rev->status" :small="true" /> @endif
                        @if ($series->isArchived()) <x-creator-status status="archived" :small="true" /> @endif
                        @if ($series->isHidden()) <x-creator-status status="hidden" :small="true" /> @endif
                        <a href="{{ route('creator.series.edit', $series->public_id) }}" class="btn-secondary !py-1.5 !px-3 text-xs">فتح</a>
                    </div>
                </div>
            @empty
                <x-empty-state icon="grid" title="لا سلاسل هنا" message="غيّر التصفية أو أنشئ سلسلة جديدة من أحجياتك المنشورة." :action="route('creator.series.create')" actionLabel="سلسلة جديدة" />
            @endforelse

            <div class="flex justify-center mt-6">{{ $seriesList->links() }}</div>
        </section>
    </div>
@endsection
