@extends('layouts.app')
@section('title', 'لجنة التحقق — المشاريع بانتظار المراجعة')

@section('content')
<div class="page-header mb-4">
    <div>
        <h1><i class="bi bi-clipboard-check me-2"></i>لجنة التحقق من بيانات المشاريع</h1>
        <p>راجع صحة نسبة الضرر ووصف الحالة المُدخلة من صاحب المشروع قبل اعتماد المشروع للنشر</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <strong>{{ $projects->total() }}</strong> مشروع بانتظار المراجعة
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>المشروع</th>
                    <th>صاحب المشروع</th>
                    <th>المدينة</th>
                    <th>نسبة الضرر</th>
                    <th>تاريخ التقديم</th>
                    <th>الإجراء</th>
                </tr>
            </thead>
            <tbody>
                @forelse($projects as $project)
                <tr>
                    <td><span class="text-muted">#{{ $project->id }}</span></td>
                    <td style="font-weight:600;">{{ Str::limit($project->title, 40) }}</td>
                    <td>{{ $project->owner->name ?? 'مستخدم محذوف' }}</td>
                    <td>{{ $project->city }}</td>
                    <td>
                        <span style="font-weight:700;color:{{ $project->priority_color }};">
                            {{ $project->damage_percentage }}%
                        </span>
                    </td>
                    <td><small class="text-muted">{{ $project->created_at->format('d/m/Y') }}</small></td>
                    <td>
                        <a href="{{ route('committee.show', $project) }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-search me-1"></i>مراجعة
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">لا توجد مشاريع بانتظار المراجعة حالياً 🎉</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($projects->hasPages())
        <div class="card-footer">{{ $projects->links() }}</div>
    @endif
</div>
@endsection