@extends('layouts.app')

@section('title', 'أحجياتي')

@section('content')
    <div class="space-y-6">
        <x-page-header title="أحجياتي" icon="puzzle" subtitle="كل أعمالك وحالتها." :back="route('creator.dashboard')" backLabel="الاستوديو" class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creator.puzzles.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />أحجية جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 anim-fade-up">
            @foreach (['all' => 'الكل', 'draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                <a href="{{ route('creator.puzzles.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($bucket === $key) aria-current="page" @endif
                   class="chip !py-1.5 !px-3 text-xs {{ $bucket === $key ? 'chip-active' : '' }}">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>

        <section class="anim-fade-up">
            @forelse ($works as $work)
                @include('creator.puzzles._row', ['work' => $work])
            @empty
                <x-empty-state icon="palette" title="لا أحجيات هنا" message="غيّر التصفية أو أنشئ أحجية جديدة." :action="route('creator.puzzles.create')" actionLabel="أحجية جديدة" />
            @endforelse

            <div class="flex justify-center mt-6">{{ $works->links() }}</div>
        </section>
    </div>
@endsection
