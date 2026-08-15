@extends('layouts.app')
@section('title', 'طلباتي للتطوع')
@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-clipboard-check me-2"></i>طلباتي للتطوع</h1>
    <p>تتبع حالة طلبات التطوع التي أرسلتها</p>
</div>
@forelse($applications as $app)
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h6 style="font-weight:700;margin-bottom:4px;">{{ $app->project->title }}</h6>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <span class="text-muted" style="font-size:.85rem;"><i class="bi bi-geo-alt me-1"></i>{{ $app->project->city }}</span>
                    <span class="text-muted" style="font-size:.85rem;">بواسطة: {{ $app->project->owner->name ?? 'مستخدم محذوف' }}</span>
                </div>
            </div>
            <div class="text-end">
                <span class="badge status-{{ $app->status }}" style="font-size:.85rem;">{{ $app->status_arabic }}</span>
                <div><small class="text-muted">{{ $app->created_at->diffForHumans() }}</small></div>
            </div>
        </div>
        @if($app->message)
        <div class="mt-2 p-2 rounded-2" style="background:var(--bg-main);font-size:.88rem;color:var(--text-mid);">
            <i class="bi bi-chat-left-text me-1"></i>{{ $app->message }}
        </div>
        @endif
        @if($app->status === 'rejected' && $app->rejection_reason)
        <div class="alert alert-danger mt-2 py-2 mb-0" style="font-size:.88rem;">
            <i class="bi bi-x-circle me-1"></i>سبب الرفض: {{ $app->rejection_reason }}
        </div>
        @endif
        <div class="mt-2">
            <a href="{{ route('projects.show', $app->project) }}" class="btn btn-outline-primary btn-sm">عرض المشروع</a>
        </div>
    </div>
</div>
@empty
<div class="card text-center py-5">
    <div style="font-size:3.5rem;margin-bottom:16px;">📋</div>
    <h5 class="text-muted">لم تتقدم لأي مشروع بعد</h5>
    <a href="{{ route('projects.index') }}" class="btn btn-primary mt-3">تصفح المشاريع المتاحة</a>
</div>
@endforelse
{{ $applications->links() }}
@endsection