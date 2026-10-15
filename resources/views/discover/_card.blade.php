@php /** @var \App\Services\Discovery\DiscoverCard $card */ @endphp
{{-- E27: بطاقة اكتشاف موحَّدة. البطاقة كلها رابط واحد. النصوص مهرَّبة؛ الرابط من route() بالخادم. «السبب» يشرح لماذا ظهرت (شفافية التوصية) ولا يكشف نشاط أي لاعب آخر. --}}
<a href="{{ $card->url }}" class="glass rounded-2xl p-4 flex flex-col h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
    <div class="flex items-start justify-between gap-2 mb-2">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$card->icon" class="w-5 h-5" /></span>
        @if ($card->badge)
            <x-status-badge :tone="$card->badgeTone ?? 'neutral'" :small="true">{{ $card->badge }}</x-status-badge>
        @endif
    </div>
    <h3 class="font-display font-black text-white leading-snug break-words">{{ $card->title }}</h3>
    @if ($card->subtitle)
        <p class="text-xs text-slate-400 mt-1">{{ $card->subtitle }}</p>
    @endif
    @if ($card->reason)
        <p class="mt-auto pt-3 text-[11px] font-bold text-amethyst">{{ $card->reason }}</p>
    @endif
</a>
