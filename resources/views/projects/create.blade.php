@extends('layouts.app')
@section('title', 'إضافة مشروع جديد')

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-plus-circle me-2"></i>إضافة مشروع جديد</h1>
    <p>أدخل تفاصيل مشروعك للحصول على دعم المتطوعين</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body p-4">
                <form action="{{ route('projects.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    {{-- Section 1: Basic Info --}}
                    <div class="mb-4 pb-4 border-bottom">
                        <h5 style="font-weight:700;color:var(--primary);margin-bottom:20px;"><i class="bi bi-info-circle me-2"></i>المعلومات الأساسية</h5>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">عنوان المشروع <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
                                    value="{{ old('title') }}" placeholder="مثال: إعادة إعمار محل بقالة في حلب" required>
                                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">نوع المشروع <span class="text-danger">*</span></label>
                                <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                                    <option value="">اختر النوع</option>
                                    @foreach(['shop'=>'🏪 محل تجاري','workshop'=>'🔧 ورشة عمل','clinic'=>'🏥 عيادة','bakery'=>'🥖 مخبز','restaurant'=>'🍽️ مطعم','school'=>'🏫 مدرسة','mosque'=>'🕌 مسجد','pharmacy'=>'💊 صيدلية','other'=>'📦 أخرى'] as $v => $l)
                                        <option value="{{ $v }}" {{ old('type') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                    @endforeach
                                </select>
                                @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الأولوية <span class="text-danger">*</span></label>
                                <select name="priority" class="form-select @error('priority') is-invalid @enderror" required>
                                    <option value="">اختر الأولوية</option>
                                    <option value="critical" {{ old('priority') === 'critical' ? 'selected' : '' }}>🔴 حرجة — ضرر شديد جداً</option>
                                    <option value="high" {{ old('priority') === 'high' ? 'selected' : '' }}>🟠 عالية — ضرر شديد</option>
                                    <option value="medium" {{ old('priority') === 'medium' ? 'selected' : '' }}>🟡 متوسطة</option>
                                    <option value="low" {{ old('priority') === 'low' ? 'selected' : '' }}>🟢 منخفضة</option>
                                </select>
                                @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label">وصف تفصيلي للمشروع <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                                    rows="5" placeholder="اشرح وضع المشروع والأضرار التي لحقت به وما تحتاجه من دعم..." required minlength="50">{{ old('description') }}</textarea>
                                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="text-muted">50 حرف على الأقل</small>
                            </div>
                        </div>
                    </div>

                    {{-- Section 2: Damage & Resources --}}
                    <div class="mb-4 pb-4 border-bottom">
                        <h5 style="font-weight:700;color:var(--primary);margin-bottom:20px;"><i class="bi bi-graph-down me-2"></i>الضرر والاحتياجات</h5>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">نسبة الضرر (%) <span class="text-danger">*</span></label>
                                <input type="number" name="damage_percentage" class="form-control @error('damage_percentage') is-invalid @enderror"
                                    value="{{ old('damage_percentage', 50) }}" min="0" max="100" required oninput="updateDamageLabel(this.value)">
                                <div id="damageLabel" style="font-size:.82rem;margin-top:4px;font-weight:600;"></div>
                                @error('damage_percentage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">عدد المتطوعين المطلوبين <span class="text-danger">*</span></label>
                                <input type="number" name="volunteers_needed" class="form-control @error('volunteers_needed') is-invalid @enderror"
                                    value="{{ old('volunteers_needed', 3) }}" min="1" max="100" required>
                                @error('volunteers_needed')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">المدة التقديرية (بالأيام) <span class="text-danger">*</span></label>
                                <input type="number" name="estimated_days" class="form-control @error('estimated_days') is-invalid @enderror"
                                    value="{{ old('estimated_days', 7) }}" min="1" required>
                                @error('estimated_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">التكلفة التقديرية (ل.س)</label>
                                <input type="number" name="estimated_cost" class="form-control" value="{{ old('estimated_cost') }}" min="0" placeholder="اختياري">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">نوع التمويل</label>
                                <select name="funding_type" id="fundingType" class="form-select" onchange="toggleFundingFields()">
                                    <option value="">-- اختر --</option>
                                    <option value="self_funded" {{ old('funding_type') === 'self_funded' ? 'selected' : '' }}>تمويل ذاتي (من صاحب المشروع)</option>
                                    <option value="individual_donations" {{ old('funding_type') === 'individual_donations' ? 'selected' : '' }}>تبرعات أفراد</option>
                                    <option value="international_org" {{ old('funding_type') === 'international_org' ? 'selected' : '' }}>منظمة دولية</option>
                                    <option value="government" {{ old('funding_type') === 'government' ? 'selected' : '' }}>جهة حكومية</option>
                                    <option value="mixed" {{ old('funding_type') === 'mixed' ? 'selected' : '' }}>تمويل مختلط</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="fundingOrgWrap" style="display:none;">
                                <label class="form-label">اسم الجهة الداعمة</label>
                                <input type="text" name="funding_organization" class="form-control" value="{{ old('funding_organization') }}" placeholder="مثال: اليونيسف، الهلال الأحمر السوري...">
                            </div>
                            <div class="col-md-6" id="fundingAmountWrap" style="display:none;">
                                <label class="form-label">قيمة التمويل (ل.س)</label>
                                <input type="number" name="funding_amount" class="form-control" value="{{ old('funding_amount') }}" min="0" placeholder="اختياري">
                            </div>
                            <div class="col-12">
                                <label class="form-label">المهارات المطلوبة</label>
                                <div class="row g-2">
                                    @foreach(\App\Models\VolunteerProfile::allSkills() as $key => $label)
                                    <div class="col-6 col-md-3">
                                        <label class="d-flex align-items-center gap-2 p-2 border rounded-2" style="cursor:pointer;transition:.2s;" onmouseenter="this.style.background='var(--primary-pale)'" onmouseleave="this.style.background=''">
                                            <input type="checkbox" name="required_skills[]" value="{{ $key }}"
                                                {{ in_array($key, old('required_skills', [])) ? 'checked' : '' }}
                                                style="accent-color:var(--primary);">
                                            <span style="font-size:.88rem;">{{ $label }}</span>
                                        </label>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Section 3: Location --}}
                    <div class="mb-4 pb-4 border-bottom">
                        <h5 style="font-weight:700;color:var(--primary);margin-bottom:20px;"><i class="bi bi-geo-alt me-2"></i>الموقع</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">المدينة <span class="text-danger">*</span></label>
                                <select name="city" class="form-select @error('city') is-invalid @enderror" required>
                                    <option value="">اختر المدينة</option>
                                    @foreach(['دمشق','حلب','حمص','حماة','اللاذقية','طرطوس','درعا','السويداء','دير الزور','الرقة','القامشلي','إدلب'] as $city)
                                        <option value="{{ $city }}" {{ old('city') === $city ? 'selected' : '' }}>{{ $city }}</option>
                                    @endforeach
                                </select>
                                @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">العنوان التفصيلي <span class="text-danger">*</span></label>
                                <input type="text" name="address" class="form-control @error('address') is-invalid @enderror"
                                    value="{{ old('address') }}" placeholder="الحي، الشارع، رقم البناء..." required>
                                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    {{-- Section 4: Images --}}
                    <div class="mb-4">
                        <h5 style="font-weight:700;color:var(--primary);margin-bottom:20px;"><i class="bi bi-images me-2"></i>صور المشروع (قبل الإعمار)</h5>
                        <div class="border-2 border-dashed rounded-3 p-4 text-center" style="border:2px dashed var(--border);background:var(--bg-main);">
                            <input type="file" name="before_images[]" id="imgUpload" multiple accept="image/*" class="d-none" onchange="previewImages(this)">
                            <label for="imgUpload" style="cursor:pointer;">
                                <i class="bi bi-cloud-upload" style="font-size:2.5rem;color:var(--primary);"></i>
                                <div style="font-weight:600;margin-top:8px;">انقر لرفع الصور</div>
                                <small class="text-muted">PNG, JPG, JPEG — الحد الأقصى 5MB لكل صورة</small>
                            </label>
                            <div id="imgPreview" class="row g-2 mt-3"></div>
                        </div>
                    </div>

                    {{-- Notes --}}
                    <div class="mb-4">
                        <label class="form-label">ملاحظات إضافية</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="أي معلومات إضافية تريد إيصالها للمتطوعين...">{{ old('notes') }}</textarea>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary px-5 fw-bold">
                            <i class="bi bi-send me-2"></i>إرسال المشروع للمراجعة
                        </button>
                        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary px-4">إلغاء</a>
                    </div>

                    <small class="text-muted d-block mt-3"><i class="bi bi-info-circle me-1"></i>سيتم مراجعة مشروعك من قبل فريق الإدارة قبل نشره للمتطوعين.</small>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function updateDamageLabel(val) {
    const el = document.getElementById('damageLabel');
    const colors = { 0:'#22c55e', 25:'#f59e0b', 50:'#ef4444', 75:'#7c3aed' };
    const labels = val < 25 ? '🟢 خفيف' : val < 50 ? '🟡 متوسط' : val < 75 ? '🔴 شديد' : '🟣 شديد جداً';
    const color  = val < 25 ? '#15803d' : val < 50 ? '#b45309' : val < 75 ? '#b91c1c' : '#7c3aed';
    el.innerHTML = `<span style="color:${color};">${labels} (${val}%)</span>`;
}
updateDamageLabel(50);

function previewImages(input) {
    const preview = document.getElementById('imgPreview');
    preview.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const col = document.createElement('div');
            col.className = 'col-4 col-md-2';
            col.innerHTML = `<img src="${e.target.result}" class="w-100 rounded-2" style="height:80px;object-fit:cover;">`;
            preview.appendChild(col);
        };
        reader.readAsDataURL(file);
    });
}

function toggleFundingFields() {
    const type = document.getElementById('fundingType').value;
    document.getElementById('fundingOrgWrap').style.display = (type !== '' && type !== 'self_funded') ? 'block' : 'none';
    document.getElementById('fundingAmountWrap').style.display = (type !== '') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleFundingFields);
</script>
@endpush
@endsection