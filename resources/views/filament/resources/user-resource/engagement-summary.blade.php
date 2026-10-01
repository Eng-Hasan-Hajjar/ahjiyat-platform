<div style="display:flex; flex-direction:column; gap:1rem;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:.8rem;">
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">السلسلة الحالية</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $streak->current_streak }} يوم</div>
        </div>
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">أطول سلسلة</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $streak->longest_streak }} يوم</div>
        </div>
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">آخر نشاط</div>
            <div style="font-weight:800; font-size:1.1rem;">{{ $streak->last_active_date?->format('Y-m-d') ?? '—' }}</div>
        </div>
    </div>

    <div>
        <div style="font-weight:800; font-size:.8rem; color:#94a3b8; margin-bottom:.4rem;">مهام اليوم</div>
        @if ($dailyQuests->isEmpty())
            <div style="color:#64748b; font-size:.85rem;">لا توجد مهام يومية نشطة.</div>
        @else
            <div style="display:flex; flex-direction:column; gap:.4rem;">
                @foreach ($dailyQuests as $row)
                    <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem .6rem; border-radius:.5rem; background:rgba(255,255,255,.03);">
                        <span>{{ $row['quest']->name }}</span>
                        <span style="font-weight:800; color:{{ $row['progress']->completed_at ? '#34d399' : '#a78bfa' }};">
                            {{ $row['progress']->current_value }}/{{ $row['progress']->target_value_snapshot }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div style="font-weight:800; font-size:.8rem; color:#94a3b8; margin-bottom:.4rem;">أهداف هذا الأسبوع</div>
        @if ($weeklyQuests->isEmpty())
            <div style="color:#64748b; font-size:.85rem;">لا توجد أهداف أسبوعية نشطة.</div>
        @else
            <div style="display:flex; flex-direction:column; gap:.4rem;">
                @foreach ($weeklyQuests as $row)
                    <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem .6rem; border-radius:.5rem; background:rgba(255,255,255,.03);">
                        <span>{{ $row['quest']->name }}</span>
                        <span style="font-weight:800; color:{{ $row['progress']->completed_at ? '#34d399' : '#a78bfa' }};">
                            {{ $row['progress']->current_value }}/{{ $row['progress']->target_value_snapshot }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
