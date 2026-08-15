@extends('layouts.app')
@section('title', 'تفاصيل المستخدم')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 style="font-weight:900;font-family:'Cairo',sans-serif;">ملف المستخدم</h2>
    <a href="{{ route('admin.users') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-right me-1"></i>العودة للقائمة
    </a>
</div>

<div class="row g-4">

    {{-- بطاقة المعلومات الأساسية --}}
    <div class="col-lg-4">
        <div class="card mb-4 text-center">
            <div class="card-body p-4">
                <img src="{{ $user->avatar_url }}"
                     style="width:100px;height:100px;border-radius:50%;
                            border:4px solid var(--primary-pale);margin-bottom:16px;">
                <h5 style="font-weight:700;margin-bottom:4px;">{{ $user->name }}</h5>
                <div class="text-muted mb-2" style="font-size:.9rem;">
                    {{ $user->email }}
                </div>
                <span class="badge"
                      style="background:var(--primary-pale);color:var(--primary);
                             font-size:.85rem;padding:6px 14px;">
                    {{ $user->role_arabic }}
                </span>

                @if($user->city)
                <div class="mt-2 text-muted" style="font-size:.9rem;">
                    <i class="bi bi-geo-alt me-1"></i>{{ $user->city }}
                </div>
                @endif

                @if($user->phone)
                <div class="text-muted" style="font-size:.9rem;">
                    <i class="bi bi-telephone me-1"></i>{{ $user->phone }}
                </div>
                @endif

                <div class="mt-2" style="font-size:.85rem;color:var(--text-light);">
                    <i class="bi bi-calendar me-1"></i>
                    تسجيل: {{ $user->created_at->format('d/m/Y') }}
                </div>

                {{-- حالة الحساب --}}
                <div class="mt-3">
                    @if($user->is_active)
                        <span class="badge"
                              style="background:#dcfce7;color:#15803d;font-size:.82rem;">
                            ✅ حساب نشط
                        </span>
                    @else
                        <span class="badge"
                              style="background:#fecaca;color:#b91c1c;font-size:.82rem;">
                            ❌ حساب معطّل
                        </span>
                    @endif
                </div>

                {{-- أزرار الإجراءات --}}
                <div class="mt-3 d-flex gap-2 justify-content-center">
                    <form action="{{ route('admin.users.toggle', $user) }}" method="POST">
                        @csrf
                        <button class="btn btn-sm {{ $user->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} fw-bold">
                            {{ $user->is_active ? '🔒 تعطيل الحساب' : '✅ تفعيل الحساب' }}
                        </button>
                    </form>

                    @if(!$user->isAdmin())
                    <form action="{{ route('admin.users.delete', $user) }}"
                          method="POST"
                          onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم نهائياً؟')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger fw-bold">
                            🗑️ حذف
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- إحصائيات المتطوع --}}
        @if($user->isVolunteer() && $user->volunteerProfile)
        <div class="card">
            <div class="card-header">
                <i class="bi bi-person-gear me-2 text-primary"></i>إحصائيات التطوع
            </div>
            <div class="card-body">
                <div class="row g-2 text-center mb-3">
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;color:var(--primary);">
                            {{ $user->volunteerProfile->points }}
                        </div>
                        <small class="text-muted">نقطة</small>
                    </div>
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;">
                            {{ $user->volunteerProfile->total_hours_contributed }}
                        </div>
                        <small class="text-muted">ساعة</small>
                    </div>
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;color:#f59e0b;">
                            {{ number_format($user->volunteerProfile->rating, 1) }}
                        </div>
                        <small class="text-muted">تقييم</small>
                    </div>
                </div>

                @if(!empty($user->volunteerProfile->skills))
                    @php $allSkills = \App\Models\VolunteerProfile::allSkills(); @endphp
                    <div class="d-flex flex-wrap gap-1">
                        @foreach($user->volunteerProfile->skills as $skill)
                            <span class="badge"
                                  style="background:var(--primary-pale);
                                         color:var(--primary);font-size:.75rem;">
                                {{ $allSkills[$skill] ?? $skill }}
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3" style="font-size:.85rem;">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">مستوى الخبرة</span>
                        <span style="font-weight:600;">
                            {{ match($user->volunteerProfile->experience_level) {
                                'beginner'     => 'مبتدئ',
                                'intermediate' => 'متوسط',
                                'expert'       => 'خبير',
                                default        => $user->volunteerProfile->experience_level
                            } }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">ساعات أسبوعياً</span>
                        <span style="font-weight:600;">
                            {{ $user->volunteerProfile->hours_per_week }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">يمتلك سيارة</span>
                        <span style="font-weight:600;">
                            {{ $user->volunteerProfile->has_vehicle ? '✅ نعم' : '❌ لا' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- المحتوى الرئيسي --}}
    <div class="col-lg-8">

        {{-- مشاريع صاحب المشروع --}}
        @if($user->isProjectOwner())
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-buildings me-2 text-primary"></i>
                مشاريع المستخدم ({{ $user->ownedProjects->count() }})
            </div>
            <div class="card-body p-0">
                @forelse($user->ownedProjects->take(10) as $project)
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <a href="{{ route('projects.show', $project) }}"
                           style="font-weight:600;text-decoration:none;color:var(--text-dark);">
                            {{ $project->title }}
                        </a>
                        <div style="font-size:.82rem;color:var(--text-mid);">
                            <i class="bi bi-geo-alt me-1"></i>{{ $project->city }}
                            · {{ $project->created_at->format('d/m/Y') }}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge status-{{ $project->status }}">
                            {{ $project->status_arabic }}
                        </span>
                        <span class="badge priority-{{ $project->priority }}">
                            {{ $project->priority_arabic }}
                        </span>
                    </div>
                </div>
                @empty
                <div class="p-3 text-center text-muted">لا توجد مشاريع</div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- مشاريع المتطوع --}}
        @if($user->isVolunteer())
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-buildings me-2 text-primary"></i>
                المشاريع المشارك فيها ({{ $user->assignedProjects->count() }})
            </div>
            <div class="card-body p-0">
                @forelse($user->assignedProjects->take(10) as $project)
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <a href="{{ route('projects.show', $project) }}"
                           style="font-weight:600;text-decoration:none;color:var(--text-dark);">
                            {{ $project->title }}
                        </a>
                        <div style="font-size:.82rem;color:var(--text-mid);">
                            <i class="bi bi-geo-alt me-1"></i>{{ $project->city }}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge status-{{ $project->status }}">
                            {{ $project->status_arabic }}
                        </span>
                        <span style="font-size:.8rem;color:var(--text-light);">
                            {{ $project->pivot->hours_contributed ?? 0 }} ساعة
                        </span>
                    </div>
                </div>
                @empty
                <div class="p-3 text-center text-muted">لا توجد مشاريع</div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- التقييمات المستلمة --}}
        <div class="card">
            <div class="card-header">
                <i class="bi bi-star me-2 text-primary"></i>
                التقييمات المستلمة ({{ $user->ratingsReceived->count() }})
            </div>
            <div class="card-body p-0">
                @forelse($user->ratingsReceived->take(5) as $rating)
                <div class="p-3 border-bottom">
                    <div class="d-flex gap-3 align-items-start">
                        <img src="{{ $rating->rater->avatar_url }}"
                             style="width:34px;height:34px;border-radius:50%;flex-shrink:0;">
                        <div class="flex-grow-1">
                            <div style="font-size:.88rem;font-weight:600;">
                                {{ $rating->rater->name }}
                            </div>
                            <div class="stars" style="font-size:.85rem;color:#f59e0b;">
                                {{ str_repeat('★', $rating->rating) }}{{ str_repeat('☆', 5 - $rating->rating) }}
                            </div>
                            @if($rating->comment)
                                <p class="mb-0 mt-1"
                                   style="font-size:.82rem;color:var(--text-mid);">
                                    {{ $rating->comment }}
                                </p>
                            @endif
                            <small class="text-muted">
                                {{ $rating->created_at->diffForHumans() }}
                            </small>
                        </div>
                    </div>
                </div>
                @empty
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-star" style="font-size:2rem;opacity:.3;"></i>
                    <div class="mt-2">لا توجد تقييمات بعد</div>
                </div>
                @endforelse
            </div>
        </div>

    </div>
</div>

@endsection