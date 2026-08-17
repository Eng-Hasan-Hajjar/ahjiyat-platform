{{--
    شارة مستوى الصعوبة (سهل/متوسط/صعب) - نسخة موحّدة تحل محل نفس المنطق
    المكرّر سابقاً بـ puzzles/index وpuzzles/show وchallenges/show.
    الاستخدام: <x-difficulty-badge :difficulty="$puzzle->difficulty" />
--}}
@props(['difficulty'])

@php
    $label = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$difficulty] ?? $difficulty;
    $style = [
        'easy' => 'bg-emerald/10 text-emerald border-emerald/30',
        'medium' => 'bg-amber-400/10 text-amber-300 border-amber-400/30',
        'hard' => 'bg-rose/10 text-rose border-rose/30',
    ][$difficulty] ?? 'bg-white/10 text-slate-300 border-white/20';
@endphp

<span {{ $attributes->merge(['class' => "rounded-full border px-3 py-1 text-xs font-black shrink-0 $style"]) }}>
    {{ $label }}
</span>