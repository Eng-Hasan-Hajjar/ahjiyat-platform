@extends('layouts.app')

@section('title', 'الأحجيات')

@section('content')
    <h1 class="font-display font-bold text-2xl text-ink mb-6">
        {{ $category?->name ?? 'كل الأحجيات' }}
    </h1>

    <div class="flex gap-2 overflow-x-auto mb-6 pb-2">
        <a href="{{ route('puzzles.index') }}"
           class="shrink-0 text-sm px-3 py-1.5 rounded-full {{ ! $category ? 'bg-amethyst text-white' : 'bg-white border border-ink/10 text-ink/70' }}">
            الكل
        </a>
        @foreach ($categories as $cat)
            <a href="{{ route('puzzles.category', $cat) }}"
               class="shrink-0 text-sm px-3 py-1.5 rounded-full {{ $category?->id === $cat->id ? 'bg-amethyst text-white' : 'bg-white border border-ink/10 text-ink/70' }}">
                {{ $cat->name }}
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        @forelse ($puzzles as $puzzle)
            <x-puzzle-card :puzzle="$puzzle" />
        @empty
            <p class="text-ink/60 col-span-3">لا توجد أحجيات بهذا التصنيف حالياً.</p>
        @endforelse
    </div>

    {{ $puzzles->links() }}
@endsection
