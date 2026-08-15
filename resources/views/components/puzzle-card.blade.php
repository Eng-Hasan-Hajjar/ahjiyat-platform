@props(['puzzle'])

@php
    $difficultyLabel = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$puzzle->difficulty] ?? $puzzle->difficulty;
    $difficultyColor = ['easy' => 'emerald', 'medium' => 'gold', 'hard' => 'rose'][$puzzle->difficulty] ?? 'ink';
@endphp

<a href="{{ route('puzzles.show', $puzzle) }}"
   class="block bg-white rounded-xl border border-ink/10 p-5 hover:border-amethyst hover:shadow-md transition">
    <div class="flex items-center justify-between mb-3">
        <span class="text-xs font-bold px-2 py-1 rounded-full bg-{{ $difficultyColor }}-50 text-{{ $difficultyColor }}">
            {{ $difficultyLabel }}
        </span>
        <span class="inline-flex items-center gap-1 text-sm font-bold text-gold">
            <span class="w-3 h-3 bg-gold gem-facet inline-block"></span>
            {{ $puzzle->gem_reward }}
        </span>
    </div>
    <h3 class="font-display font-bold text-lg text-ink mb-1">{{ $puzzle->title }}</h3>
    <p class="text-sm text-ink/60">{{ $puzzle->category->name ?? '' }}</p>
</a>
