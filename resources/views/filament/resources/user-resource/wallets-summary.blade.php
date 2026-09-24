<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.8rem;">
    @forelse ($wallets as $wallet)
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.85rem; margin-bottom:.3rem;">{{ $wallet->currency->name }} ({{ $wallet->currency->code }})</div>
            <div style="font-size:1.2rem; font-weight:900;">{{ number_format($wallet->available_balance) }}</div>
            <div style="color:#94a3b8; font-size:.75rem;">متاح</div>
            @if ($wallet->pending_balance > 0)
                <div style="margin-top:.3rem; font-size:.85rem;">{{ number_format($wallet->pending_balance) }} <span style="color:#94a3b8; font-size:.75rem;">معلَّق</span></div>
            @endif
        </div>
    @empty
        <p style="color:#64748b;">لا محافظ لهذا المستخدم بعد.</p>
    @endforelse
</div>