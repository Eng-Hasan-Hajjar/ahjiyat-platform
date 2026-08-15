@extends('layouts.app')
@section('title', 'الإعلانات')
@section('content')

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-megaphone me-2"></i>الإعلانات</h1>
            <p>إدارة الإعلانات المنشورة على المنصة</p>
        </div>
       
    </div>
</div>



   <a href="{{ route('admin.announcements.create') }}"
           class="btn btn-sm"
           style="background:rgba(255,255,255,.25);color:#d60404;
                  border:1px solid rgba(255,255,255,.4);font-weight:600;">
            <i class="bi bi-plus-circle me-1"></i>إعلان جديد
        </a>

        
<div class="card">
    <div class="card-body p-0">
        @forelse($announcements as $ann)
        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h6 style="font-weight:700;margin:0;">{{ $ann->title }}</h6>
                        @if($ann->is_published)
                            <span class="badge"
                                  style="background:#dcfce7;color:#15803d;font-size:.72rem;">
                                منشور
                            </span>
                        @else
                            <span class="badge"
                                  style="background:#fef3c7;color:#b45309;font-size:.72rem;">
                                مسودة
                            </span>
                        @endif
                    </div>
                    <p style="font-size:.9rem;color:var(--text-mid);margin:6px 0;">
                        {{ Str::limit($ann->content, 150) }}
                    </p>
                    <div class="d-flex gap-3"
                         style="font-size:.82rem;color:var(--text-light);">
                        <span><i class="bi bi-people me-1"></i>{{ $ann->target_arabic }}</span>
                        <span><i class="bi bi-person me-1"></i>{{ $ann->author->name }}</span>
                        <span><i class="bi bi-clock me-1"></i>{{ $ann->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                <form action="{{ route('admin.announcements.destroy', $ann) }}"
                      method="POST"
                      onsubmit="return confirm('هل أنت متأكد من حذف هذا الإعلان؟')">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger ms-3">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
        @empty
        <div class="text-center py-5">
            <div style="font-size:3rem;margin-bottom:12px;">📢</div>
            <h5 class="text-muted">لا توجد إعلانات</h5>
            <a href="{{ route('admin.announcements.create') }}"
               class="btn btn-primary mt-3">
                أضف إعلاناً جديداً
            </a>
        </div>
        @endforelse
    </div>
</div>

<div class="mt-3">{{ $announcements->links() }}</div>

@endsection