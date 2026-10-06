@props(['team', 'size' => 'h-12 w-12 text-lg'])
<span class="inline-flex shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amethyst to-gold font-black text-white {{ $size }}" aria-hidden="true">{{ mb_substr($team->name, 0, 1) }}</span>
