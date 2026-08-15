@extends('layouts.app')
@section('title', 'طلبات التطوع')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 style="font-weight:900;font-family:'Cairo',sans-serif;margin-bottom:4px;">طلبات التطوع</h2>
            <p class="text-muted mb-0"><a href="{{ route('projects.show', $project) }}"
                    style="color:var(--primary);">{{ $project->title }}</a></p>
        </div>
        <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary btn-sm"><i
                class="bi bi-arrow-right me-1"></i>العودة</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @forelse($applications as $app)
                <div class="p-4 border-bottom">
                    @if($app->volunteer)
                        <div class="d-flex gap-3 align-items-start">
                            <img src="{{ $app->volunteer->avatar_url }}" class="avatar" style="width:50px;height:50px;">
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 style="font-weight:700;margin-bottom:2px;">{{ $app->volunteer->name }}</h6>
                                        <div class="d-flex gap-2 flex-wrap" style="font-size:.82rem;color:var(--text-mid);">
                                            @if($app->volunteer->city)<span><i
                                            class="bi bi-geo-alt me-1"></i>{{ $app->volunteer->city }}</span>@endif
                                            @if($app->volunteer->volunteerProfile)
                                                <span><i
                                                        class="bi bi-star me-1"></i>{{ number_format($app->volunteer->volunteerProfile->rating, 1) }}
                                                    تقييم</span>
                                                <span><i
                                                        class="bi bi-clock me-1"></i>{{ $app->volunteer->volunteerProfile->total_hours_contributed }}
                                                    ساعة تطوع</span>
                                            @endif
                                            <span><i class="bi bi-calendar me-1"></i>{{ $app->available_hours_per_week }}
                                                ساعة/أسبوع</span>
                                        </div>
                                    </div>
                                    <span class="badge status-{{ $app->status }} fs-6">{{ $app->status_arabic }}</span>
                                </div>

                                @if($app->volunteer->volunteerProfile && !empty($app->volunteer->volunteerProfile->skills))
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        @php $allSkills = \App\Models\VolunteerProfile::allSkills(); @endphp
                                        @foreach($app->volunteer->volunteerProfile->skills as $skill)
                                            <span class="badge"
                                                style="background:var(--primary-pale);color:var(--primary);font-size:.75rem;">{{ $allSkills[$skill] ?? $skill }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                @if($app->message)
                                    <div class="p-2 rounded-2 mb-3"
                                        style="background:var(--bg-main);font-size:.88rem;color:var(--text-mid);">
                                        <i class="bi bi-chat-left-quote me-1"></i>{{ $app->message }}
                                    </div>
                                @endif

                                @if($app->status === 'pending')
                                    <div class="d-flex gap-2 flex-wrap">
                                        <form action="{{ route('applications.accept', $app) }}" method="POST">
                                            @csrf
                                            <button class="btn btn-success btn-sm fw-bold"><i class="bi bi-check-circle me-1"></i>قبول
                                                المتطوع</button>
                                        </form>
                                        <div x-data="{ open: false }">
                                            <button class="btn btn-outline-danger btn-sm"
                                                onclick="document.getElementById('reject-{{ $app->id }}').classList.toggle('d-none')">
                                                <i class="bi bi-x-circle me-1"></i>رفض
                                            </button>
                                            <div id="reject-{{ $app->id }}" class="d-none mt-2">
                                                <form action="{{ route('applications.reject', $app) }}" method="POST"
                                                    class="d-flex gap-2">
                                                    @csrf
                                                    <input type="text" name="rejection_reason" class="form-control form-control-sm"
                                                        placeholder="سبب الرفض (اختياري)">
                                                    <button class="btn btn-danger btn-sm">تأكيد</button>
                                                </form>
                                            </div>
                                        </div>
                                        <a href="{{ route('volunteers.show', $app->volunteer) }}"
                                            class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-person me-1"></i>عرض الملف
                                        </a>
                                    </div>
                                @elseif($app->status === 'rejected' && $app->rejection_reason)
                                    <div class="alert alert-danger py-2 mb-0" style="font-size:.85rem;">
                                        <i class="bi bi-x-circle me-1"></i>سبب الرفض: {{ $app->rejection_reason }}
                                    </div>
                                @endif
                                <small class="text-muted d-block mt-2">{{ $app->created_at->diffForHumans() }}</small>
                            </div>
                        </div>
                    @else
                        <div class="d-flex gap-3 align-items-center">
                            <div class="d-flex align-items-center justify-content-center"
                                style="width:50px;height:50px;background:#f1f5f9;color:#94a3b8;border-radius:50%;font-size:1.4rem;flex-shrink:0;">
                                <i class="bi bi-person-x"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 style="font-weight:700;margin-bottom:2px;color:var(--text-light);">متطوع محذوف</h6>
                                <small class="text-muted d-block">هذا الحساب لم يعد موجوداً على المنصة</small>
                                <span class="badge status-{{ $app->status }} fs-6 mt-1">{{ $app->status_arabic }}</span>
                                <small class="text-muted d-block mt-2">{{ $app->created_at->diffForHumans() }}</small>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="text-center py-5">
                    <div style="font-size:3rem;margin-bottom:12px;">📋</div>
                    <h5 class="text-muted">لا توجد طلبات تطوع بعد</h5>
                    <p class="text-muted" style="font-size:.9rem;">ستظهر هنا طلبات المتطوعين الراغبين في المشاركة</p>
                </div>
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $applications->links() }}</div>
@endsection