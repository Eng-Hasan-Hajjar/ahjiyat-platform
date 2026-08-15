@extends('layouts.app')
@section('title', 'لوحة المتصدرين')
@section('content')

@php
    $myProfile = (auth()->check() && auth()->user()->isVolunteer()) ? auth()->user()->volunteerProfile : null;
    $myBadge   = $myProfile ? $myProfile->badge : null;
@endphp

<div class="page-header mb-4">
    <h1><i class="bi bi-trophy me-2"></i>لوحة المتصدرين</h1>
    <p>أكثر المتطوعين إسهاماً في إعادة الإعمار — من أصل {{ $totalRanked }} متطوع حصل على نقاط</p>
</div>

{{-- ═══ شرح آلية النقاط والشارات ═══ --}}
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-info-circle-fill" style="color:var(--primary);font-size:1.2rem;"></i>
            <h6 class="mb-0" style="font-weight:800;">كيف تُحسب نقاطك وشارتك؟</h6>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div style="background:var(--primary-pale);border-radius:12px;padding:14px 16px;height:100%;">
                    <div style="font-weight:700;font-size:.92rem;margin-bottom:4px;">🕐 كيف تكسب النقاط</div>
                    <div style="font-size:.85rem;color:var(--text-mid);line-height:1.7;">
                        كل ساعة تطوع تُوثّق بمهمة مكتملة تمنحك <strong>5 نقاط</strong>.
                        مثال: مهمة مدتها 4 ساعات = 20 نقطة تُضاف لرصيدك فور اعتماد إنجازها.
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background:#f1f5f9;border-radius:12px;padding:14px 16px;height:100%;">
                    <div style="font-weight:700;font-size:.92rem;margin-bottom:4px;">الفرق بين الميدالية 🥇 والشارة ⬤</div>
                    <div style="font-size:.85rem;color:var(--text-mid);line-height:1.7;">
                        <strong>🥇🥈🥉 الميدالية</strong> بجانب الاسم = ترتيبك الحالي بين الكل، بتتغيّر كل ما تغيّر ترتيبك.<br>
                        <strong>⬤ الدائرة الملوّنة</strong> = مستواك الثابت حسب مجموع نقاطك، وبتضل معك مهما تغيّر ترتيب غيرك.
                    </div>
                </div>
            </div>
        </div>

        <div style="font-weight:700;font-size:.85rem;color:var(--text-mid);margin-bottom:8px;">سلّم الشارات</div>
        <div class="d-flex flex-wrap gap-2">
            @foreach(array_reverse($tiers) as $tier)
                @php $isMine = $myBadge && $myBadge['label'] === $tier['label']; @endphp
                <div class="d-flex align-items-center gap-2" style="background:{{ $tier['bg'] }};border:1.5px solid {{ $tier['color'] }}{{ $isMine ? '' : '55' }};border-radius:30px;padding:6px 14px;{{ $isMine ? 'box-shadow:0 0 0 2px '.$tier['color'].';' : '' }}">
                    <span style="font-size:1rem;">{{ $tier['icon'] }}</span>
                    <span style="font-weight:700;font-size:.82rem;color:{{ $tier['color'] }};">{{ $tier['label'] }}</span>
                    <span style="font-size:.72rem;color:var(--text-light);">{{ $tier['min'] }}+ نقطة</span>
                    @if($isMine)<span style="font-size:.7rem;font-weight:800;color:{{ $tier['color'] }};">(أنت هون)</span>@endif
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══ حالتي الشخصية ═══ --}}
@if($myProfile && $myBadge)
    <div class="card mb-4" style="border:2px solid {{ $myBadge['color'] }}33;">
        <div class="card-body d-flex flex-wrap align-items-center gap-4">
            <div class="d-flex align-items-center gap-2" style="background:{{ $myBadge['bg'] }};border-radius:30px;padding:8px 16px;">
                <span style="font-size:1.3rem;">{{ $myBadge['icon'] }}</span>
                <span style="font-weight:800;color:{{ $myBadge['color'] }};">{{ $myBadge['label'] }}</span>
            </div>
            <div>
                <div style="font-weight:900;font-size:1.3rem;color:var(--primary);">{{ $myProfile->points }} نقطة</div>
                <small class="text-muted">رصيدك الحالي</small>
            </div>
            @if($myRank)
            <div>
                <div style="font-weight:900;font-size:1.3rem;">#{{ $myRank }}</div>
                <small class="text-muted">ترتيبك بين {{ $totalRanked }} متطوع</small>
            </div>
            @endif
            @if($myBadge['next_label'])
            <div class="flex-grow-1" style="min-width:200px;">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted">باقي {{ $myBadge['points_to_next'] }} نقطة لشارة {{ $myBadge['next_label'] }}</small>
                    <small class="text-muted">{{ $myBadge['progress'] }}%</small>
                </div>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar" style="width:{{ $myBadge['progress'] }}%;background:{{ $myBadge['color'] }};"></div>
                </div>
            </div>
            @else
            <div class="flex-grow-1 text-center" style="min-width:200px;">
                <span style="font-weight:700;color:{{ $myBadge['color'] }};">🎉 وصلت لأعلى شارة!</span>
            </div>
            @endif
        </div>
    </div>
@endif

{{-- ═══ القائمة ═══ --}}
<div class="card">
    <div class="card-body p-0">
        @forelse($volunteers as $i => $vol)
        @php $pos = $i + 1; $profile = $vol->volunteerProfile; $badge = $profile?->badge; @endphp
        <div class="d-flex align-items-center gap-3 p-3 border-bottom"
             style="{{ $pos === 1 ? 'background:linear-gradient(90deg,#fff8e1,#fff) !important;' : '' }}">

            {{-- ميدالية المركز: ترتيب نسبي بهاي القائمة بس --}}
            <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.1rem;flex-shrink:0;
                background:{{ $pos === 1 ? '#fbbf24' : ($pos === 2 ? '#cbd5e1' : ($pos === 3 ? '#d9a066' : 'var(--border)')) }};
                color:{{ $pos <= 3 ? '#fff' : 'var(--text-mid)' }};" title="المركز رقم {{ $pos }} بلوحة المتصدرين">
                {{ $pos <= 3 ? ['🥇','🥈','🥉'][$pos-1] : $pos }}
            </div>

            <img src="{{ $vol->avatar_url }}" style="width:44px;height:44px;border-radius:50%;border:2px solid var(--border);">

            <div class="flex-grow-1">
                <a href="{{ route('volunteers.show', $vol) }}" style="font-weight:700;text-decoration:none;color:var(--text-dark);">{{ $vol->name }}</a>
                @if($vol->city)<div style="font-size:.82rem;color:var(--text-light);"><i class="bi bi-geo-alt me-1"></i>{{ $vol->city }}</div>@endif
            </div>

            @if($profile)
            <div class="d-flex gap-4 text-center">
                <div><div style="font-weight:800;font-size:1.1rem;color:var(--primary);">{{ $profile->points }}</div><small class="text-muted" style="font-size:.72rem;">نقطة</small></div>
                <div><div style="font-weight:800;font-size:1.1rem;">{{ $profile->total_hours_contributed }}</div><small class="text-muted" style="font-size:.72rem;">ساعة</small></div>
                <div><div style="font-weight:800;font-size:1.1rem;">{{ $profile->completed_projects }}</div><small class="text-muted" style="font-size:.72rem;">مشروع</small></div>
            </div>

            {{-- شارة المستوى: إنجاز ثابت حسب مجموع النقاط --}}
            <div class="d-flex align-items-center gap-1" style="background:{{ $badge['bg'] }};border:1px solid {{ $badge['color'] }}33;border-radius:30px;padding:5px 12px;flex-shrink:0;" title="مستوى {{ $badge['label'] }} — من {{ $badge['min'] }} نقطة">
                <span style="font-size:1rem;">{{ $badge['icon'] }}</span>
                <span style="font-weight:700;font-size:.8rem;color:{{ $badge['color'] }};">{{ $badge['label'] }}</span>
            </div>
            @endif
        </div>
        @empty
            <div class="p-4 text-center text-muted">ما في متطوعين حصلوا نقاط لسا 🌱</div>
        @endforelse
    </div>
</div>
@endsection