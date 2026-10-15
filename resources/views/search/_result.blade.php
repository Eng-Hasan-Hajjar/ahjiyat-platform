@php /** @var \App\Services\Search\SearchResult $r */ @endphp
{{-- E27: صف نتيجة بحث. رابط واحد كامل الصف (هدف لمس واسع). كل النصوص مهرَّبة؛ الرابط من route() بالخادم لا من المستخدم. --}}
<li>
    <a href="{{ $r->url }}" class="flex items-center gap-3 px-4 py-3 transition motion-reduce:transition-none hover:bg-white/5 focus:outline-none focus-visible:bg-white/5 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amethyst">
        @if ($r->type === 'players')
            <x-player-avatar :name="$r->title" size="md" />
        @else
            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$r->icon" class="w-5 h-5" /></span>
        @endif
        <span class="min-w-0 flex-1 text-start">
            <span class="block font-black text-white truncate">{{ $r->title }}</span>
            @if ($r->subtitle)
                <span class="block text-xs text-slate-400 truncate">{{ $r->subtitle }}</span>
            @endif
        </span>
        @if ($r->badge)
            <x-status-badge :small="true">{{ $r->badge }}</x-status-badge>
        @endif
    </a>
</li>
