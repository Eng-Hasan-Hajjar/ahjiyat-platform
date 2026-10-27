@extends('layouts.app')

@section('title', 'أرشيف المواسم')
@section('meta_description', 'أرشيف المواسم الرسمية المنتهية على أحجيات.')

@section('content')
    <div class="max-w-5xl mx-auto">
        <x-page-header title="أرشيف المواسم" icon="calendar" subtitle="كل المواسم التي انتهت أو أُرشفت، من الأحدث إلى الأقدم." :back="route('seasons.index')" back-label="المواسم الرسمية" class="anim-fade-up" />

        @if ($items->isEmpty())
            <x-empty-state icon="calendar" title="لا توجد مواسم في الأرشيف بعد" message="ستظهر هنا المواسم بعد انتهائها." class="anim-fade-up" />
        @else
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ($items as $item)
                    @include('seasons._card', ['item' => $item, 'userCompleted' => $item->user_completed])
                @endforeach
            </div>

            <div class="mt-8">{{ $page->links() }}</div>
        @endif
    </div>
@endsection
