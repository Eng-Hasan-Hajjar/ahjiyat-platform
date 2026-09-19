@php
    $isSuperAdmin = $user->hasRole('super-admin');
@endphp

<div style="display:flex; flex-direction:column; gap:.4rem; font-size:.85rem;">
    @if ($isSuperAdmin)
        <span style="color:#f59e0b; font-weight:800;">🌟 مدير أعلى - وصول كامل غير مقيَّد لكل أجزاء المنصة.</span>
    @else
        <div>
            <strong>لوحة الإدارة:</strong>
            {{ $user->can('admin.access') ? '✅ مسموح' : '⛔ غير مسموح' }}
        </div>
        @foreach (config('permissions', []) as $key => $module)
            @continue($key === 'dashboard')
            @php
                $actions = collect($module['permissions'])->keys()->filter(fn ($p) => $user->can($p))
                    ->map(fn ($p) => $module['permissions'][$p]);
            @endphp
            <div>
                <strong>{{ $module['label'] }}:</strong>
                {{ $actions->isNotEmpty() ? $actions->implode(' + ') : '⛔ غير مسموح' }}
            </div>
        @endforeach
    @endif
</div>