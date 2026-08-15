@php $user = auth()->user(); @endphp

{{-- User Mini Profile --}}
<div class="px-4 pb-4 mb-2" style="border-bottom:1px solid var(--border)">
    <div class="d-flex align-items-center gap-3">
        <img src="{{ $user->avatar_url }}" class="avatar" style="width:46px;height:46px;">
        <div style="min-width:0">
            <div style="font-weight:700;font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $user->name }}</div>
            <span class="badge" style="background:var(--primary-pale);color:var(--primary);font-size:.72rem;">{{ $user->role_arabic }}</span>
        </div>
    </div>
</div>

{{-- Common Links --}}
<div class="sidebar-section-title">الرئيسية</div>
<a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
    <i class="bi bi-grid-1x2"></i> لوحة التحكم
</a>
<a href="{{ route('projects.index') }}" class="sidebar-link {{ request()->routeIs('projects.index') ? 'active' : '' }}">
    <i class="bi bi-buildings"></i> تصفح المشاريع
</a>
<a href="{{ route('notifications.index') }}" class="sidebar-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
    <i class="bi bi-bell"></i> الإشعارات
    @if($user->unreadNotifications->count() > 0)
        <span class="badge ms-auto" style="background:var(--danger);color:#fff;">{{ $user->unreadNotifications->count() }}</span>
    @endif
</a>

{{-- Volunteer Links --}}
@if($user->isVolunteer())
    <div class="sidebar-section-title">حسابي</div>
    <a href="{{ route('volunteer.profile') }}" class="sidebar-link {{ request()->routeIs('volunteer.profile') ? 'active' : '' }}">
        <i class="bi bi-person-gear"></i> ملفي الشخصي
    </a>
    <a href="{{ route('volunteer.applications') }}" class="sidebar-link {{ request()->routeIs('volunteer.applications') ? 'active' : '' }}">
        <i class="bi bi-clipboard-check"></i> طلباتي للتطوع
    </a>
    <a href="{{ route('volunteers.leaderboard') }}" class="sidebar-link {{ request()->routeIs('volunteers.leaderboard') ? 'active' : '' }}">
        <i class="bi bi-trophy"></i> لوحة المتصدرين
    </a>
    <a href="{{ route('volunteers.index') }}" class="sidebar-link {{ request()->routeIs('volunteers.index') ? 'active' : '' }}">
        <i class="bi bi-people"></i> المتطوعون
    </a>
@endif

{{-- Project Owner Links --}}
@if($user->isProjectOwner())
    <div class="sidebar-section-title">مشاريعي</div>
    <a href="{{ route('projects.mine') }}" class="sidebar-link {{ request()->routeIs('projects.mine') ? 'active' : '' }}">
        <i class="bi bi-buildings"></i> مشاريعي
    </a>
    <a href="{{ route('projects.create') }}" class="sidebar-link {{ request()->routeIs('projects.create') ? 'active' : '' }}">
        <i class="bi bi-plus-circle"></i> إضافة مشروع
    </a>
@endif

{{-- Committee Links --}}
@if($user->isCommittee())
    <div class="sidebar-section-title">لجنة التحقق</div>
    <a href="{{ route('committee.index') }}" class="sidebar-link {{ request()->routeIs('committee.*') ? 'active' : '' }}">
        <i class="bi bi-clipboard-check"></i> مراجعة المشاريع
    </a>
@endif


{{-- Admin Links --}}
@if($user->isAdmin())
    <div class="sidebar-section-title">الإدارة</div>
    <a href="{{ route('admin.users') }}" class="sidebar-link {{ request()->routeIs('admin.users*') ? 'active' : '' }}">
        <i class="bi bi-people-fill"></i> إدارة المستخدمين
    </a>
    <a href="{{ route('admin.projects') }}" class="sidebar-link {{ request()->routeIs('admin.projects') ? 'active' : '' }}">
        <i class="bi bi-buildings-fill"></i> إدارة المشاريع
    </a>
    <a href="{{ route('admin.announcements') }}" class="sidebar-link {{ request()->routeIs('admin.announcements*') ? 'active' : '' }}">
        <i class="bi bi-megaphone"></i> الإعلانات
    </a>
    <a href="{{ route('admin.settings') }}" class="sidebar-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
        <i class="bi bi-gear"></i> إعدادات النظام
    </a>
    <a href="{{ route('admin.donations') }}" class="sidebar-link {{ request()->routeIs('admin.donations') ? 'active' : '' }}">
        <i class="bi bi-heart"></i> التبرعات
    </a>
    <a href="{{ route('admin.reports') }}" class="sidebar-link {{ request()->routeIs('admin.reports') ? 'active' : '' }}">
        <i class="bi bi-bar-chart-line"></i> التقارير
    </a>
@endif

{{-- Logout --}}
<div class="px-3 mt-4">
    <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button type="submit" class="btn btn-outline-danger w-100 btn-sm" style="border-radius:var(--radius-sm);">
            <i class="bi bi-box-arrow-right me-2"></i>تسجيل الخروج
        </button>
    </form>
</div>