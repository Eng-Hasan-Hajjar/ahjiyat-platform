@extends('layouts.app')
@section('title', 'ملف المستخدم')

@push('styles')
<style>
    .stat-pill {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 18px 24px;
        background: rgba(255,255,255,.04);
        border: 1px solid var(--border);
        border-radius: 14px;
        min-width: 110px;
        text-align: center;
    }
    .stat-num {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 30px;
        font-weight: 700;
        color: #fff;
        line-height: 1;
    }
    .stat-lbl {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 5px;
        font-weight: 500;
    }

    .log-row {
        background: var(--surface2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px 18px;
        transition: border-color .2s;
        cursor: pointer;
    }
    .log-row:hover { border-color: rgba(249,115,22,.4); }

    .cf-bar-wrap {
        height: 6px;
        background: rgba(255,255,255,.06);
        border-radius: 3px;
        width: 80px;
    }
    .cf-bar-fill {
        height: 6px;
        border-radius: 3px;
        transition: width .6s ease;
    }

    .severity-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-left: 5px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-muted);
    }
    .empty-state i {
        font-size: 52px;
        display: block;
        margin-bottom: 14px;
        opacity: .4;
    }
</style>
@endpush

@section('content')

{{-- ── Topbar ──────────────────────────────────────── --}}
<div class="topbar">
    <div>
        <div class="topbar-title">
            <i class="bi bi-person-circle me-2" style="color:var(--primary)"></i>
            ملفي الشخصي
        </div>
        <div class="topbar-subtitle">سجل تشخيصاتي وإحصائياتي</div>
    </div>
    <a href="{{ route('diagnosis.index') }}" class="btn-primary-custom">
        <i class="bi bi-search-heart"></i> تشخيص جديد
    </a>
</div>

{{-- ── Profile Card ────────────────────────────────── --}}
<div class="card-dark mb-4">
    <div class="d-flex align-items-center gap-4 flex-wrap">

        {{-- Avatar --}}
        <div style="width:72px;height:72px;border-radius:50%;flex-shrink:0;
                    background:linear-gradient(135deg,var(--primary),var(--accent));
                    display:flex;align-items:center;justify-content:center;
                    font-size:30px;font-weight:800;color:#fff;
                    box-shadow:0 6px 20px rgba(249,115,22,.3)">
            {{ mb_substr(auth()->user()->name, 0, 1) }}
        </div>

        {{-- Info --}}
        <div style="flex:1;min-width:0">
            <h2 style="font-size:20px;font-weight:800;color:#fff;margin:0 0 4px">
                {{ auth()->user()->name }}
            </h2>
            <div style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:6px">
                <i class="bi bi-envelope"></i>
                {{ auth()->user()->email }}
            </div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:6px">
                <i class="bi bi-calendar3"></i>
                عضو منذ: {{ auth()->user()->created_at->format('Y/m/d') }}
            </div>
        </div>

        {{-- Stats Pills --}}
        <div class="d-flex gap-3 flex-wrap">
            <div class="stat-pill">
                <span class="stat-num" style="color:var(--primary)">{{ $totalDiagnoses }}</span>
                <span class="stat-lbl">تشخيص كلي</span>
            </div>
            <div class="stat-pill">
                <span class="stat-num" style="color:var(--accent)">{{ $thisMonth }}</span>
                <span class="stat-lbl">هذا الشهر</span>
            </div>
            <div class="stat-pill">
                <span class="stat-num" style="color:var(--success)">
                    {{ $avgCF ? number_format($avgCF * 100, 0) . '%' : '—' }}
                </span>
                <span class="stat-lbl">متوسط الثقة</span>
            </div>
            <div class="stat-pill">
                <span class="stat-num" style="color:var(--warning)">{{ $uniqueFaults }}</span>
                <span class="stat-lbl">عطل مختلف</span>
            </div>
        </div>

    </div>
</div>

{{-- ── Most Common Fault ───────────────────────────── --}}
@if($mostCommonFault)
<div class="card-dark mb-4"
     style="border-color:rgba(249,115,22,.25);
            background:linear-gradient(135deg,rgba(249,115,22,.07),rgba(249,115,22,.02))">
    <div class="d-flex align-items-center gap-3">
        <div style="width:44px;height:44px;border-radius:10px;flex-shrink:0;
                    background:rgba(249,115,22,.15);
                    display:flex;align-items:center;justify-content:center;font-size:20px">
            🔧
        </div>
        <div>
            <div style="font-size:11px;color:var(--text-muted);font-weight:600;
                        text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">
                العطل الأكثر تكراراً في تشخيصاتك
            </div>
            <div style="font-size:17px;font-weight:800;color:#fff">
                {{ $mostCommonFault->top_fault_name }}
            </div>
            <div style="font-size:12px;color:var(--primary)">
                ظهر {{ $mostCommonFault->count }} {{ $mostCommonFault->count == 1 ? 'مرة' : 'مرات' }}
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── Diagnosis History ───────────────────────────── --}}
<div class="card-dark">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div style="font-size:16px;font-weight:700;color:#fff">
            <i class="bi bi-clock-history me-2" style="color:var(--primary)"></i>
            سجل التشخيصات
        </div>
        @if($logs->total() > 0)
        <span style="font-family:'Space Grotesk',sans-serif;font-size:12px;
                     color:var(--text-muted);background:rgba(255,255,255,.05);
                     padding:4px 12px;border-radius:20px">
            {{ $logs->total() }} تشخيص
        </span>
        @endif
    </div>

    @if($logs->isEmpty())
        <div class="empty-state">
            <i class="bi bi-journal-x"></i>
            <div style="font-size:17px;font-weight:700;color:#fff;margin-bottom:8px">
                لا توجد تشخيصات بعد
            </div>
            <p style="font-size:13px;margin-bottom:20px">
                ابدأ بتشخيص سيارتك وستظهر نتائجك هنا
            </p>
            <a href="{{ route('diagnosis.index') }}" class="btn-primary-custom"
               style="text-decoration:none">
                <i class="bi bi-search-heart"></i> ابدأ التشخيص
            </a>
        </div>
    @else
        <div class="d-flex flex-column gap-3">
            @foreach($logs as $log)
            <div class="log-row">
                <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">

                    {{-- Left: fault name + date --}}
                    <div style="flex:1;min-width:0">
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                            {{-- Number badge --}}
                            <span style="font-family:'Space Grotesk',sans-serif;font-size:11px;
                                         color:var(--text-muted);background:rgba(255,255,255,.06);
                                         padding:2px 8px;border-radius:4px">
                                #{{ $log->id }}
                            </span>

                            {{-- Date --}}
                            <span style="font-size:11px;color:var(--text-muted)">
                                <i class="bi bi-calendar3" style="font-size:10px"></i>
                                {{ $log->created_at->format('Y/m/d  H:i') }}
                                &nbsp;·&nbsp;
                                {{ $log->created_at->diffForHumans() }}
                            </span>
                        </div>

                        {{-- Top fault --}}
                        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:4px">
                            {{ $log->top_fault_name ?? 'لم يُعثر على نتائج' }}
                        </div>

                        {{-- Symptoms used --}}
                        <div style="font-size:12px;color:var(--text-muted)">
                            <i class="bi bi-ui-checks" style="font-size:11px"></i>
                            {{ count($log->selected_symptom_ids ?? []) }} أعراض مُدخلة
                            &nbsp;·&nbsp;
                            <i class="bi bi-list-check" style="font-size:11px"></i>
                            {{ count($log->result ?? []) }} عطل محتمل
                        </div>

                        {{-- Other faults (if any) --}}
                        @if(!empty($log->result) && count($log->result) > 1)
                        <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:8px">
                            @foreach(array_slice($log->result, 1, 3) as $r)
                            <span style="background:rgba(255,255,255,.05);
                                         color:var(--text-muted);
                                         padding:2px 10px;border-radius:20px;font-size:11px">
                                {{ $r['fault_name'] }}
                                <span style="font-family:'Space Grotesk',sans-serif;opacity:.7">
                                    ({{ number_format($r['cf_percent'], 0) }}%)
                                </span>
                            </span>
                            @endforeach
                            @if(count($log->result) > 4)
                            <span style="color:var(--text-muted);font-size:11px;padding:2px 4px">
                                +{{ count($log->result) - 4 }} أخرى
                            </span>
                            @endif
                        </div>
                        @endif
                    </div>

                    {{-- Right: CF --}}
                    @if($log->top_cf)
                    @php $cf = $log->top_cf; @endphp
                    <div style="text-align:center;flex-shrink:0">
                        {{-- SVG Ring --}}
                        <div style="position:relative;width:60px;height:60px;margin:0 auto 4px">
                            <svg width="60" height="60" viewBox="0 0 60 60"
                                 style="transform:rotate(-90deg)">
                                <circle cx="30" cy="30" r="23"
                                        fill="none"
                                        stroke="rgba(255,255,255,.06)"
                                        stroke-width="5"/>
                                <circle cx="30" cy="30" r="23"
                                        fill="none"
                                        stroke="{{ $cf>=0.8 ? '#22c55e' : ($cf>=0.5 ? '#f59e0b' : '#ef4444') }}"
                                        stroke-width="5"
                                        stroke-linecap="round"
                                        stroke-dasharray="{{ 2 * pi() * 23 }}"
                                        stroke-dashoffset="{{ 2 * pi() * 23 * (1 - $cf) }}"/>
                            </svg>
                            <div style="position:absolute;inset:0;display:flex;align-items:center;
                                        justify-content:center;font-family:'Space Grotesk',sans-serif;
                                        font-size:13px;font-weight:700;
                                        color:{{ $cf>=0.8 ? '#22c55e' : ($cf>=0.5 ? '#f59e0b' : '#ef4444') }}">
                                {{ number_format($cf*100, 0) }}%
                            </div>
                        </div>
                        <div style="font-size:10px;color:var(--text-muted)">درجة الثقة</div>
                    </div>
                    @endif

                </div>
            </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</div>

@endsection