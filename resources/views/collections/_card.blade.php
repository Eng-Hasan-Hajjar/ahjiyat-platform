@php
    /** @var object{public_id: string, title: string, description: ?string, saves_count: int, visible_count: int} $c */
@endphp
{{-- E35: بطاقة مجموعة عامة (قسم ملف اللاعب). البطاقة كلها رابط واحد؛ النصوص مهرَّبة؛ الرابط من route() بالخادم. --}}
<a href="{{ route('collections.public.show', $c->public_id) }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="inventory" class="w-5 h-5" /></span>
        <x-status-badge tone="primary" :small="true">مجموعة</x-status-badge>
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $c->title }}</h3>
    @if ($c->description) <p class="text-xs text-slate-400 mt-1 break-words line-clamp-2">{{ $c->description }}</p> @endif
    <p class="mt-auto pt-3 text-[11px] font-bold text-slate-400">{{ number_format($c->visible_count) }} أحجية · {{ number_format($c->saves_count) }} حفظ</p>
</a>
