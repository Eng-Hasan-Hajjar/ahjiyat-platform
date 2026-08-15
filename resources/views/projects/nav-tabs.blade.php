{{--
    مكوّن تبويبات تنقل داخل المشروع
    الاستخدام: @include('projects.partials.nav-tabs', ['project' => $project, 'active' => 'timeline'])
    القيم الممكنة لـ active: details | timeline | finances | media | approvals
--}}
<style>
.project-nav-tabs {
    display: flex; gap: 4px; margin-bottom: 24px; overflow-x: auto;
    border-bottom: 2px solid var(--border); padding-bottom: 0;
}
.project-nav-tabs a {
    display: flex; align-items: center; gap: 6px;
    padding: 12px 18px; text-decoration: none; white-space: nowrap;
    color: var(--text-mid); font-weight: 600; font-size: .9rem;
    border-bottom: 3px solid transparent; transition: all .2s;
    flex-shrink: 0;
}
.project-nav-tabs a:hover { color: var(--primary); background: var(--primary-pale); }
.project-nav-tabs a.active { color: var(--primary); border-bottom-color: var(--primary); }
.project-nav-tabs .tab-badge {
    background: var(--danger); color: #fff; font-size: .68rem;
    border-radius: 20px; padding: 1px 6px; font-weight: 700;
}
</style>

<div class="project-nav-tabs">
    <a href="{{ route('projects.show', $project) }}" class="{{ $active === 'details' ? 'active' : '' }}">
        <i class="bi bi-info-circle"></i>تفاصيل المشروع
    </a>
    <a href="{{ route('projects.timeline', $project) }}" class="{{ $active === 'timeline' ? 'active' : '' }}">
        <i class="bi bi-diagram-3"></i>المتابعة الشاملة
    </a>
    <a href="{{ route('finances.index', $project) }}" class="{{ $active === 'finances' ? 'active' : '' }}">
        <i class="bi bi-cash-stack"></i>المتابعة المالية
    </a>
    <a href="{{ route('media.index', $project) }}" class="{{ $active === 'media' ? 'active' : '' }}">
        <i class="bi bi-images"></i>التوثيق المرئي
    </a>
    <a href="{{ route('approvals.index', $project) }}" class="{{ $active === 'approvals' ? 'active' : '' }}">
        <i class="bi bi-file-earmark-check"></i>الموافقة الخطية
        @if(!$project->has_approved_consent)
            <span class="tab-badge">!</span>
        @endif
    </a>
</div>