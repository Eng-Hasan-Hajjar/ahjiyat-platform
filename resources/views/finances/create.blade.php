@extends('layouts.app')
@section('title', 'تسجيل حركة مالية - ' . $project->title)

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-plus-circle me-2"></i>تسجيل حركة مالية جديدة</h1>
    <p>{{ $project->title }}</p>
</div>

<div class="card" style="max-width:700px;margin:0 auto;">
    <div class="card-body p-4">
        <form action="{{ route('finances.store', $project) }}" method="POST" enctype="multipart/form-data">
            @csrf

            {{-- Entry Type Toggle --}}
            <div class="mb-4">
                <label class="form-label d-block">نوع الحركة</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="entry_type" id="type_donation" value="donation"
                            {{ old('entry_type', $entryType) === 'donation' ? 'checked' : '' }}>
                        <label class="form-check-label" for="type_donation">
                            <i class="bi bi-arrow-down-circle text-success me-1"></i>تبرع وارد
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="entry_type" id="type_expense" value="expense"
                            {{ old('entry_type', $entryType) === 'expense' ? 'checked' : '' }}>
                        <label class="form-check-label" for="type_expense">
                            <i class="bi bi-arrow-up-circle text-danger me-1"></i>مصروف صادر
                        </label>
                    </div>
                </div>
                @error('entry_type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">العنوان <span class="text-danger">*</span></label>
                    <input type="text" name="title" value="{{ old('title') }}" class="form-control @error('title') is-invalid @enderror"
                        placeholder="مثال: تبرع لشراء مواد دهان" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">التصنيف <span class="text-danger">*</span></label>
                    <select name="category" class="form-select @error('category') is-invalid @enderror" required>
                        <option value="">اختر التصنيف</option>
                        <optgroup label="تصنيفات التبرع" class="donation-cat">
                            <option value="cash">تبرع نقدي</option>
                            <option value="in_kind">تبرع عيني</option>
                            <option value="volunteer_hours_value">قيمة ساعات تطوع</option>
                        </optgroup>
                        <optgroup label="تصنيفات المصروف" class="expense-cat">
                            <option value="materials">مواد بناء</option>
                            <option value="labor">أجور عمالة</option>
                            <option value="equipment">معدات</option>
                            <option value="transport">نقل ومواصلات</option>
                            <option value="other">أخرى</option>
                        </optgroup>
                    </select>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">المبلغ <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="amount" step="0.01" min="0.01" value="{{ old('amount') }}"
                            class="form-control @error('amount') is-invalid @enderror" required>
                        <span class="input-group-text">ل.س</span>
                    </div>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">تاريخ الحركة <span class="text-danger">*</span></label>
                    <input type="date" name="entry_date" value="{{ old('entry_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}"
                        class="form-control @error('entry_date') is-invalid @enderror" required>
                    @error('entry_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Donor fields - only relevant for donations, shown/hidden via JS --}}
                <div class="col-md-6 donor-field">
                    <label class="form-label">اسم المتبرع</label>
                    <input type="text" name="donor_name" value="{{ old('donor_name') }}" class="form-control" placeholder="اختياري">
                </div>

                <div class="col-12 donor-field">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="donor_anonymous" id="anon" value="1" {{ old('donor_anonymous') ? 'checked' : '' }}>
                        <label class="form-check-label" for="anon">إخفاء اسم المتبرع (متبرع مجهول)</label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label">الوصف</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="تفاصيل إضافية...">{{ old('description') }}</textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">مرفق إثبات (فاتورة / إيصال / صورة)</label>
                    <input type="file" name="attachment" class="form-control @error('attachment') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png">
                    <small class="text-muted">PDF أو صورة، حتى 5 ميجابايت</small>
                    @error('attachment')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="alert alert-info mt-4 d-flex gap-2 align-items-center">
                <i class="bi bi-info-circle-fill"></i>
                <small>ستظهر هذه الحركة بحالة "بانتظار المراجعة" حتى يعتمدها المشرف ضماناً للشفافية والتدقيق.</small>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>حفظ الحركة</button>
                <a href="{{ route('finances.index', $project) }}" class="btn btn-outline-secondary px-4">إلغاء</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleByType() {
    const isDonation = document.getElementById('type_donation').checked;
    document.querySelectorAll('.donor-field').forEach(el => el.style.display = isDonation ? '' : 'none');
    document.querySelectorAll('.donation-cat').forEach(el => el.style.display = isDonation ? '' : 'none');
    document.querySelectorAll('.expense-cat').forEach(el => el.style.display = isDonation ? 'none' : '');
}
document.getElementById('type_donation').addEventListener('change', toggleByType);
document.getElementById('type_expense').addEventListener('change', toggleByType);
document.addEventListener('DOMContentLoaded', toggleByType);
</script>
@endsection