@extends('layouts.app')

@section('title', 'سلسلة جديدة')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="سلسلة جديدة" icon="plus" subtitle="اختر من أحجياتك المنشورة ورتّبها. تُحفظ كمسودة ثم تُرسل للمراجعة." :back="route('creator.series.index')" backLabel="سلاسلي" class="anim-fade-up !mb-0" />

        @if ($works->count() < $limits['min_items'])
            <x-empty-state icon="puzzle" title="تحتاج أحجيات منشورة أولًا" :message="'لإنشاء سلسلة تحتاج '.$limits['min_items'].' أحجيات منشورة وظاهرة على الأقل.'" :action="route('creator.puzzles.index')" actionLabel="أحجياتي" />
        @else
            <form method="POST" action="{{ route('creator.series.store') }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                @csrf
                @include('creator.series._form', ['values' => [], 'works' => $works, 'selected' => $selected, 'current' => $current, 'limits' => $limits])
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400">السلاسل محتوى مجتمع بلا جوائز اقتصادية، وتحتاج موافقة المراجع قبل ظهورها.</div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ كمسودة</button>
                </div>
            </form>
        @endif
    </div>
@endsection
