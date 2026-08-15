@extends('layouts.app')
@section('title', 'مشاريعي')
@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div><h1><i class="bi bi-buildings me-2"></i>مشاريعي</h1><p>إدارة مشاريعك المسجّلة</p></div>
        <a href="{{ route('projects.create') }}" class="btn btn-sm" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.4);">+ إضافة مشروع</a>
    </div>
</div>
@forelse($projects as $project)
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 style="font-weight:700;margin-bottom:6px;">{{ $project->title }}</h5>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge status-{{ $project->status }}">{{ $project->status_arabic }}</span>
                    <span class="badge priority-{{ $project->priority }}">{{ $project->priority_arabic }}</span>
                    <span class="text-muted" style="font-size:.85rem;"><i class="bi bi-geo-alt me-1"></i>{{ $project->city }}</span>
                    @if(!$project->has_approved_consent)
                        <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:.72rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>بلا موافقة خطية
                        </span>
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-primary btn-sm" title="تفاصيل المشروع"><i class="bi bi-eye"></i></a>
                <a href="{{ route('projects.timeline', $project) }}" class="btn btn-outline-success btn-sm" title="المتابعة الشاملة"><i class="bi bi-diagram-3"></i></a>
                <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary btn-sm" title="تعديل المشروع"><i class="bi bi-pencil"></i></a>
                @if($project->status === 'pending')
                <form action="{{ route('projects.destroy', $project) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا المشروع؟')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm" title="حذف المشروع"><i class="bi bi-trash"></i></button>
                </form>
                @endif
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted">التقدم</small>
                    <small style="font-weight:700;color:var(--primary);">{{ $project->progress_percentage }}%</small>
                </div>
                <div class="progress"><div class="progress-bar" style="width:{{ $project->progress_percentage }}%"></div></div>
            </div>
            <div class="col-md-6 d-flex gap-4 justify-content-md-end">
                <div class="text-center">
                    <div style="font-weight:700;font-size:1.1rem;color:var(--primary);">{{ $project->tasks->where('status','completed')->count() }}/{{ $project->tasks->count() }}</div>
                    <small class="text-muted">مهام</small>
                </div>
                <div class="text-center">
                    <div style="font-weight:700;font-size:1.1rem;">{{ $project->damage_percentage }}%</div>
                    <small class="text-muted">نسبة الضرر</small>
                </div>
            </div>
        </div>

        {{-- Quick access row to new features --}}
        <div class="d-flex gap-2 mt-3 pt-3" style="border-top:1px solid var(--border);">
            <a href="{{ route('projects.timeline', $project) }}" class="btn btn-sm btn-outline-success flex-fill">
                <i class="bi bi-diagram-3 me-1"></i>المتابعة الشاملة
            </a>
            <a href="{{ route('finances.index', $project) }}" class="btn btn-sm btn-outline-primary flex-fill">
                <i class="bi bi-cash-stack me-1"></i>المالية
            </a>
            <a href="{{ route('media.index', $project) }}" class="btn btn-sm btn-outline-secondary flex-fill">
                <i class="bi bi-images me-1"></i>التوثيق
            </a>
            <a href="{{ route('approvals.index', $project) }}" class="btn btn-sm {{ $project->has_approved_consent ? 'btn-outline-success' : 'btn-outline-warning' }} flex-fill">
                <i class="bi bi-file-earmark-check me-1"></i>الموافقة
            </a>
        </div>
    </div>
</div>
@empty
<div class="card text-center py-5">
    <div style="font-size:4rem;margin-bottom:16px;">🏗️</div>
    <h5 class="text-muted">لا توجد مشاريع مسجّلة بعد</h5>
    <a href="{{ route('projects.create') }}" class="btn btn-primary mt-3">أضف مشروعك الأول</a>
</div>
@endforelse
{{ $projects->links() }}
@endsection