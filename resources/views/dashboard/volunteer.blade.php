@extends('layouts.app')
@section('title', 'لوحة المتطوع')

@section('content')
    {{-- Header with Profile Summary --}}
    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <img src="{{ $user->avatar_url }}" class="avatar-lg" style="border:3px solid rgba(255,255,255,.5);">
            </div>
            <div class="col">
                <h1 style="font-size:1.6rem;">مرحباً، {{ $user->name }} 👋</h1>
                <div class="d-flex align-items-center gap-3 flex-wrap mt-1">
                    @if($profile)
                        @php $badge = $profile->badge; @endphp
                        <span style="background:rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:.85rem;">
                            {{ $badge['icon'] }} {{ $badge['label'] }}
                        </span>
                        <span style="font-size:.9rem;opacity:.9;"><i class="bi bi-star-fill me-1"
                                style="color:var(--accent);"></i>{{ $points }} نقطة</span>
                        <span style="font-size:.9rem;opacity:.9;"><i class="bi bi-clock me-1"></i>{{ $totalHours }} ساعة
                            تطوع</span>
                    @endif
                    @if($user->city)
                        <span style="font-size:.9rem;opacity:.9;"><i class="bi bi-geo-alt me-1"></i>{{ $user->city }}</span>
                    @endif
                </div>
            </div>
          
        </div>
    </div>

    {{-- Quick Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card green text-center">
                <div class="stat-number" style="color:var(--primary);">{{ $points }}</div>
                <div class="stat-label">نقطتي</div>
                <div class="stat-icon">⭐</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card blue text-center">
                <div class="stat-number" style="color:#1d4ed8;">{{ $totalHours }}</div>
                <div class="stat-label">ساعات التطوع</div>
                <div class="stat-icon">⏱️</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card amber text-center">
                <div class="stat-number" style="color:#b45309;">{{ $completedCount }}</div>
                <div class="stat-label">مشاريع مكتملة</div>
                <div class="stat-icon">✅</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card red text-center">
                <div class="stat-number" style="color:#b91c1c;">{{ $myTasks->count() }}</div>
                <div class="stat-label">مهام معلّقة</div>
                <div class="stat-icon">📋</div>
            </div>
        </div>
    </div>

    {{-- Profile Completion --}}
    @if(!$profile || empty($profile->skills))
        <div class="alert alert-warning d-flex gap-3 align-items-center mb-4">
            <i class="bi bi-person-exclamation fs-4"></i>
            <div>
                <strong>أكمل ملفك الشخصي!</strong> أضف مهاراتك وأوقات توفرك لتحصل على فرص تطوع مناسبة.
            </div>
            <a href="{{ route('volunteer.profile') }}" class="btn btn-warning btn-sm ms-auto fw-bold">أكمل الملف</a>
        </div>
    @endif

    <div class="row g-4">
        {{-- My Active Projects --}}
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between">
                    <span><i class="bi bi-buildings me-2 text-primary"></i>مشاريعي النشطة</span>
                    <a href="{{ route('projects.index') }}" class="btn btn-outline-primary btn-sm">تصفح المزيد</a>
                </div>
                <div class="card-body">
                    @forelse($myProjects->where('pivot.status', 'accepted') as $project)
                        <div class="p-3 border rounded-3 mb-3" style="transition:all .2s;"
                            onmouseenter="this.style.boxShadow='0 4px 12px rgba(0,0,0,.1)'"
                            onmouseleave="this.style.boxShadow='none'">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <a href="{{ route('projects.show', $project) }}"
                                        style="font-weight:700;text-decoration:none;color:var(--text-dark);">{{ $project->title }}</a>
                                    <div class="mt-1">
                                        <span
                                            class="badge status-{{ $project->status }} me-1">{{ $project->status_arabic }}</span>
                                        <span
                                            class="badge priority-{{ $project->priority }}">{{ $project->priority_arabic }}</span>
                                    </div>
                                </div>
                                <small class="text-muted"><i class="bi bi-geo-alt me-1"></i>{{ $project->city }}</small>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1 me-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">التقدم</small>
                                        <small
                                            style="font-weight:700;color:var(--primary);">{{ $project->progress_percentage }}%</small>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width:{{ $project->progress_percentage }}%"></div>
                                    </div>
                                </div>
                                <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-outline-primary">عرض</a>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-5">
                            <div style="font-size:3rem;margin-bottom:16px;">🏗️</div>
                            <h6 class="text-muted">لم تنضم لأي مشروع بعد</h6>
                            <a href="{{ route('projects.index') }}" class="btn btn-primary mt-3">تصفح المشاريع المتاحة</a>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- My Pending Tasks --}}
            @if($myTasks->isNotEmpty())
                <div class="card">
                    <div class="card-header"><i class="bi bi-list-check me-2 text-primary"></i>مهامي المعلّقة</div>
                    <div class="card-body p-0">
                        @foreach($myTasks as $task)
                            <div class="p-3 border-bottom d-flex align-items-center gap-3">
                                <form action="{{ route('tasks.status', $task) }}" method="POST" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="btn btn-sm"
                                        style="width:28px;height:28px;padding:0;border-radius:50%;border:2px solid var(--border);"
                                        title="إنهاء المهمة">
                                        <i class="bi bi-check" style="font-size:.85rem;"></i>
                                    </button>
                                </form>
                                <div class="flex-grow-1">
                                    <div style="font-weight:600;font-size:.9rem;">{{ $task->title }}</div>
                                    <small class="text-muted">{{ $task->project->title ?? '' }}</small>
                                </div>
                                <span class="badge priority-{{ $task->priority }}">{{ $task->status_arabic }}</span>
                                @if($task->due_date)
                                    <small class="text-muted">{{ $task->due_date->format('d/m') }}</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Right Sidebar --}}
        <div class="col-lg-4">
            {{-- Skills Card --}}
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-tools me-2 text-primary"></i>مهاراتي</div>
                <div class="card-body">
                    @if($profile && !empty($profile->skills))
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($profile->skills_arabic as $key => $label)
                                <span class="badge"
                                    style="background:var(--primary-pale);color:var(--primary);font-size:.8rem;padding:6px 12px;">{{ $label }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted mb-2" style="font-size:.9rem;">لم تضف مهاراتك بعد</p>
                        <a href="{{ route('volunteer.profile') }}" class="btn btn-outline-primary btn-sm w-100">إضافة
                            المهارات</a>
                    @endif
                </div>
            </div>

            {{-- Available Projects --}}
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-search me-2 text-primary"></i>مشاريع متاحة قريبة منك</div>
                <div class="card-body p-0">
                    @forelse($availableProjects->take(4) as $p)
                        <a href="{{ route('projects.show', $p) }}"
                            class="d-flex align-items-center gap-3 p-3 border-bottom text-decoration-none"
                            style="transition:.2s;" onmouseenter="this.style.background='var(--primary-pale)'"
                            onmouseleave="this.style.background=''">
                            <div
                                style="width:40px;height:40px;border-radius:10px;background:var(--primary-pale);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                                {{ match ($p->type) { 'shop' => '🏪', 'workshop' => '🔧', 'clinic' => '🏥', 'bakery' => '🥖', default => '🏗️'} }}
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div
                                    style="font-weight:600;font-size:.88rem;color:var(--text-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    {{ $p->title }}</div>
                                <small style="color:var(--text-light);">{{ $p->city }} ·
                                    {{ $p->volunteers_needed - $p->volunteers_assigned }} متطوع مطلوب</small>
                            </div>
                            <span class="badge priority-{{ $p->priority }}"
                                style="flex-shrink:0;">{{ $p->priority_arabic }}</span>
                        </a>
                    @empty
                        <div class="p-3 text-center text-muted" style="font-size:.9rem;">لا توجد مشاريع متاحة حالياً</div>
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
                                <p style="font-size:.82rem;color:var(--text-mid);margin:4px 0 0;">
                                    {{ Str::limit($ann->content, 80) }}</p>
                                <small class="text-muted">{{ $ann->created_at->diffForHumans() }}</small>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection