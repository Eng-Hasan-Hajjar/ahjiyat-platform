@extends('layouts.app')
@section('title', 'المتطوعون')
@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-people me-2"></i>المتطوعون</h1>
    <p>تعرف على أعضاء مجتمع التطوع لإعادة الإعمار</p>
</div>
<div class="card mb-4">
    <div class="card-body">
        <form method="GET">
            <div class="row g-3 align-items-end">
                <div class="col-md-4"><label class="form-label">بحث بالاسم</label>
                    <input type="text" name="search" class="form-control" placeholder="ابحث..." value="{{ request('search') }}"></div>
                <div class="col-md-3"><label class="form-label">المهارة</label>
                    <select name="skill" class="form-select">
                        <option value="">كل المهارات</option>
                        @foreach($skills as $k => $v)<option value="{{ $k }}" {{ request('skill') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label">المدينة</label>
                    <select name="city" class="form-select">
                        <option value="">كل المدن</option>
                        @foreach($cities as $c)<option value="{{ $c }}" {{ request('city') === $c ? 'selected' : '' }}>{{ $c }}</option>@endforeach
                    </select></div>
                <div class="col-auto d-flex gap-2">
                    <button class="btn btn-primary"><i class="bi bi-search me-1"></i>بحث</button>
                    <a href="{{ route('volunteers.index') }}" class="btn btn-outline-secondary">إعادة ضبط</a>
                </div>
            </div>
        </form>
    </div>
</div>
<div class="row g-4">
    @forelse($volunteers as $vol)
    <div class="col-md-6 col-lg-4">
        <div class="card h-100" style="transition:all .25s;" onmouseenter="this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)'" onmouseleave="this.style.transform='';this.style.boxShadow=''">
            <div class="card-body p-4">
                <div class="d-flex gap-3 mb-3">
                    <img src="{{ $vol->avatar_url }}" class="avatar-lg" style="border:3px solid var(--primary-pale);">
                    <div>
                        <h6 style="font-weight:700;margin-bottom:4px;">{{ $vol->name }}</h6>
                        @if($vol->city)<small class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i>{{ $vol->city }}</small>@endif
                        @if($vol->volunteerProfile)
                            @php $badge = $vol->volunteerProfile->badge; @endphp
                            <span style="font-size:.78rem;font-weight:600;color:{{ $badge['color'] }};">{{ $badge['icon'] }} {{ $badge['label'] }}</span>
                        @endif
                    </div>
                </div>
                @if($vol->volunteerProfile)
                    <div class="d-flex justify-content-around text-center mb-3 py-2 border-top border-bottom">
                        <div><div style="font-weight:800;color:var(--primary);">{{ $vol->volunteerProfile->points }}</div><small class="text-muted" style="font-size:.75rem;">نقطة</small></div>
                        <div><div style="font-weight:800;">{{ $vol->volunteerProfile->total_hours_contributed }}</div><small class="text-muted" style="font-size:.75rem;">ساعة</small></div>
                        <div>
                            <div style="font-weight:800;color:#f59e0b;">{{ number_format($vol->volunteerProfile->rating, 1) }}</div>
                            <small class="text-muted" style="font-size:.75rem;">تقييم</small>
                        </div>
                    </div>
                    @if(!empty($vol->volunteerProfile->skills))
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach(array_slice($vol->volunteerProfile->skills_arabic, 0, 3) as $label)
                                <span class="badge" style="background:var(--primary-pale);color:var(--primary);font-size:.75rem;">{{ $label }}</span>
                            @endforeach
                            @if(count($vol->volunteerProfile->skills) > 3)
                                <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:.75rem;">+{{ count($vol->volunteerProfile->skills) - 3 }}</span>
                            @endif
                        </div>
                    @endif
                @endif
                <a href="{{ route('volunteers.show', $vol) }}" class="btn btn-outline-primary btn-sm w-100">عرض الملف الشخصي</a>
            </div>
        </div>
    </div>
    @empty
    <div class="col-12"><div class="card text-center py-5">
        <div style="font-size:3rem;margin-bottom:12px;">🙋</div>
        <h5 class="text-muted">لا يوجد متطوعون يطابقون البحث</h5>
    </div></div>
    @endforelse
</div>
<div class="mt-4">{{ $volunteers->links() }}</div>
@endsection