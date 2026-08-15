@extends('layouts.app')
@section('title', 'لوحة صاحب المشروع')

@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-buildings me-2"></i>مشاريعي</h1>
            <p>مرحباً {{ $user->name }} — تابع مشاريعك وفرق التطوع</p>
        </div>
      
    </div>
</div>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card green">
            <div class="stat-number" style="color:var(--primary);">{{ $stats['total_projects'] }}</div>
            <div class="stat-label">إجمالي المشاريع</div>
            <div class="stat-icon">🏗️</div>
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
        <div class="card stat-card" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
            <div class="stat-number" style="color:#15803d;">{{ $stats['completed_projects'] }}</div>
            <div class="stat-label">مكتملة</div>
            <div class="stat-icon">✅</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card amber">
            <div class="stat-number" style="color:#b45309;">{{ $stats['pending_projects'] }}</div>
            <div class="stat-label">بانتظار الموافقة</div>
            <div class="stat-icon">⏳</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Projects List --}}
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-buildings me-2 text-primary"></i>مشاريعي</span>
                <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus me-1"></i>مشروع جديد
                </a>
            </div>
            <div class="card-body p-0">
                @forelse($projects as $project)
                <div class="p-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <a href="{{ route('projects.show', $project) }}" style="font-weight:700;font-size:1rem;text-decoration:none;color:var(--text-dark);">{{ $project->title }}</a>
                            <div class="d-flex gap-2 mt-1">
                                <span class="badge status-{{ $project->status }}">{{ $project->status_arabic }}</span>
                                <span class="badge priority-{{ $project->priority }}">{{ $project->priority_arabic }}</span>
                                <span style="font-size:.8rem;color:var(--text-mid);"><i class="bi bi-geo-alt me-1"></i>{{ $project->city }}</span>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            @if(in_array($project->status,['approved','in_progress']))
                            <a href="{{ route('applications.index', $project) }}" class="btn btn-sm btn-outline-primary position-relative">
                                <i class="bi bi-people me-1"></i>الطلبات
                                @if($project->applications->where('status','pending')->count() > 0)
                                    <span class="badge ms-1" style="background:var(--danger);color:#fff;font-size:.7rem;">{{ $project->applications->where('status','pending')->count() }}</span>
                                @endif
                            </a>
                            @endif
                            <a href="{{ route('projects.edit', $project) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between mb-1">
                                <small style="font-weight:600;color:var(--text-mid);">التقدم الإجمالي</small>
                                <small style="font-weight:700;color:var(--primary);">{{ $project->progress_percentage }}%</small>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width:{{ $project->progress_percentage }}%"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-3 justify-content-end">
                                <div class="text-center">
                                    <div style="font-weight:700;font-size:1rem;color:var(--primary);">{{ $project->volunteers->count() }}/{{ $project->volunteers_needed }}</div>
                                    <small class="text-muted">متطوع</small>
                                </div>
                                <div class="text-center">
                                    <div style="font-weight:700;font-size:1rem;color:var(--text-dark);">{{ $project->tasks->count() }}</div>
                                    <small class="text-muted">مهمة</small>
                                </div>
                                <div class="text-center">
                                    <div style="font-weight:700;font-size:1rem;color:#22c55e;">{{ $project->tasks->where('status','completed')->count() }}</div>
                                    <small class="text-muted">مكتملة</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @empty
                <div class="text-center py-5">
                    <div style="font-size:3.5rem;margin-bottom:16px;">🏗️</div>
                    <h5 class="text-muted">لا توجد مشاريع بعد</h5>
                    <p class="text-muted" style="font-size:.9rem;">أضف مشروعك الأول للحصول على دعم المتطوعين</p>
                    <a href="{{ route('projects.create') }}" class="btn btn-primary mt-2">إضافة مشروعي الأول</a>
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Recent Applications --}}
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-clipboard-check me-2 text-primary"></i>طلبات تطوع جديدة</div>
            <div class="card-body p-0">
                @forelse($recentApplications as $app)
                <div class="p-3 border-bottom">
                    <div class="d-flex gap-2 align-items-center mb-2">
                        <img src="{{ $app->volunteer?->avatar_url ?? 'https://ui-avatars.com/api/?name=X&background=cbd5e1&color=fff' }}" style="width:32px;height:32px;border-radius:50%;">
                        <div>
                            <div style="font-weight:600;font-size:.88rem;">{{ $app->volunteer->name ?? 'متطوع محذوف' }}</div>
                            <small class="text-muted">{{ Str::limit($app->project->title,30) }}</small>
                        </div>
                        <span class="badge status-{{ $app->status }} ms-auto" style="font-size:.7rem;">{{ $app->status_arabic }}</span>
                    </div>
                    @if($app->status === 'pending')
                    <div class="d-flex gap-2">
                        <form action="{{ route('applications.accept', $app) }}" method="POST" class="flex-fill">
                            @csrf
                            <button class="btn btn-success btn-sm w-100" style="font-size:.8rem;">قبول</button>
                        </form>
                        <form action="{{ route('applications.reject', $app) }}" method="POST" class="flex-fill">
                            @csrf
                            <button class="btn btn-outline-danger btn-sm w-100" style="font-size:.8rem;">رفض</button>
                        </form>
                    </div>
                    @endif
                </div>
                @empty
                <div class="p-3 text-center text-muted" style="font-size:.9rem;">لا توجد طلبات جديدة</div>
                @endforelse
            </div>
        </div>

        {{-- Announcements --}}
        @if($announcements->isNotEmpty())
        <div class="card">
            <div class="card-header"><i class="bi bi-megaphone me-2 text-primary"></i>إعلانات</div>
            <div class="card-body p-0">
                @foreach($announcements as $ann)
                <div class="p-3 border-bottom">
                    <div style="font-weight:600;font-size:.88rem;">{{ $ann->title }}</div>
                    <p style="font-size:.82rem;color:var(--text-mid);margin:4px 0 0;">{{ Str::limit($ann->content, 80) }}</p>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
@endsection