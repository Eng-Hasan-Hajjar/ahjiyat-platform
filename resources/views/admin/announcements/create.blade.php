@extends('layouts.app')
@section('title', 'إعلان جديد')
@section('content')

<div class="page-header mb-4">
    <h1><i class="bi bi-megaphone me-2"></i>إضافة إعلان جديد</h1>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4">
                <form action="{{ route('admin.announcements.store') }}" method="POST">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">
                            عنوان الإعلان <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="title"
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title') }}" required>
                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            محتوى الإعلان <span class="text-danger">*</span>
                        </label>
                        <textarea name="content" rows="6"
                                  class="form-control @error('content') is-invalid @enderror"
                                  required minlength="10">{{ old('content') }}</textarea>
                        @error('content')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">الموجّه إلى</label>
                            <select name="target" class="form-select">
                                <option value="all"
                                    {{ old('target','all') === 'all' ? 'selected' : '' }}>
                                    👥 الجميع
                                </option>
                                <option value="volunteers"
                                    {{ old('target') === 'volunteers' ? 'selected' : '' }}>
                                    🙋 المتطوعون فقط
                                </option>
                                <option value="owners"
                                    {{ old('target') === 'owners' ? 'selected' : '' }}>
                                    🏪 أصحاب المشاريع فقط
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <label class="d-flex align-items-center gap-2"
                                   style="cursor:pointer;">
                                <input type="checkbox" name="is_published"
                                       value="1" checked
                                       style="accent-color:var(--primary);
                                              width:18px;height:18px;">
                                <span>نشر فوراً</span>
                            </label>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary px-5 fw-bold">
                            <i class="bi bi-send me-2"></i>نشر الإعلان
                        </button>
                        <a href="{{ route('admin.announcements') }}"
                           class="btn btn-outline-secondary">
                            إلغاء
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection