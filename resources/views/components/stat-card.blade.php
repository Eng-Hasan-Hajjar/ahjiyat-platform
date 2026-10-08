@props(['label', 'value', 'icon' => null, 'href' => null, 'hint' => null, 'tone' => 'default'])

{{--
    E22: بطاقة إحصاء/حالة صغيرة (مستوى، مهام، محفظة، رسائل...). رابط كامل إن وُجد href (هدف لمس واسع، تركيز مرئي)، وإلا بطاقة ثابتة. لا تأثيرات ثقيلة: التركيز على الرقم.
    الألوان من رموز الثيم (text-gold/amethyst تتبع color_accent/primary من E4).
--}}
@php
    $valueTone = match ($tone) { 'gold' => 'text-gold', 'amethyst' => 'text-amethyst', 'emerald' => 'text-emerald', default => 'text-white' };
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['glass rounded-2xl p-4 flex items-center gap-3 min-w-0', 'transition hover:border-amethyst/40 motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst' => (bool) $href]) }}>
    @if ($icon)
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-white/5 {{ $valueTone }}"><x-ui-icon :name="$icon" class="w-5 h-5" /></span>
    @endif
    <span class="min-w-0">
        <span class="block font-display font-black text-xl leading-tight {{ $valueTone }} truncate">{{ $value }}</span>
        <span class="block text-xs text-slate-400 truncate">{{ $label }}</span>
        @if ($hint)
            <span class="block text-[11px] text-slate-500 truncate">{{ $hint }}</span>
        @endif
    </span>
</{{ $tag }}>
