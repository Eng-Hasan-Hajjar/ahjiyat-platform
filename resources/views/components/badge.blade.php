@props(['type' => 'default', 'text'])

@php
$styles = [
    'success' => 'background:#dcfce7;color:#15803d;',
    'danger'  => 'background:#fecaca;color:#b91c1c;',
    'warning' => 'background:#fef3c7;color:#b45309;',
    'info'    => 'background:#dbeafe;color:#1d4ed8;',
    'primary' => 'background:var(--primary-pale);color:var(--primary);',
    'default' => 'background:#f1f5f9;color:#475569;',
];
$style = $styles[$type] ?? $styles['default'];
@endphp

<span class="badge" style="{{ $style }}border-radius:20px;font-size:.78rem;padding:4px 10px;">
    {{ $text ?? $slot }}
</span>