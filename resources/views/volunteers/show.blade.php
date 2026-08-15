{{-- volunteers/show.blade.php --}}
@extends('layouts.app')
@section('title', $volunteer->name)
@section('content')
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card text-center mb-4"><div class="card-body p-4">
            <img src="{{ $volunteer->avatar_url }}" class="avatar-xl mb-3" style="border:4px solid var(--primary-pale);">
            <h4 style="font-weight:700;">{{ $volunteer->name }}</h4>
            @if($volunteer->city)<div class="text-muted mb-2"><i class="bi bi-geo-alt me-1"></i>{{ $volunteer->city }}</div>@endif
            @if($volunteer->bio)<p style="font-size:.9rem;color:var(--text-mid);">{{ $volunteer->bio }}</p>@endif
            @if($volunteer->volunteerProfile)
                @php $badge = $volunteer->volunteerProfile->badge; @endphp
                <span style="font-size:1.2rem;">{{ $badge['icon'] }}</span>
                <span style="font-weight:700;color:{{ $badge['color'] }};">{{ $badge['label'] }}</span>
                <div class="row g-2 mt-3">
                    <div class="col-4 text-center"><div style="font-weight:900;font-size:1.4rem;color:var(--primary);">{{ $volunteer->volunteerProfile->points }}</div><small class="text-muted">نقطة</small></div>
                    <div class="col-4 text-center"><div style="font-weight:900;font-size:1.4rem;">{{ $volunteer->volunteerProfile->total_hours_contributed }}</div><small class="text-muted">ساعة</small></div>
                    <div class="col-4 text-center"><div style="font-weight:900;font-size:1.4rem;color:#f59e0b;">{{ number_format($avgRating, 1) }}</div><small class="text-muted">تقييم</small></div>
                </div>
                @if(!empty($volunteer->volunteerProfile->skills))
                <div class="mt-3"><h6 style="font-weight:700;">المهارات</h6>
                <div class="d-flex flex-wrap gap-1 justify-content-center">
                    @foreach($volunteer->volunteerProfile->skills_arabic as $label)
                        <span class="badge" style="background:var(--primary-pale);color:var(--primary);">{{ $label }}</span>
                    @endforeach
                </div></div>
                @endif
            @endif
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-4"><div class="card-header"><i class="bi bi-star me-2 text-primary"></i>التقييمات</div>
        <div class="card-body">
            @forelse($ratings as $rating)
            <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                <img src="{{ $rating->rater->avatar_url }}" style="width:36px;height:36px;border-radius:50%;">
                <div><div style="font-weight:600;font-size:.9rem;">{{ $rating->rater->name }}</div>
                <div class="stars">{{ str_repeat('★',$rating->rating) }}{{ str_repeat('☆',5-$rating->rating) }}</div>
                @if($rating->comment)<p style="font-size:.88rem;color:var(--text-mid);margin:4px 0 0;">{{ $rating->comment }}</p>@endif
                <small class="text-muted">{{ $rating->project->title ?? '' }} · {{ $rating->created_at->diffForHumans() }}</small></div>
            </div>
            @empty<div class="text-center text-muted py-3">لا توجد تقييمات بعد</div>@endforelse
        </div></div>
        <div class="card"><div class="card-header"><i class="bi bi-buildings me-2 text-primary"></i>المشاريع المكتملة</div>
        <div class="card-body p-0">
            @forelse($volunteer->assignedProjects as $p)
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <div><div style="font-weight:600;">{{ $p->title }}</div><small class="text-muted">{{ $p->city }}</small></div>
                <span class="badge status-completed">مكتمل</span>
            </div>
            @empty<div class="p-3 text-center text-muted">لا توجد مشاريع مكتملة</div>@endforelse
        </div></div>
    </div>
</div>
@endsection