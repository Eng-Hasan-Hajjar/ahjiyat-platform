{{--
    شارة حالة عنصر بمحرك الحملات (Step أو Gate) - مشتقة بالكامل من
    CampaignProgressService::stepState()/gateState() سيرفرياً، لا اشتقاق
    هون إطلاقاً (C8.4). الاستخدام: <x-campaign-state-badge :state="$state" />
--}}
@props(['state', 'small' => false])

@php
    $label = [
        'completed' => 'مكتملة',
        'locked' => 'مقفلة',
        'in_progress' => 'قيد التقدّم',
        'available' => 'متاحة',
        'not_qualified' => 'غير مؤهَّل',
    ][$state] ?? $state;

    $style = [
        'completed' => 'bg-emerald/10 text-emerald border-emerald/30',
        'locked' => 'bg-white/5 text-slate-500 border-white/10',
        'in_progress' => 'bg-gold/10 text-gold border-gold/30',
        'available' => 'bg-amethyst/10 text-amethyst border-amethyst/30',
        'not_qualified' => 'bg-rose/10 text-rose border-rose/30',
    ][$state] ?? 'bg-white/10 text-slate-300 border-white/20';

    $size = $small ? 'px-2 py-0.5 text-[10px]' : 'px-3 py-1 text-xs';
@endphp

<span {{ $attributes->merge(['class' => "rounded-full border font-black shrink-0 $style $size"]) }}>
    {{ $label }}
</span>