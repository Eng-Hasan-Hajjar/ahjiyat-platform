{{--
    شارة حالة التحدي (مفتوح الآن / قريباً / انتهى) - نسخة موحّدة تحل محل نفس
    المنطق المكرّر سابقاً بـ challenges/index وchallenges/show.
    الاستخدام: <x-challenge-status-badge :challenge="$challenge" />
--}}
@props(['challenge'])

@php
    $now = now();
    $isOpen = $challenge->is_active && $now->between($challenge->starts_at, $challenge->ends_at);
    $isUpcoming = $challenge->starts_at->isFuture();

    $label = $isOpen ? 'مفتوح الآن' : ($isUpcoming ? 'قريباً' : 'انتهى');
    $style = $isOpen
        ? 'bg-emerald/10 text-emerald border-emerald/30'
        : ($isUpcoming ? 'bg-gold/10 text-gold border-gold/30' : 'bg-white/5 text-slate-400 border-white/10');
@endphp

<span {{ $attributes->merge(['class' => "rounded-full border px-3 py-1 text-xs font-black shrink-0 $style"]) }}>
    {{ $label }}
</span>