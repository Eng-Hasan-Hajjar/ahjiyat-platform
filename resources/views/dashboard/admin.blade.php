@extends('layouts.app')
@section('title', 'لوحة تحكم الإدارة')

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-speedometer2 me-2"></i>لوحة تحكم الإدارة</h1>
    <p>مرحباً {{ $user->name }} — نظرة شاملة على حالة المنصة الآن</p>
</div>

{{-- ═══ المستخدمون ═══ --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card stat-card green">
            <div class="stat-number" style="color:var(--primary);">{{ $stats['total_users'] }}</div>
            <div class="stat-label">إجمالي المستخدمين</div>
            <div class="stat-icon">👥</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card blue">
            <div class="stat-number" style="color:#1d4ed8;">{{ $stats['total_volunteers'] }}</div>
            <div class="stat-label">متطوعون</div>
            <div class="stat-icon">🙋</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card amber">
            <div class="stat-number" style="color:#b45309;">{{ $stats['total_owners'] }}</div>
            <div class="stat-label">أصحاب مشاريع</div>
            <div class="stat-icon">🏪</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card" style="background:linear-gradient(135deg,#f3e8ff,#e2caf9);">
            <div class="stat-number" style="color:#7c3aed;">{{ $stats['total_committee'] }}</div>
            <div class="stat-label">أعضاء لجنة التحقق</div>
            <div class="stat-icon">⚖️</div>
        </div>
    </div>
</div>

{{-- ═══ المشاريع ═══ --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
            <div class="stat-number" style="color:#15803d;">{{ $stats['total_projects'] }}</div>
            <div class="stat-label">إجمالي المشاريع</div>
            <div class="stat-icon">🏗️</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card amber">
            <div class="stat-number" style="color:#b45309;">{{ $stats['pending_projects'] }}</div>
            <div class="stat-label">بانتظار الموافقة</div>
            <div class="stat-icon">⏳</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card blue">
            <div class="stat-number" style="color:#1d4ed8;">{{ $stats['active_projects'] }}</div>
            <div class="stat-label">قيد التنفيذ</div>
            <div class="stat-icon">⚡</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card green">
            <div class="stat-number" style="color:var(--primary);">{{ $stats['completed_projects'] }}</div>
            <div class="stat-label">مكتملة</div>
            <div class="stat-icon">✅</div>
        </div>
    </div>
</div>

{{-- ═══ بنود بانتظار إجراء ═══ --}}
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-circle-fill text-warning"></i>
        <strong>بنود بانتظار إجراء منك</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <a href="{{ route('committee.index') }}" class="d-block text-decoration-none" style="color:inherit;">
                    <div style="border:1.5px solid #fde68a;background:#fffbeb;border-radius:12px;padding:16px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div style="font-weight:900;font-size:1.5rem;color:#b45309;">{{ $actionItems['pending_verifications'] }}</div>
                                <div style="font-size:.85rem;color:var(--text-mid);">مشاريع بانتظار مراجعة اللجنة</div>
                            </div>
                            <i class="bi bi-clipboard-check" style="font-size:1.8rem;color:#fbbf24;"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ route('admin.projects') }}" class="d-block text-decoration-none" style="color:inherit;">
                    <div style="border:1.5px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:16px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div style="font-weight:900;font-size:1.5rem;color:#1d4ed8;">{{ $actionItems['pending_finance'] }}</div>
                                <div style="font-size:.85rem;color:var(--text-mid);">حركات مالية بانتظار الاعتماد</div>
                            </div>
                            <i class="bi bi-cash-coin" style="font-size:1.8rem;color:#60a5fa;"></i>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ route('admin.projects') }}" class="d-block text-decoration-none" style="color:inherit;">
                    <div style="border:1.5px solid #fecaca;background:#fef2f2;border-radius:12px;padding:16px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div style="font-weight:900;font-size:1.5rem;color:#b91c1c;">{{ $actionItems['pending_consents'] }}</div>
                                <div style="font-size:.85rem;color:var(--text-mid);">موافقات خطية بانتظار الاعتماد</div>
                            </div>
                            <i class="bi bi-file-earmark-check" style="font-size:1.8rem;color:#f87171;"></i>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

{{-- ═══ الملخص المالي ═══ --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div style="font-size:1.8rem;font-weight:900;color:#15803d;">{{ number_format($financeSummary['verified_donations'], 0) }}</div>
                <div class="text-muted small">إجمالي التبرعات الموثّقة والمعتمدة (ل.س)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div style="font-size:1.8rem;font-weight:900;color:#b91c1c;">{{ number_format($financeSummary['verified_expenses'], 0) }}</div>
                <div class="text-muted small">إجمالي المصروفات الموثّقة والمعتمدة (ل.س)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <div style="font-size:1.8rem;font-weight:900;color:#1d4ed8;">{{ number_format($stats['total_donations'], 0) }}</div>
                <div class="text-muted small">تبرعات سريعة عبر صفحة المشروع (ل.س)</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══ توزيع المشاريع ═══ --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>توزيع المشاريع حسب الحالة</div>
            <div class="card-body">
                @php
                    $statusLabels = ['pending'=>'بانتظار الموافقة','approved'=>'موافق عليه','in_progress'=>'قيد التنفيذ','completed'=>'مكتمل','cancelled'=>'ملغي','rejected'=>'مرفوض'];
                    $statusColors = ['pending'=>'#f59e0b','approved'=>'#3b82f6','in_progress'=>'#2E7D4F','completed'=>'#16a34a','cancelled'=>'#6b7280','rejected'=>'#ef4444'];
                    $maxCount = $projectsByStatus->max() ?: 1;
                @endphp
                @forelse($projectsByStatus as $status => $count)
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small style="font-weight:600;">{{ $statusLabels[$status] ?? $status }}</small>
                        <small style="font-weight:700;">{{ $count }}</small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width:{{ ($count / $maxCount) * 100 }}%;background:{{ $statusColors[$status] ?? '#6b7280' }};"></div>
                    </div>
                </div>
                @empty
                <div class="text-muted text-center py-3">لا توجد مشاريع بعد</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-geo-alt me-2"></i>أكثر المدن نشاطاً بالمشاريع</div>
            <div class="card-body">
                @php $maxCity = $projectsByCity->max() ?: 1; @endphp
                @forelse($projectsByCity as $city => $count)
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small style="font-weight:600;">{{ $city ?: 'غير محدد' }}</small>
                        <small style="font-weight:700;">{{ $count }}</small>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width:{{ ($count / $maxCity) * 100 }}%;background:var(--primary);"></div>
                    </div>
                </div>
                @empty
                <div class="text-muted text-center py-3">لا توجد بيانات بعد</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- ═══ أحدث المشاريع والمستخدمين ═══ --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>أحدث المشاريع المضافة</span>
                <a href="{{ route('admin.projects') }}" class="btn btn-sm btn-outline-primary">عرض الكل</a>
            </div>
            <div class="card-body p-0">
                @forelse($recentProjects as $p)
                <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                    <div>
                        <div style="font-weight:700;font-size:.9rem;">#{{ $p->id }} — {{ Str::limit($p->title, 35) }}</div>
                        <small class="text-muted">{{ $p->owner->name ?? 'مستخدم محذوف' }} · {{ $p->city }}</small>
                    </div>
                    <span class="badge status-{{ $p->status }}">{{ $p->status_arabic }}</span>
                </div>
                @empty
                <div class="p-4 text-center text-muted">لا توجد مشاريع بعد</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-plus me-2"></i>أحدث المستخدمين</span>
                <a href="{{ route('admin.users') }}" class="btn btn-sm btn-outline-primary">عرض الكل</a>
            </div>
            <div class="card-body p-0">
                @forelse($recentUsers as $u)
                <div class="d-flex align-items-center gap-2 p-3 border-bottom">
                    <img src="{{ $u->avatar_url }}" style="width:36px;height:36px;border-radius:50%;">
                    <div class="flex-grow-1">
                        <div style="font-weight:600;font-size:.88rem;">{{ $u->name }}</div>
                        <small class="text-muted">{{ $u->role_arabic }}</small>
                    </div>
                    <small class="text-muted">{{ $u->created_at->diffForHumans() }}</small>
                </div>
                @empty
                <div class="p-4 text-center text-muted">لا يوجد مستخدمون بعد</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- ═══ آخر الإعلانات ═══ --}}
@if($announcements->isNotEmpty())
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-megaphone me-2"></i>آخر الإعلانات المنشورة</span>
        <a href="{{ route('admin.announcements') }}" class="btn btn-sm btn-outline-primary">إدارة الإعلانات</a>
    </div>
    <div class="card-body p-0">
        @foreach($announcements as $a)
        <div class="p-3 border-bottom">
            <div style="font-weight:700;font-size:.9rem;">{{ $a->title }}</div>
            <small class="text-muted">{{ Str::limit($a->content, 100) }}</small>
        </div>
        @endforeach
    </div>
</div>
@endif
@endsection