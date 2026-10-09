{{--
    شارة مستوى الصعوبة (سهل/متوسط/صعب) - نسخة موحّدة تحل محل نفس المنطق
    المكرّر سابقاً بـ puzzles/index وpuzzles/show وchallenges/show.
    الاستخدام: <x-difficulty-badge :difficulty="$puzzle->difficulty" />
    E23: تُرسم عبر status-badge الموحَّدة (المتوسط كان amber بلا بديل للثيم الفاتح فصار نبرة warning القابلة للقراءة بالثيمين).
--}}
@props(['difficulty'])

@php
    $label = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'][$difficulty] ?? $difficulty;
    $tone = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'][$difficulty] ?? 'neutral';
@endphp

<x-status-badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-status-badge>
