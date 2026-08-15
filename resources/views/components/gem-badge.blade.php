@props(['amount'])

<span class="inline-flex items-center gap-1.5 bg-white/10 rounded-full pl-3 pr-2 py-1 text-sm font-bold text-gold">
    <span class="w-3.5 h-3.5 bg-gold gem-facet inline-block"></span>
    {{ number_format($amount) }}
</span>
