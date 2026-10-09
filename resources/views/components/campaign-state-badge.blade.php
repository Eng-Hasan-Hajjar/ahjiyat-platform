{{--
    شارة حالة عنصر بمحرك الحملات (Step أو Gate). الحالات العامة (completed/
    locked/in_progress/available/not_qualified) قادمة من CampaignProgressService
    مباشرة. technical_pending/content_pending حالتان إداريتان فقط (لا تُمرَّران
    أبداً لغير Admin من الـController).
    E23: تُرسم عبر status-badge الموحَّدة (نبرة + أيقونة للمقفل/المكتمل) دون تغيير أي حالة أو نص.
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

    $tone = [
        'completed' => 'success',
        'locked' => 'neutral',
        'in_progress' => 'warning',
        'available' => 'primary',
        'not_qualified' => 'danger',
        'technical_pending' => 'warning',
        'content_pending' => 'primary',
    ][$state] ?? 'neutral';

    $icon = ['completed' => 'check-circle', 'locked' => 'lock'][$state] ?? null;
@endphp

<x-status-badge :tone="$tone" :icon="$icon" :small="$small" :dashed="in_array($state, ['technical_pending', 'content_pending'], true)" {{ $attributes }}>{{ $label }}</x-status-badge>
