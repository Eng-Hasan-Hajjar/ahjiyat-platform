@extends('layouts.app')
@section('title', 'مقارنة قبل/بعد - ' . $milestone->title)

@push('styles')
<style>
.compare-col { background: #fff; border-radius: var(--radius); padding: 20px; height: 100%; }
.compare-col h5 { font-family:'Cairo',sans-serif; font-weight: 800; margin-bottom: 16px; display:flex; align-items:center; gap: 8px; }
.compare-img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px,1fr)); gap: 10px; }
.compare-img-grid img { width: 100%; height: 130px; object-fit: cover; border-radius: 8px; }
</style>
@endpush

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-columns-gap me-2"></i>مقارنة قبل / بعد</h1>
    <p>{{ $project->title }} — مرحلة: {{ $milestone->title }}</p>
</div>

<div class="mb-3">
    <a href="{{ route('media.index', $project) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-right me-1"></i>العودة لكل الملفات
    </a>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="compare-col" style="border:2px solid #fecaca;">
            <h5 style="color:#b91c1c;"><i class="bi bi-clock-history"></i>قبل ({{ $before->count() }})</h5>
            @if($before->isNotEmpty())
            <div class="compare-img-grid">
                @foreach($before as $item)
                    @if($item->media_type === 'image')
                        <a href="{{ $item->url }}" target="_blank"><img src="{{ $item->url }}"></a>
                    @else
                        <a href="{{ $item->url }}" target="_blank" class="d-flex align-items-center justify-content-center" style="height:130px;background:#1a2332;border-radius:8px;color:#fff;font-size:1.8rem;">
                            <i class="bi bi-play-circle-fill"></i>
                        </a>
                    @endif
                @endforeach
            </div>
            @else
            <p class="text-muted text-center py-4">لا توجد صور "قبل" لهذه المرحلة</p>
            @endif
        </div>
    </div>

    <div class="col-md-6">
        <div class="compare-col" style="border:2px solid #bbf7d0;">
            <h5 style="color:#15803d;"><i class="bi bi-check-circle"></i>بعد ({{ $after->count() }})</h5>
            @if($after->isNotEmpty())
            <div class="compare-img-grid">
                @foreach($after as $item)
                    @if($item->media_type === 'image')
                        <a href="{{ $item->url }}" target="_blank"><img src="{{ $item->url }}"></a>
                    @else
                        <a href="{{ $item->url }}" target="_blank" class="d-flex align-items-center justify-content-center" style="height:130px;background:#1a2332;border-radius:8px;color:#fff;font-size:1.8rem;">
                            <i class="bi bi-play-circle-fill"></i>
                        </a>
                    @endif
                @endforeach
            </div>
            @else
            <p class="text-muted text-center py-4">لا توجد صور "بعد" لهذه المرحلة بعد</p>
            @endif
        </div>
    </div>
</div>

@if($milestone->description)
<div class="card mt-4">
    <div class="card-body">
        <strong>وصف المرحلة:</strong>
        <p class="mb-0 text-muted mt-1">{{ $milestone->description }}</p>
    </div>
</div>
@endif
@endsection