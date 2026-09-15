{{--
    شارة حالة عنصر بمحرك الحملات (Step أو Gate). الحالات العامة (completed/
    locked/in_progress/available/not_qualified) قادمة من CampaignProgressService
    مباشرة. technical_pending/content_pending حالتان إداريتان فقط (لا تُمرَّران
    أبداً لغير Admin من الـController).
--}}
@props(['state', 'small' => false])

@php
    $label = [
        'completed' => 'مكتملة',
        'locked' => 'مقفلة',
        'in_progress' => 'قيد التقدّم',
        'available' => 'متاحة',
        'not_qualified' => 'غير مؤهَّل',
        'technical_pending' => 'بانتظار قرار تقني',
        'content_pending' => 'محتوى مؤقت',
    ][$state] ?? $state;

    $style = [
        'completed' => 'bg-emerald/10 text-emerald border-emerald/30',
        'locked' => 'bg-white/5 text-slate-500 border-white/10',
        'in_progress' => 'bg-gold/10 text-gold border-gold/30',
        'available' => 'bg-amethyst/10 text-amethyst border-amethyst/30',
        'not_qualified' => 'bg-rose/10 text-rose border-rose/30',
        'technical_pending' => 'bg-gold/10 text-gold border-gold/30 border-dashed',
        'content_pending' => 'bg-cyan-400/10 text-cyan-300 border-cyan-400/30 border-dashed',
    ][$state] ?? 'bg-white/10 text-slate-300 border-white/20';

    $size = $small ? 'px-2 py-0.5 text-[10px]' : 'px-3 py-1 text-xs';
@endphp

<span {{ $attributes->merge(['class' => "rounded-full border font-black shrink-0 $style $size"]) }}>
    {{ $label }}
</span>