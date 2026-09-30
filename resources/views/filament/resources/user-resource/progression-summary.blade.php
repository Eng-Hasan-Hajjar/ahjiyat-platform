<div style="display:flex; flex-direction:column; gap:1rem;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:.8rem;">
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">المستوى الحالي</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $currentLevel->level_number }} — {{ $currentLevel->name }}</div>
        </div>
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">إجمالي XP</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ number_format($progression->total_xp) }}</div>
        </div>
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">XP للمستوى التالي</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $nextLevel ? number_format($nextLevel->xp_required_total) : 'أعلى مستوى' }}</div>
        </div>
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">إنجازات مفتوحة</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $achievementsUnlockedCount }}</div>
        </div>
    </div>

    <div>
        <div style="font-weight:800; font-size:.8rem; color:#94a3b8; margin-bottom:.4rem;">آخر معاملات XP</div>
        @if ($recentXp->isEmpty())
            <div style="color:#64748b; font-size:.85rem;">لا معاملات بعد.</div>
        @else
            <div style="display:flex; flex-direction:column; gap:.4rem;">
                @foreach ($recentXp as $tx)
                    <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem .6rem; border-radius:.5rem; background:rgba(255,255,255,.03);">
                        <span>{{ $tx->reason }}</span>
                        <span style="font-weight:800; color:#a78bfa;">+{{ $tx->amount }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div style="font-weight:800; font-size:.8rem; color:#94a3b8; margin-bottom:.4rem;">آخر فتح مستويات</div>
        @if ($recentUnlocks->isEmpty())
            <div style="color:#64748b; font-size:.85rem;">لا شيء بعد.</div>
        @else
            <div style="display:flex; flex-direction:column; gap:.4rem;">
                @foreach ($recentUnlocks as $unlock)
                    <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem .6rem; border-radius:.5rem; background:rgba(255,255,255,.03);">
                        <span>{{ $unlock->level->name }}</span>
                        <span style="color:#64748b;">{{ $unlock->unlocked_at->format('Y-m-d') }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>