@props(['status', 'small' => false])

{{--
    E28: شارة حالة نسخة تأليف (مسودة/قيد المراجعة/تحتاج تعديلات/منشورة/مرفوضة) بمكوّن الشارة الموحَّد (E23). النص دائمًا موجود (لا اعتماد على اللون وحده).
--}}
@php
    $map = [
        'draft' => ['neutral', 'مسودة'],
        'pending_review' => ['warning', 'قيد المراجعة'],
        'changes_requested' => ['warning', 'تحتاج تعديلات'],
        'approved' => ['success', 'منشورة'],
        'rejected' => ['danger', 'مرفوضة'],
        'archived' => ['neutral', 'مؤرشفة'],
        'hidden' => ['danger', 'مخفية من الإشراف'],
    ];
    [$tone, $label] = $map[$status] ?? ['neutral', $status];
@endphp
<x-status-badge :tone="$tone" :small="$small">{{ $label }}</x-status-badge>
