@extends('layouts.app')
@section('title', 'المشاريع')

@section('content')
    <div class="page-header mb-4">
        <h1><i class="bi bi-buildings me-2"></i>المشاريع المتاحة</h1>
        <p>تصفح المشاريع التي تحتاج لمساعدتك وقدّم طلب التطوع</p>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('projects.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">بحث</label>
                        <input type="text" name="search" class="form-control" placeholder="ابحث بعنوان المشروع..."
                            value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">المدينة</label>
                        <select name="city" class="form-select">
                            <option value="">كل المدن</option>
                            @foreach($cities as $city)
                                <option value="{{ $city }}" {{ request('city') === $city ? 'selected' : '' }}>{{ $city }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">النوع</label>
                        <select name="type" class="form-select">
                            <option value="">كل الأنواع</option>
                            @foreach(['shop' => 'محل', 'workshop' => 'ورشة', 'clinic' => 'عيادة', 'bakery' => 'مخبز', 'restaurant' => 'مطعم', 'mosque' => 'مسجد', 'pharmacy' => 'صيدلية', 'other' => 'أخرى'] as $v => $l)
                                <option value="{{ $v }}" {{ request('type') === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">الأولوية</label>
                        <select name="priority" class="form-select">
                            <option value="">كل الأولويات</option>
                            @foreach(['critical' => 'حرجة', 'high' => 'عالية', 'medium' => 'متوسطة', 'low' => 'منخفضة'] as $v => $l)
                                <option value="{{ $v }}" {{ request('priority') === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ترتيب حسب</label>
                        <select name="sort" class="form-select">
                            <option value="latest" {{ request('sort', 'latest') === 'latest' ? 'selected' : '' }}>الأحدث
                            </option>
                            <option value="priority" {{ request('sort') === 'priority' ? 'selected' : '' }}>الأولوية</option>
                            <option value="damage" {{ request('sort') === 'damage' ? 'selected' : '' }}>نسبة الضرر</option>
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>بحث</button>
                        <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">إعادة ضبط</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Results Count --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <span style="color:var(--text-mid);font-size:.9rem;">
            <i class="bi bi-grid me-1"></i>{{ $projects->total() }} مشروع
        </span>
        @auth
            @if(auth()->user()->isProjectOwner())
                <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i>إضافة مشروع
                </a>
            @endif
        @endauth
    </div>

    {{-- Projects Grid --}}
    @if($projects->isEmpty())
        <div class="card text-center py-5">
            <div style="font-size:4rem;margin-bottom:16px;">🔍</div>
            <h5 class="text-muted">لا توجد مشاريع تطابق بحثك</h5>
            <a href="{{ route('projects.index') }}" class="btn btn-outline-primary mt-3">عرض جميع المشاريع</a>
        </div>
    @else
        <div class="row g-4 mb-4">
            @foreach($projects as $project)
                <div class="col-md-6 col-lg-4">
                    <div class="card project-card h-100">
                        {{-- Image or Placeholder --}}
                        @php $imgs = json_decode($project->before_images ?? '[]', true); @endphp
                        @if(!empty($imgs))
                            <img src="{{ asset('storage/' . $imgs[0]) }}" class="card-img-top" alt="{{ $project->title }}">
                        @else
                            <div class="card-img-placeholder">
                                {{ match ($project->type) {
                                'shop' => '🏪', 'workshop' => '🔧', 'clinic' => '🏥', 'bakery' => '🥖',
                                'restaurant' => '🍽️', 'mosque' => '🕌', 'pharmacy' => '💊', default => '🏗️'
                            } }}
                            </div>
                        @endif

                        <div class="card-body d-flex flex-column">
                            {{-- Badges --}}
                            <div class="d-flex gap-2 flex-wrap mb-2">
                                <span class="badge priority-{{ $project->priority }}">{{ $project->priority_arabic }}</span>
                                <span class="badge status-{{ $project->status }}">{{ $project->status_arabic }}</span>
                                <span
                                    style="font-size:.75rem;background:#f1f5f9;color:#475569;border-radius:20px;padding:3px 8px;">{{ $project->type_arabic }}</span>
                            </div>

                            <small class="text-muted" style="font-weight:700;">#{{ $project->id }}</small>
                            <h6 class="card-title fw-bold mb-2" style="line-height:1.4;">{{ $project->title }}</h6>
                
                
                            <p class="card-text text-muted small flex-grow-1" style="line-height:1.6;">
                                {{ Str::limit($project->description, 100) }}</p>

                            {{-- Meta --}}
                            <div class="d-flex justify-content-between small text-muted mb-3">
                                <span><i class="bi bi-geo-alt me-1"></i>{{ $project->city }}</span>
                                <span><i class="bi bi-people me-1"></i>{{ $project->volunteers_needed }} متطوع</span>
                                <span style="color:{{ $project->priority_color }};">💔 {{ $project->damage_percentage }}%</span>
                            </div>

                            {{-- Progress --}}
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">التقدم</small>
                                    <small
                                        style="font-weight:700;color:var(--primary);">{{ $project->progress_percentage }}%</small>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width:{{ $project->progress_percentage }}%"></div>
                                </div>
                            </div>

                            <a href="{{ route('projects.show', $project) }}" class="btn btn-primary btn-sm w-100">
                                عرض التفاصيل <i class="bi bi-arrow-left ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        {{ $projects->links() }}
    @endif
@endsection