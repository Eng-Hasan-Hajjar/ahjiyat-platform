@php
    /** @var \App\Models\CreatorPuzzle $work */
    $rev = $work->latestRevision;
    $kinds = config('creators.kinds');
@endphp
{{-- E28: صف عمل المبدع. حالة النسخة الأخيرة + حالة النشر (منشور/مخفي/مؤرشف) معًا: عمل منشور بنسخة تعديل مسودة يعرض الاثنتين. نصوص مهرَّبة. --}}
<div class="glass rounded-2xl p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <a href="{{ route('creator.puzzles.edit', $work->public_id) }}" class="font-display font-black text-white hover:text-amethyst break-words focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $rev?->title ?? $work->published_title }}</a>
        <p class="text-xs text-slate-400 mt-1">
            {{ $kinds[$rev?->kind]['label'] ?? '' }}
            @if ($rev?->category) · {{ $rev->category->name }} @endif
            @if ($rev) · نسخة {{ $rev->revision_number }} @endif
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @if ($rev) <x-creator-status :status="$rev->status" :small="true" /> @endif
        @if ($work->isPublished() && ! $work->isArchived() && ! $work->isHidden()) <x-status-badge tone="success" :small="true" icon="globe">ظاهرة للعامة</x-status-badge> @endif
        @if ($work->isArchived()) <x-creator-status status="archived" :small="true" /> @endif
        @if ($work->isHidden()) <x-creator-status status="hidden" :small="true" /> @endif
        <a href="{{ route('creator.puzzles.edit', $work->public_id) }}" class="btn-secondary !py-1.5 !px-3 text-xs">فتح</a>
    </div>
</div>
