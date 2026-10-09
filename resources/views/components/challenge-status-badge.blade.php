{{--
    شارة حالة التحدي (مفتوح الآن / قريباً / انتهى) - نسخة موحّدة تحل محل نفس
    المنطق المكرّر سابقاً بـ challenges/index وchallenges/show.
    الاستخدام: <x-challenge-status-badge :challenge="$challenge" />
    E23: تُرسم عبر status-badge الموحَّدة.
--}}
@props(['challenge'])

@php
    $now = now();
    $isOpen = $challenge->is_active && $now->between($challenge->starts_at, $challenge->ends_at);
    $isUpcoming = $challenge->starts_at->isFuture();

    $label = $isOpen ? 'مفتوح الآن' : ($isUpcoming ? 'قريباً' : 'انتهى');
    $tone = $isOpen ? 'success' : ($isUpcoming ? 'warning' : 'neutral');
@endphp

<x-status-badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-status-badge>
