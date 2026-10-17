@extends('layouts.app')

@section('title', 'من أتابعهم')
@section('content_width', 'max-w-6xl')

{{-- E29: خلاصة أحدث ما نشره المبدعون الذين تتابعهم (الظاهر الآن فقط، الأحدث نشرًا أولًا). خاصة بك؛ لا تُعرض هويات المتابعين لأحد. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="من أتابعهم" icon="friends" subtitle="أحدث أحجيات المبدعين الذين تتابعهم." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('favorites.index') }}" class="chip !py-2 text-sm">مفضلتي</a>
                <a href="{{ route('community.puzzles.index') }}" class="chip !py-2 text-sm">أحجيات المجتمع</a>
            </x-slot:actions>
        </x-page-header>

        @if ($works->isEmpty())
            @if ($followsAny)
                <x-empty-state icon="puzzle" title="لا جديد الآن" message="لم ينشر المبدعون الذين تتابعهم أحجيات جديدة بعد. سنُخطرك عند النشر." :action="route('community.puzzles.index')" actionLabel="تصفّح أحجيات المجتمع" />
            @else
                <x-empty-state icon="friends" title="لا تتابع أي مبدع بعد" message="افتح صفحة أي مبدع من أحجيات المجتمع واضغط «متابعة» لتصلك أحدث أحجياته هنا." :action="route('community.puzzles.index')" actionLabel="اكتشف مبدعين" />
            @endif
        @else
            <section aria-label="أحدث أحجيات المتابَعين" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up">
                @foreach ($works as $work)
                    @include('community._card', ['work' => $work, 'showCreator' => true, 'savedIds' => $savedIds ?? []])
                @endforeach
            </section>

            <div class="flex justify-center">{{ $works->links() }}</div>
        @endif
    </div>
@endsection
