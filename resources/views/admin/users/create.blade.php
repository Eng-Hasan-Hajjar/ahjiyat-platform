@extends('layouts.app')
@section('title', 'إضافة مستخدم')
@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-person-plus me-2"></i>إضافة مستخدم جديد</h1>
            <p>إنشاء حساب جديد من لوحة الإدارة</p>
        </div>
        <a href="{{ route('admin.users') }}" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-right me-1"></i>العودة
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-4">
                <form action="{{ route('admin.users.store') }}" method="POST">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الاسم الكامل *</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">البريد الإلكتروني *</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الدور *</label>
                            <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                                <option value="volunteer"     {{ old('role','volunteer') === 'volunteer'     ? 'selected':'' }}>متطوع</option>
                                <option value="project_owner" {{ old('role') === 'project_owner' ? 'selected':'' }}>صاحب مشروع</option>
                                <option value="admin"         {{ old('role') === 'admin' ? 'selected':'' }}>مدير</option>
                                <option value="committee"     {{ old('role') === 'committee' ? 'selected':'' }}>عضو لجنة</option>
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المدينة</label>
                            <select name="city" class="form-select">
                                <option value="">اختر المدينة</option>
                                @foreach(['دمشق','حلب','حمص','حماة','اللاذقية','طرطوس','درعا','السويداء','دير الزور','الرقة','القامشلي','إدلب'] as $city)
                                    <option value="{{ $city }}" {{ old('city') === $city ? 'selected':'' }}>{{ $city }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="tel" name="phone" class="form-control" value="{{ old('phone') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">كلمة المرور *</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                                placeholder="8 أحرف على الأقل" required>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تأكيد كلمة المرور *</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                                <input type="checkbox" name="is_active" value="1" checked style="accent-color:var(--primary);width:18px;height:18px;">
                                <span>الحساب نشط</span>
                            </label>
                        </div>
                        <div class="col-12 d-flex gap-3 pt-2">
                            <button type="submit" class="btn btn-primary px-5 fw-bold">
                                <i class="bi bi-person-plus me-2"></i>إنشاء الحساب
                            </button>
                            <a href="{{ route('admin.users') }}" class="btn btn-outline-secondary">إلغاء</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection