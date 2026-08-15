@extends('layouts.app')
@section('title', 'ملفي الشخصي')
@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-person-gear me-2"></i>ملفي الشخصي</h1>
    <p>تحديث بياناتك الشخصية ومهاراتك لتحصل على فرص تطوع مناسبة</p>
</div>
<div class="row g-4">
    {{-- Profile Summary --}}
    <div class="col-lg-4">
        <div class="card mb-4 text-center">
            <div class="card-body p-4">
                <img src="{{ $user->avatar_url }}" class="avatar-xl mb-3" style="border:4px solid var(--primary-pale);">
                <h5 style="font-weight:700;">{{ $user->name }}</h5>
                <span class="badge" style="background:var(--primary-pale);color:var(--primary);">متطوع</span>
                @if($profile && $profile->points > 0)
                    @php $badge = $profile->badge; @endphp
                    <div class="mt-2">
                        <span style="font-size:1.5rem;">{{ $badge['icon'] }}</span>
                        <span style="font-size:.85rem;font-weight:600;color:{{ $badge['color'] }};">{{ $badge['label'] }}</span>
                    </div>
                @endif
                <div class="row g-2 mt-3 text-center">
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;color:var(--primary);">{{ $profile->points ?? 0 }}</div>
                        <small class="text-muted" style="font-size:.75rem;">نقطة</small>
                    </div>
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;">{{ $profile->total_hours_contributed ?? 0 }}</div>
                        <small class="text-muted" style="font-size:.75rem;">ساعة</small>
                    </div>
                    <div class="col-4">
                        <div style="font-weight:800;font-size:1.3rem;color:#f59e0b;">{{ number_format($profile->rating ?? 0, 1) }}</div>
                        <small class="text-muted" style="font-size:.75rem;">تقييم</small>
                    </div>
                </div>
            </div>
        </div>
        @if($profile && !empty($profile->skills))
        <div class="card">
            <div class="card-header"><i class="bi bi-tools me-2 text-primary"></i>مهاراتي</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    @foreach($profile->skills_arabic as $label)
                        <span class="badge" style="background:var(--primary-pale);color:var(--primary);font-size:.82rem;padding:6px 12px;">{{ $label }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Edit Form --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil me-2 text-primary"></i>تعديل المعلومات</div>
            <div class="card-body p-4">
                <form action="{{ route('volunteer.profile.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <h6 style="font-weight:700;color:var(--primary);margin-bottom:16px;">المعلومات الشخصية</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">الاسم الكامل</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم الهاتف</label>
                            <input type="tel" name="phone" class="form-control" value="{{ old('phone', $user->phone) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المدينة</label>
                            <select name="city" class="form-select">
                                @foreach(['دمشق','حلب','حمص','حماة','اللاذقية','طرطوس','درعا','السويداء','دير الزور','الرقة','القامشلي','إدلب'] as $city)
                                    <option value="{{ $city }}" {{ old('city', $user->city) === $city ? 'selected' : '' }}>{{ $city }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">صورة شخصية</label>
                            <input type="file" name="avatar" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12">
                            <label class="form-label">العنوان</label>
                            <input type="text" name="address" class="form-control" value="{{ old('address', $user->address) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">نبذة شخصية</label>
                            <textarea name="bio" class="form-control" rows="3" maxlength="1000">{{ old('bio', $user->bio) }}</textarea>
                        </div>
                    </div>

                    <h6 style="font-weight:700;color:var(--primary);margin-bottom:16px;">معلومات التطوع</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">مستوى الخبرة</label>
                            <select name="experience_level" class="form-select">
                                <option value="beginner" {{ old('experience_level', $profile->experience_level ?? '') === 'beginner' ? 'selected' : '' }}>مبتدئ</option>
                                <option value="intermediate" {{ old('experience_level', $profile->experience_level ?? '') === 'intermediate' ? 'selected' : '' }}>متوسط</option>
                                <option value="expert" {{ old('experience_level', $profile->experience_level ?? '') === 'expert' ? 'selected' : '' }}>خبير</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ساعات التطوع أسبوعياً</label>
                            <input type="number" name="hours_per_week" class="form-control" value="{{ old('hours_per_week', $profile->hours_per_week ?? 5) }}" min="0" max="168">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">مسافة التنقل (كم)</label>
                            <input type="number" name="travel_distance_km" class="form-control" value="{{ old('travel_distance_km', $profile->travel_distance_km ?? 10) }}" min="1">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                                <input type="checkbox" name="has_vehicle" value="1"
                                    {{ old('has_vehicle', $profile->has_vehicle ?? false) ? 'checked' : '' }}
                                    style="accent-color:var(--primary);width:18px;height:18px;">
                                <span>أمتلك سيارة للتنقل</span>
                            </label>
                        </div>
                        <div class="col-12">
                            <label class="form-label">مهاراتي</label>
                            <div class="row g-2">
                                @foreach($skills as $key => $label)
                                <div class="col-6 col-md-4">
                                    <label class="d-flex align-items-center gap-2 p-2 border rounded-2" style="cursor:pointer;transition:.15s;" onmouseenter="this.style.background='var(--primary-pale)'" onmouseleave="this.style.background=''">
                                        <input type="checkbox" name="skills[]" value="{{ $key }}"
                                            {{ in_array($key, old('skills', $profile->skills ?? [])) ? 'checked' : '' }}
                                            style="accent-color:var(--primary);">
                                        <span style="font-size:.88rem;">{{ $label }}</span>
                                    </label>
                                </div>
                                @endforeach
                            </div>
                            <input type="text" name="other_skill_note" class="form-control form-control-sm mt-2"
                                placeholder="إذا اخترت «أخرى» وضّح هون شو مهارتك (مثال: نجارة ألمنيوم، برمجة...)"
                                value="{{ old('other_skill_note', $profile->other_skill_note ?? '') }}" maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label">أيام التوفر</label>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach(['saturday'=>'السبت','sunday'=>'الأحد','monday'=>'الاثنين','tuesday'=>'الثلاثاء','wednesday'=>'الأربعاء','thursday'=>'الخميس','friday'=>'الجمعة'] as $v => $l)
                                <label class="d-flex align-items-center gap-1" style="cursor:pointer;border:1.5px solid var(--border);border-radius:20px;padding:5px 12px;font-size:.85rem;transition:.15s;" onmouseenter="this.style.borderColor='var(--primary)'" onmouseleave="this.style.borderColor='var(--border)'">
                                    <input type="checkbox" name="availability[]" value="{{ $v }}"
                                        {{ in_array($v, old('availability', $profile->availability ?? [])) ? 'checked' : '' }}
                                        style="accent-color:var(--primary);">
                                    {{ $l }}
                                </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-5 fw-bold">
                        <i class="bi bi-save me-2"></i>حفظ التعديلات
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection