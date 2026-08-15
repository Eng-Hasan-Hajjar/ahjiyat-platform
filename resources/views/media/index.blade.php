@extends('layouts.app')
@section('title', 'الصور والفيديوهات التوثيقية - ' . $project->title)

@push('styles')
    <style>
        .media-filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .media-filter-tabs a {
            padding: 8px 18px;
            border-radius: 25px;
            text-decoration: none;
            font-size: .88rem;
            font-weight: 600;
            color: var(--text-mid);
            background: #fff;
            border: 1.5px solid var(--border);
            transition: all .2s;
        }

        .media-filter-tabs a:hover,
        .media-filter-tabs a.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        .media-item {
            border-radius: var(--radius-sm);
            overflow: hidden;
            position: relative;
            background: #fff;
            border: 1px solid var(--border);
            transition: all .25s;
        }

        .media-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .media-thumb {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            background: #f1f5f9;
        }

        .media-thumb-video {
            width: 100%;
            height: 160px;
            background: #1a2332;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 2.5rem;
        }

        .media-phase-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: .72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .phase-before {
            background: #fecaca;
            color: #b91c1c;
        }

        .phase-during {
            background: #fde9b8;
            color: #b45309;
        }

        .phase-after {
            background: #bbf7d0;
            color: #15803d;
        }

        .media-caption {
            padding: 10px 12px;
            font-size: .82rem;
            color: var(--text-mid);
        }

        .media-delete-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(0, 0, 0, .6);
            color: #fff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
        }
    </style>
@endpush

@section('content')
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1><i class="bi bi-images me-2"></i>التوثيق المرئي</h1>
                <p>{{ $project->title }} — صور وفيديوهات قبل وأثناء وبعد كل مرحلة</p>
            </div>
      
        </div>
    </div>

    <button class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
        <i class="bi bi-cloud-upload me-1"></i>رفع ملفات جديدة
    </button>

    @include('projects.nav-tabs', ['project' => $project, 'active' => 'media'])

    {{-- Filter Tabs --}}
    <div class="media-filter-tabs">
        <a href="{{ route('media.index', $project) }}" class="{{ !$phase ? 'active' : '' }}">الكل
            ({{ $project->media->count() }})</a>
        <a href="{{ route('media.index', $project) }}?phase=before" class="{{ $phase === 'before' ? 'active' : '' }}">قبل
            ({{ $project->media->where('phase', 'before')->count() }})</a>
        <a href="{{ route('media.index', $project) }}?phase=during" class="{{ $phase === 'during' ? 'active' : '' }}">أثناء
            العمل ({{ $project->media->where('phase', 'during')->count() }})</a>
        <a href="{{ route('media.index', $project) }}?phase=after" class="{{ $phase === 'after' ? 'active' : '' }}">بعد
            ({{ $project->media->where('phase', 'after')->count() }})</a>
    </div>

    {{-- Milestone comparison shortcuts --}}
    @if($milestones->isNotEmpty())
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-columns-gap me-2 text-primary"></i>مقارنات قبل/بعد حسب المرحلة</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    @foreach($milestones as $m)
                        <a href="{{ route('media.compare', [$project, $m->id]) }}" class="btn btn-sm btn-outline-primary">
                            {{ $m->title }} <i class="bi bi-arrow-left ms-1"></i>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Media Grid --}}
    <div class="card">
        <div class="card-body">
            @if($media->isNotEmpty())
                <div class="media-grid">
                    @foreach($media as $item)
                        <div class="media-item">
                            <span class="media-phase-badge phase-{{ $item->phase }}">{{ $item->phase_arabic }}</span>

                            @can('update', $project)
                                <form action="{{ route('media.destroy', $item) }}" method="POST"
                                    onsubmit="return confirm('حذف هذا الملف؟')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="media-delete-btn"><i class="bi bi-trash"></i></button>
                                </form>
                            @endcan

                            @if($item->media_type === 'image')
                                <a href="{{ $item->url }}" target="_blank">
                                    <img src="{{ $item->url }}" class="media-thumb" loading="lazy">
                                </a>
                            @else
                                <a href="{{ $item->url }}" target="_blank" class="text-decoration-none">
                                    <div class="media-thumb-video"><i class="bi bi-play-circle-fill"></i></div>
                                </a>
                            @endif

                            <div class="media-caption">
                                @if($item->milestone)
                                    <div style="font-weight:600;color:var(--text-dark);">{{ $item->milestone->title }}</div>
                                @endif
                                @if($item->caption)
                                    <div>{{ $item->caption }}</div>
                                @endif
                                <small class="text-muted">{{ $item->created_at->format('Y-m-d') }}</small>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($media->hasPages())
                    <div class="mt-4">{{ $media->links() }}</div>
                @endif
            @else
                <div class="text-center py-5 text-muted">
                    <div style="font-size:3rem;">📷</div>
                    لا توجد ملفات بهذا التصنيف بعد
                </div>
            @endif
        </div>
    </div>

    {{-- Upload Modal --}}
    @can('update', $project)
        <div class="modal fade" id="uploadMediaModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="{{ route('media.store', $project) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="modal-header">
                            <h6 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>رفع صور / فيديوهات</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">الملفات (يمكن اختيار عدة ملفات) <span
                                        class="text-danger">*</span></label>
                                <input type="file" name="files[]" class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.avi" multiple required>
                                <small class="text-muted">صور أو فيديوهات، حتى 50 ميجابايت لكل ملف، 10 ملفات كحد أقصى</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">المرحلة <span class="text-danger">*</span></label>
                                <select name="phase" class="form-select" required>
                                    <option value="before">قبل</option>
                                    <option value="during" selected>أثناء العمل</option>
                                    <option value="after">بعد</option>
                                </select>
                            </div>
                            @if($milestones->isNotEmpty())
                                <div class="mb-3">
                                    <label class="form-label">ربط بمرحلة محددة (اختياري)</label>
                                    <select name="milestone_id" class="form-select">
                                        <option value="">بدون ربط</option>
                                        @foreach($milestones as $m)
                                            <option value="{{ $m->id }}">{{ $m->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="mb-2">
                                <label class="form-label">وصف مختصر</label>
                                <input type="text" name="caption" class="form-control" placeholder="اختياري">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" class="btn btn-primary">رفع الملفات</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
@endsection