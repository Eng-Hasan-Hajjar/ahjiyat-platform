<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.8rem;">
    @forelse ($items as $inventoryItem)
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.85rem; margin-bottom:.3rem;">{{ $inventoryItem->item->name ?? '—' }}</div>
            <div style="font-size:1.2rem; font-weight:900;">× {{ $inventoryItem->quantity }}</div>
        </div>
    @empty
        <p style="color:#64748b;">لا عناصر مملوكة لهذا المستخدم.</p>
    @endforelse
</div>