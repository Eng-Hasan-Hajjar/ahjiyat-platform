@extends('layouts.app')
@section('title', 'تعديل المشروع')
@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-pencil me-2"></i>تعديل المشروع</h1>
    <p>{{ $project->title }}</p>
</div>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card"><div class="card-body p-4">
            <form action="{{ route('projects.update', $project) }}" method="POST">
                @csrf @method('PUT')
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">عنوان المشروع *</label>
                        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $project->title) }}" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">نوع المشروع</label>
                        <select name="type" class="form-select">
                            @foreach(['shop'=>'محل تجاري','workshop'=>'ورشة عمل','clinic'=>'عيادة','bakery'=>'مخبز','restaurant'=>'مطعم','school'=>'مدرسة','mosque'=>'مسجد','pharmacy'=>'صيدلية','other'=>'أخرى'] as $v => $l)
                                <option value="{{ $v }}" {{ old('type', $project->type) === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الأولوية</label>
                        <select name="priority" class="form-select">
                            @foreach(['critical'=>'حرجة','high'=>'عالية','medium'=>'متوسطة','low'=>'منخفضة'] as $v => $l)
                                <option value="{{ $v }}" {{ old('priority', $project->priority) === $v ? 'selected' : '' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">الوصف *</label>
                        <textarea name="description" class="form-control" rows="5" required minlength="50">{{ old('description', $project->description) }}</textarea>
                    </div>
                    <div class="col-md-4"><label class="form-label">نسبة الضرر (%)</label>
                        <input type="number" name="damage_percentage" class="form-control" value="{{ old('damage_percentage', $project->damage_percentage) }}" min="0" max="100"></div>
                    <div class="col-md-4"><label class="form-label">عدد المتطوعين</label>
                        <input type="number" name="volunteers_needed" class="form-control" value="{{ old('volunteers_needed', $project->volunteers_needed) }}" min="1"></div>
                    <div class="col-md-4"><label class="form-label">المدة (أيام)</label>
                        <input type="number" name="estimated_days" class="form-control" value="{{ old('estimated_days', $project->estimated_days) }}" min="1"></div>
                    <div class="col-md-6">
                        <label class="form-label">المدينة</label>
                        <select name="city" class="form-select">
                            @foreach(['دمشق','حلب','حمص','حماة','اللاذقية','طرطوس','درعا','السويداء','دير الزور','الرقة','القامشلي','إدلب'] as $city)
                                <option value="{{ $city }}" {{ old('city', $project->city) === $city ? 'selected' : '' }}>{{ $city }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">العنوان</label>
                        <input type="text" name="address" class="form-control" value="{{ old('address', $project->address) }}"></div>
                    <div class="col-12">
                        <label class="form-label">المهارات المطلوبة</label>
                        <div class="row g-2">
                            @foreach(\App\Models\VolunteerProfile::allSkills() as $key => $label)
                            <div class="col-6 col-md-3">
                                <label class="d-flex align-items-center gap-2 p-2 border rounded-2" style="cursor:pointer;">
                                    <input type="checkbox" name="required_skills[]" value="{{ $key }}"
                                        {{ in_array($key, old('required_skills', $project->required_skills ?? [])) ? 'checked' : '' }}
                                        style="accent-color:var(--primary);">
                                    <span style="font-size:.88rem;">{{ $label }}</span>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">نوع التمويل</label>
                        <select name="funding_type" id="fundingTypeEdit" class="form-select" onchange="toggleFundingFieldsEdit()">
                            <option value="">-- اختر --</option>
                            <option value="self_funded" {{ old('funding_type', $project->funding_type) === 'self_funded' ? 'selected' : '' }}>تمويل ذاتي</option>
                            <option value="individual_donations" {{ old('funding_type', $project->funding_type) === 'individual_donations' ? 'selected' : '' }}>تبرعات أفراد</option>
                            <option value="international_org" {{ old('funding_type', $project->funding_type) === 'international_org' ? 'selected' : '' }}>منظمة دولية</option>
                            <option value="government" {{ old('funding_type', $project->funding_type) === 'government' ? 'selected' : '' }}>جهة حكومية</option>
                            <option value="mixed" {{ old('funding_type', $project->funding_type) === 'mixed' ? 'selected' : '' }}>تمويل مختلط</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="fundingOrgWrapEdit">
                        <label class="form-label">اسم الجهة الداعمة</label>
                        <input type="text" name="funding_organization" class="form-control" value="{{ old('funding_organization', $project->funding_organization) }}" placeholder="مثال: اليونيسف">
                    </div>
                    <div class="col-md-4" id="fundingAmountWrapEdit">
                        <label class="form-label">قيمة التمويل (ل.س)</label>
                        <input type="number" name="funding_amount" class="form-control" value="{{ old('funding_amount', $project->funding_amount) }}" min="0">
                    </div>
                    <div class="col-12"><label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $project->notes) }}</textarea></div>                   <div class="col-12 d-flex gap-3">
                        <button type="submit" class="btn btn-primary px-5 fw-bold">حفظ التعديلات</button>
                        <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary">إلغاء</a>
                    </div>
                </div>
            </form>
        </div></div>
    </div>
</div>

@push('scripts')
<script>
function toggleFundingFieldsEdit() {
    const type = document.getElementById('fundingTypeEdit').value;
    document.getElementById('fundingOrgWrapEdit').style.display = (type !== '' && type !== 'self_funded') ? 'block' : 'none';
    document.getElementById('fundingAmountWrapEdit').style.display = (type !== '') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleFundingFieldsEdit);
</script>
@endpush
@endsection