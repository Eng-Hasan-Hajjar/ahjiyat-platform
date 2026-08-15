@extends('layouts.app')
@section('title', 'لوحة اللجنة')

@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-clipboard-check me-2"></i>لوحة اللجنة</h1>
            <p>مرحباً {{ $user->name }} — راجع بيانات المشاريع المُدخلة قبل اعتمادها</p>
        </div>
        <a href="{{ route('committee.index') }}" class="btn btn-sm" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.4);font-weight:600;">
            <i class="bi bi-list-check me-1"></i>قائمة المراجعة
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);">
            <div class="stat-number" style="color:#b45309;">{{ $stats['pending_review'] }}</div>
            <div class="stat-label">مشاريع بانتظار المراجعة</div>
            <div class="stat-icon">⏳</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
            <div class="stat-number" style="color:#15803d;">{{ $stats['approved_by_me'] }}</div>
            <div class="stat-label">اعتمدتها بنفسي</div>
            <div class="stat-icon">✅</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card" style="background:linear-gradient(135deg,#fecaca,#fca5a5);">
            <div class="stat-number" style="color:#b91c1c;">{{ $stats['rejected_by_me'] }}</div>
            <div class="stat-label">رفضتها بنفسي</div>
            <div class="stat-icon">❌</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card blue">
            <div class="stat-number" style="color:#1d4ed8;">{{ $stats['total_reviewed'] }}</div>
            <div class="stat-label">إجمالي ما راجعته</div>
            <div class="stat-icon">📋</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-split me-2 text-warning"></i>أحدث المشاريع بانتظار المراجعة</span>
        <a href="{{ route('committee.index') }}" class="btn btn-sm btn-outline-primary">عرض الكل</a>
    </div>
    <div class="card-body p-0">
        @forelse($pendingProjects as $project)
            <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                <div>
                    <div style="font-weight:700;">#{{ $project->id }} — {{ Str::limit($project->title, 45) }}</div>
                    <small class="text-muted">{{ $project->owner->name ?? 'مستخدم محذوف' }} · {{ $project->city }} · ضرر {{ $project->damage_percentage }}%</small>
                </div>
                <a href="{{ route('committee.show', $project) }}" class="btn btn-sm btn-primary">مراجعة</a>
            </div>
        @empty
            <div class="p-4 text-center text-muted">لا توجد مشاريع بانتظار المراجعة حالياً 🎉</div>
        @endforelse
    </div>
</div>
@endsection