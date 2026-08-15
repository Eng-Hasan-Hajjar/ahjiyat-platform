@extends('layouts.app')
@section('title', 'التقارير والإحصائيات')
@section('content')

<div class="page-header mb-4">
    <h1><i class="bi bi-bar-chart-line me-2"></i>التقارير والإحصائيات</h1>
    <p>نظرة شاملة على أداء المنصة وإنجازاتها</p>
</div>

<div class="row g-4 mb-4">

    {{-- المشاريع حسب الحالة --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-pie-chart me-2 text-primary"></i>المشاريع حسب الحالة
            </div>
            <div class="card-body">
                @php
                    $statusLabels = [
                        'pending'     => 'معلق',
                        'approved'    => 'موافق',
                        'in_progress' => 'جارٍ',
                        'completed'   => 'مكتمل',
                        'rejected'    => 'مرفوض',
                        'cancelled'   => 'ملغي',
                    ];
                    $statusColors = [
                        'pending'     => '#f59e0b',
                        'approved'    => '#3b82f6',
                        'in_progress' => '#06b6d4',
                        'completed'   => '#22c55e',
                        'rejected'    => '#ef4444',
                        'cancelled'   => '#6b7280',
                    ];
                    $total = $stats['projects_by_status']->sum() ?: 1;
                @endphp

                @foreach($stats['projects_by_status'] as $status => $count)
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-weight:600;font-size:.9rem;">
                            {{ $statusLabels[$status] ?? $status }}
                        </span>
                        <div>
                            <span style="font-weight:700;">{{ $count }}</span>
                            <span class="text-muted" style="font-size:.82rem;">
                                ({{ round(($count / $total) * 100) }}%)
                            </span>
                        </div>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar"
                             style="width:{{ ($count/$total)*100 }}%;
                                    background:{{ $statusColors[$status] ?? '#6b7280' }};
                                    border-radius:5px;">
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- المشاريع حسب النوع --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-grid me-2 text-primary"></i>المشاريع حسب النوع
            </div>
            <div class="card-body">
                @php
                    $typeLabels = [
                        'shop'       => 'محل',
                        'workshop'   => 'ورشة',
                        'clinic'     => 'عيادة',
                        'bakery'     => 'مخبز',
                        'restaurant' => 'مطعم',
                        'school'     => 'مدرسة',
                        'mosque'     => 'مسجد',
                        'pharmacy'   => 'صيدلية',
                        'other'      => 'أخرى',
                    ];
                    $typeIcons = [
                        'shop'       => '🏪',
                        'workshop'   => '🔧',
                        'clinic'     => '🏥',
                        'bakery'     => '🥖',
                        'restaurant' => '🍽️',
                        'school'     => '🏫',
                        'mosque'     => '🕌',
                        'pharmacy'   => '💊',
                        'other'      => '📦',
                    ];
                @endphp
                <div class="row g-2">
                    @foreach($stats['projects_by_type'] as $type => $count)
                    <div class="col-6">
                        <div class="d-flex align-items-center gap-2 p-2 rounded-2"
                             style="background:var(--bg-main);">
                            <span style="font-size:1.3rem;">
                                {{ $typeIcons[$type] ?? '📦' }}
                            </span>
                            <div>
                                <div style="font-weight:600;font-size:.88rem;">
                                    {{ $typeLabels[$type] ?? $type }}
                                </div>
                                <div style="font-weight:800;color:var(--primary);font-size:.95rem;">
                                    {{ $count }}
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- المشاريع حسب المدينة --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-geo-alt me-2 text-primary"></i>المشاريع حسب المدينة (أعلى 10)
            </div>
            <div class="card-body">
                @php $cityTotal = $stats['projects_by_city']->sum() ?: 1; @endphp
                @foreach($stats['projects_by_city'] as $city => $count)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span style="width:70px;font-size:.88rem;font-weight:600;">{{ $city }}</span>
                    <div class="flex-grow-1 progress" style="height:8px;">
                        <div class="progress-bar"
                             style="width:{{ ($count/$cityTotal)*100 }}%;
                                    background:var(--primary);
                                    border-radius:4px;">
                        </div>
                    </div>
                    <span style="width:25px;font-weight:700;font-size:.88rem;">{{ $count }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- المتطوعون حسب المدينة --}}
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-people me-2 text-primary"></i>المتطوعون حسب المدينة (أعلى 10)
            </div>
            <div class="card-body">
                @php $volTotal = $stats['volunteers_by_city']->sum() ?: 1; @endphp
                @foreach($stats['volunteers_by_city'] as $city => $count)
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span style="width:70px;font-size:.88rem;font-weight:600;">{{ $city }}</span>
                    <div class="flex-grow-1 progress" style="height:8px;">
                        <div class="progress-bar"
                             style="width:{{ ($count/$volTotal)*100 }}%;
                                    background:#3b82f6;
                                    border-radius:4px;">
                        </div>
                    </div>
                    <span style="width:25px;font-weight:700;font-size:.88rem;">{{ $count }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- المشاريع الشهرية --}}
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-graph-up me-2 text-primary"></i>
                المشاريع الجديدة شهرياً ({{ now()->year }})
            </div>
            <div class="card-body">
                @php
                    $months = [
                        1  => 'يناير',
                        2  => 'فبراير',
                        3  => 'مارس',
                        4  => 'أبريل',
                        5  => 'مايو',
                        6  => 'يونيو',
                        7  => 'يوليو',
                        8  => 'أغسطس',
                        9  => 'سبتمبر',
                        10 => 'أكتوبر',
                        11 => 'نوفمبر',
                        12 => 'ديسمبر',
                    ];
                    $maxVal = $stats['monthly_projects']->max() ?: 1;
                @endphp
                <div class="d-flex align-items-end gap-2" style="height:160px;">
                    @foreach($months as $num => $month)
                        @php
                            $count = $stats['monthly_projects'][$num] ?? 0;
                            $h     = $maxVal > 0 ? round(($count / $maxVal) * 130) : 0;
                        @endphp
                        <div class="flex-fill d-flex flex-column align-items-center gap-1">
                            <span style="font-size:.72rem;font-weight:700;color:var(--primary);">
                                {{ $count ?: '' }}
                            </span>
                            <div style="width:100%;
                                        height:{{ max($h, 4) }}px;
                                        background:{{ $count > 0 ? 'var(--primary)' : 'var(--border)' }};
                                        border-radius:4px 4px 0 0;
                                        transition:.3s;"
                                 title="{{ $month }}: {{ $count }} مشروع">
                            </div>
                            <span style="font-size:.65rem;color:var(--text-light);text-align:center;">
                                {{ mb_substr($month, 0, 3) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- أفضل المتطوعين --}}
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-trophy me-2 text-primary"></i>أفضل المتطوعين
            </div>
            <div class="card-body p-0">
                @forelse($stats['top_volunteers'] as $i => $vol)
                <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                    <div style="font-weight:900;font-size:1.1rem;width:28px;
                                color:{{ ['#fbbf24','#94a3b8','#a16207'][$i] ?? 'var(--text-mid)' }};">
                        {{ ['🥇','🥈','🥉'][$i] ?? ($i + 1) }}
                    </div>
                    <img src="{{ $vol->avatar_url }}"
                         style="width:38px;height:38px;border-radius:50%;border:2px solid var(--border);">
                    <div class="flex-grow-1">
                        <div style="font-weight:600;font-size:.9rem;">{{ $vol->name }}</div>
                        <small class="text-muted">{{ $vol->city ?? '' }}</small>
                    </div>
                    <div class="text-center">
                        <div style="font-weight:800;color:var(--primary);">
                            {{ $vol->volunteerProfile->points ?? 0 }}
                        </div>
                        <small class="text-muted" style="font-size:.72rem;">نقطة</small>
                    </div>
                </div>
                @empty
                <div class="p-3 text-center text-muted">لا توجد بيانات</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ملخص التبرعات --}}
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-heart me-2 text-primary"></i>ملخص التبرعات
            </div>
            <div class="card-body text-center py-4">
                <div style="font-size:3rem;margin-bottom:8px;">💰</div>
                <div style="font-size:2.5rem;font-weight:900;
                            font-family:'Cairo',sans-serif;color:var(--primary);">
                    {{ number_format($stats['total_donations']) }} ل.س
                </div>
                <p class="text-muted mt-2">إجمالي التبرعات المستلمة</p>
                <a href="{{ route('admin.donations') }}"
                   class="btn btn-outline-primary btn-sm mt-2">
                    عرض جميع التبرعات
                </a>
            </div>
        </div>
    </div>

</div>

@endsection