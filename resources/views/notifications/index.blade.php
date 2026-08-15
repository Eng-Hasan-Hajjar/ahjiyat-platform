@extends('layouts.app')
@section('title', 'الإشعارات')
@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-bell me-2"></i>الإشعارات</h1>
            <p>جميع التنبيهات والتحديثات الخاصة بك</p>
        </div>
        @if(auth()->user()->unreadNotifications->count() > 0)
        <form action="{{ route('notifications.markAllRead') }}" method="POST">
            @csrf
            <button class="btn btn-outline-primary btn-sm">
                <i class="bi bi-check2-all me-1"></i>تعليم الكل كمقروء
            </button>
        </form>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        @forelse($notifications as $notif)
        @php $data = $notif->data; $isRead = !is_null($notif->read_at); @endphp
        <div class="d-flex align-items-start gap-3 p-4 border-bottom {{ !$isRead ? 'bg-primary-subtle' : '' }}" style="{{ !$isRead ? 'background:var(--primary-pale) !important;' : '' }}">
            <div style="width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;
                background:{{ !$isRead ? 'var(--primary)' : 'var(--border)' }};color:{{ !$isRead ? '#fff' : 'var(--text-mid)' }};">
                {{ match($data['icon'] ?? '') { 'project' => '🏗️', 'application' => '📋', default => '🔔' } }}
            </div>
            <div class="flex-grow-1">
                <div style="font-weight:{{ !$isRead ? '700' : '600' }};font-size:.95rem;margin-bottom:4px;">{{ $data['title'] ?? 'إشعار' }}</div>
                <p style="font-size:.9rem;color:var(--text-mid);margin:0 0 6px;">{{ $data['message'] ?? '' }}</p>
                <div class="d-flex align-items-center gap-3">
                    <small class="text-muted"><i class="bi bi-clock me-1"></i>{{ $notif->created_at->diffForHumans() }}</small>
                    @if(!$isRead)<span style="background:var(--primary);color:#fff;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700;">جديد</span>@endif
                    @if(isset($data['project_id']))
                        <a href="{{ route('projects.show', $data['project_id']) }}" class="btn btn-sm btn-outline-primary" style="font-size:.78rem;padding:3px 10px;">عرض المشروع</a>
                    @endif
                </div>
            </div>
            <form action="{{ route('notifications.destroy', $notif->id) }}" method="POST">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-link text-muted p-0" title="حذف"><i class="bi bi-x-lg"></i></button>
            </form>
        </div>
        @empty
        <div class="text-center py-5">
            <div style="font-size:3.5rem;margin-bottom:16px;">🔔</div>
            <h5 class="text-muted">لا توجد إشعارات</h5>
            <p class="text-muted" style="font-size:.9rem;">ستظهر هنا الإشعارات المتعلقة بمشاريعك وطلبات التطوع</p>
        </div>
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection

