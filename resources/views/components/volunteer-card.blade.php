@props(['volunteer'])

@php
    $profile = $volunteer->volunteerProfile;
    $badge   = $profile?->badge ?? ['icon' => '🌱', 'label' => 'مبتدئ', 'color' => '#4F7942'];
    $allSkills = \App\Models\VolunteerProfile::allSkills();
@endphp

<div class="card h-100" style="transition:all .25s;border-radius:var(--radius);"
     onmouseenter="this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)'"
     onmouseleave="this.style.transform='';this.style.boxShadow=''">
    <div class="card-body p-4">
        {{-- Avatar & Name --}}
        <div class="d-flex gap-3 mb-3">
            <img src="{{ $volunteer->avatar_url }}" class="avatar-lg"
                 style="border:3px solid var(--primary-pale);flex-shrink:0;">
            <div class="min-w-0">
                <h6 style="font-weight:700;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    {{ $volunteer->name }}
                </h6>
                @if($volunteer->city)
                    <small class="text-muted d-block">
                        <i class="bi bi-geo-alt me-1"></i>{{ $volunteer->city }}
                    </small>
                @endif
                <span style="font-size:.75rem;font-weight:600;color:{{ $badge['color'] }};">
                    {{ $badge['icon'] }} {{ $badge['label'] }}
                </span>
            </div>
        </div>

        {{-- Stats --}}
        @if($profile)
        <div class="d-flex justify-content-around text-center border-top border-bottom py-2 mb-3">
            <div>
                <div style="font-weight:800;color:var(--primary);">{{ number_format($profile->points) }}</div>
                <small class="text-muted" style="font-size:.72rem;">نقطة</small>
            </div>
            <div>
                <div style="font-weight:800;">{{ $profile->total_hours_contributed }}</div>
                <small class="text-muted" style="font-size:.72rem;">ساعة</small>
            </div>
            <div>
                <div style="font-weight:800;color:#f59e0b;">
                    {{ number_format($profile->rating, 1) }}
                    <i class="bi bi-star-fill" style="font-size:.7rem;"></i>
                </div>
                <small class="text-muted" style="font-size:.72rem;">تقييم</small>
            </div>
        </div>

        {{-- Skills --}}
        @if(!empty($profile->skills))
        <div class="d-flex flex-wrap gap-1 mb-3">
            @foreach(array_slice($profile->skills, 0, 3) as $skill)
                <span class="badge" style="background:var(--primary-pale);color:var(--primary);font-size:.72rem;">
                    {{ $allSkills[$skill] ?? $skill }}
                </span>
            @endforeach
            @if(count($profile->skills) > 3)
                <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:.72rem;">
                    +{{ count($profile->skills) - 3 }}
                </span>
            @endif
        </div>
        @endif
        @endif

        <a href="{{ route('volunteers.show', $volunteer) }}"
           class="btn btn-outline-primary btn-sm w-100">
            <i class="bi bi-person me-1"></i>عرض الملف الشخصي
        </a>
    </div>
</div>