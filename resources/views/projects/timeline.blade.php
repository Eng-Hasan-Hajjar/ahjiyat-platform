@extends('layouts.app')
@section('title', 'المتابعة الشاملة - ' . $project->title)

@push('styles')
<style>
.tl-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 28px; }
.tl-summary-card {
    background: #fff; border-radius: var(--radius-sm); padding: 18px;
    text-align: center; border: 1px solid var(--border);
}
.tl-summary-card .val { font-size: 1.6rem; font-weight: 900; font-family: 'Cairo', sans-serif; }
.tl-summary-card .lbl { font-size: .78rem; color: var(--text-mid); margin-top: 2px; }

.consent-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 16px; border-radius: 25px; font-size: .85rem; font-weight: 700;
}
.consent-pill.ok   { background: #dcfce7; color: #15803d; }
.consent-pill.no   { background: #fee2e2; color: #b91c1c; }

.timeline-wrap { position: relative; padding-right: 32px; }
.timeline-wrap::before {
    content: ''; position: absolute; right: 11px; top: 8px; bottom: 8px;
    width: 2px; background: var(--border);
}
.tl-event { position: relative; padding-bottom: 28px; }
.tl-event:last-child { padding-bottom: 0; }
.tl-dot {
    position: absolute; right: -32px; top: 2px;
    width: 24px; height: 24px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: .85rem; z-index: 1;
}
.tl-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 18px; }
.tl-date { font-size: .78rem; color: var(--text-light); margin-bottom: 4px; }
.tl-title { font-weight: 700; font-size: .94rem; margin-bottom: 2px; }
.tl-body { font-size: .85rem; color: var(--text-mid); }

.dot-success { background: #22c55e; }
.dot-warning { background: #f59e0b; }
.dot-info    { background: #3b82f6; }
.dot-danger  { background: #ef4444; }
.dot-primary { background: var(--primary); }

.quick-links { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
.quick-links a {
    flex: 1; min-width: 160px; background: #fff; border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); padding: 16px; text-decoration: none;
    text-align: center; transition: all .2s; color: var(--text-dark);
}
.quick-links a:hover { border-color: var(--primary); background: var(--primary-pale); transform: translateY(-2px); }
.quick-links .ic { font-size: 1.6rem; display: block; margin-bottom: 6px; }
</style>
@endpush

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-diagram-3 me-2"></i>المتابعة الشاملة للمشروع</h1>
    <p>{{ $project->title }} — نظرة موحّدة على كل ما يخص المشروع: المراحل، المالية، التوثيق، والموافقات</p>
</div>

@include('projects.nav-tabs', ['project' => $project, 'active' => 'timeline'])


{{-- Consent Status --}}
<div class="mb-4">
    @if($summary['has_approved_consent'])
        <span class="consent-pill ok"><i class="bi bi-patch-check-fill"></i>موافقة خطية معتمدة رسمياً</span>
    @elseif($summary['latest_approval'])
        <span class="consent-pill no"><i class="bi bi-hourglass-split"></i>الموافقة الخطية: {{ $summary['latest_approval']->status_arabic }}</span>
    @else
        <span class="consent-pill no"><i class="bi bi-exclamation-triangle-fill"></i>لا توجد موافقة خطية مرفوعة بعد</span>
    @endif
</div>

{{-- Summary Stats --}}
<div class="tl-summary-grid">
    <div class="tl-summary-card">
        <div class="val" style="color:var(--primary);">{{ $summary['progress_percentage'] }}%</div>
        <div class="lbl">نسبة الإنجاز</div>
    </div>
    <div class="tl-summary-card">
        <div class="val" style="color:#1d4ed8;">{{ $summary['milestones_done'] }}/{{ $summary['milestones_total'] }}</div>
        <div class="lbl">مراحل مكتملة</div>
    </div>
    <div class="tl-summary-card">
        <div class="val" style="color:#15803d;">{{ number_format($summary['total_donations'], 0) }}</div>
        <div class="lbl">تبرعات (ل.س)</div>
    </div>
    <div class="tl-summary-card">
        <div class="val" style="color:#b91c1c;">{{ number_format($summary['total_expenses'], 0) }}</div>
        <div class="lbl">مصروفات (ل.س)</div>
    </div>
    <div class="tl-summary-card">
        <div class="val" style="color:#7c3aed;">{{ $summary['media_count'] }}</div>
        <div class="lbl">ملف توثيقي</div>
    </div>
</div>

{{-- Progress Bar --}}
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
            <strong>التقدم الإجمالي للمشروع</strong>
            <strong style="color:var(--primary);">{{ $summary['progress_percentage'] }}%</strong>
        </div>
        <div class="progress" style="height:12px;">
            <div class="progress-bar" style="width:{{ $summary['progress_percentage'] }}%"></div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Milestones Mini List --}}
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-flag me-2 text-primary"></i>مراحل العمل</div>
            <div class="card-body p-0">
                @forelse($project->milestones as $m)
                <div class="p-3 border-bottom d-flex align-items-center gap-2">
                    <span class="badge status-{{ $m->status === 'completed' ? 'completed' : ($m->status === 'in_progress' ? 'in_progress' : 'pending') }}" style="flex-shrink:0;">
                        {{ $m->status === 'completed' ? '✓' : ($m->status === 'in_progress' ? '⏳' : '○') }}
                    </span>
                    <div class="flex-grow-1">
                        <div style="font-weight:600;font-size:.88rem;">{{ $m->title }}</div>
                        <small class="text-muted">{{ $m->status_arabic }}</small>
                    </div>
                </div>
                @empty
                <div class="p-3 text-center text-muted small">لا توجد مراحل مُعرّفة بعد</div>
                @endforelse
            </div>
        </div>

        {{-- Financial Mini Summary --}}
        <div class="card">
            <div class="card-header"><i class="bi bi-wallet2 me-2 text-primary"></i>الملخص المالي</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">التبرعات</span>
                    <strong style="color:#15803d;">{{ number_format($summary['total_donations'], 0) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">المصروفات</span>
                    <strong style="color:#b91c1c;">{{ number_format($summary['total_expenses'], 0) }}</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="fw-bold">الرصيد المتبقي</span>
                    <strong style="color:#1d4ed8;">{{ number_format($summary['balance'], 0) }}</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- Unified Timeline --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>الخط الزمني الشامل للمشروع</div>
            <div class="card-body">
                @if($timeline->isNotEmpty())
                <div class="timeline-wrap">
                    @foreach($timeline as $event)
                    <div class="tl-event">
                        <div class="tl-dot dot-{{ $event['color'] }}"><i class="bi {{ $event['icon'] }}"></i></div>
                        <div class="tl-card">
                            <div class="tl-date">{{ \Carbon\Carbon::parse($event['date'])->format('Y-m-d') }} — {{ \Carbon\Carbon::parse($event['date'])->diffForHumans() }}</div>
                            <div class="tl-title">{{ $event['title'] }}</div>
                            @if($event['body'])
                                <div class="tl-body">{{ $event['body'] }}</div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-5 text-muted">
                    <div style="font-size:2.5rem;">📋</div>
                    لا توجد أحداث مسجّلة بعد لهذا المشروع
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection