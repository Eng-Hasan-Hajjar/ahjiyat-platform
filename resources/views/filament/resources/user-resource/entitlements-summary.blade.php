<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:.8rem;">
    @forelse ($entitlements as $entitlement)
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.85rem; margin-bottom:.3rem;">{{ $entitlement->item->name ?? $entitlement->key }}</div>
            @if ($entitlement->revoked_at)
                <div style="color:#fb7185; font-size:.75rem; font-weight:700;">مُلغى ({{ $entitlement->revoked_at->format('Y-m-d') }})</div>
            @elseif ($entitlement->isActive())
                <div style="color:#34d399; font-size:.75rem; font-weight:700;">فعّال{{ $entitlement->expires_at ? ' - ينتهي '.$entitlement->expires_at->format('Y-m-d') : ' (دائم)' }}</div>
            @else
                <div style="color:#64748b; font-size:.75rem; font-weight:700;">منتهٍ</div>
            @endif
        </div>
    @empty
        <p style="color:#64748b;">لا امتيازات لهذا المستخدم.</p>
    @endforelse
</div>