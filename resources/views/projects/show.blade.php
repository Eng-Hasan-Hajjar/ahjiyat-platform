@extends('layouts.app')
@section('title', $project->title)

@section('content')
    {{-- Breadcrumb --}}
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb" style="font-size:.87rem;">
            <li class="breadcrumb-item"><a href="{{ route('projects.index') }}" style="color:var(--primary);">المشاريع</a>
            </li>
            <li class="breadcrumb-item active">{{ Str::limit($project->title, 40) }}</li>
        </ol>
    </nav>

    @include('projects.nav-tabs', ['project' => $project, 'active' => 'details'])

    <div class="row g-4">
        {{-- Main Content --}}
        <div class="col-lg-8">
            {{-- Header Card --}}
            <div class="card mb-4" style="overflow:hidden;">
                @php $imgs = json_decode($project->before_images ?? '[]', true); @endphp
                @if(!empty($imgs))
                    <div style="max-height:300px;overflow:hidden;">
                        <img src="{{ asset('storage/' . $imgs[0]) }}" class="w-100" style="object-fit:cover;max-height:300px;">
                    </div>
                @else
                    <div
                        style="height:180px;background:linear-gradient(135deg,var(--primary),#1B5E35);display:flex;align-items:center;justify-content:center;font-size:5rem;color:rgba(255,255,255,.4);">
                        {{ match ($project->type) { 'shop' => '🏪', 'workshop' => '🔧', 'clinic' => '🏥', 'bakery' => '🥖', 'restaurant' => '🍽️', 'mosque' => '🕌', 'pharmacy' => '💊', default => '🏗️'} }}
                    </div>
                @endif

                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="badge priority-{{ $project->priority }}">{{ $project->priority_arabic }}</span>
                        <span class="badge status-{{ $project->status }}">{{ $project->status_arabic }}</span>
                        <span
                            style="font-size:.8rem;background:#f1f5f9;color:#475569;border-radius:20px;padding:4px 10px;">{{ $project->type_arabic }}</span>
                    </div>
                    <div style="font-size:.85rem;color:var(--text-mid);font-weight:700;margin-bottom:4px;">المشروع
                        #{{ $project->id }}</div>
                    <h2 style="font-family:'Cairo',sans-serif;font-weight:900;font-size:1.6rem;margin-bottom:8px;">
                        {{ $project->title }}</h2>


                    <div class="d-flex flex-wrap gap-4 text-muted mb-4" style="font-size:.9rem;">
                        <span><i class="bi bi-geo-alt me-1"></i>{{ $project->address }}، {{ $project->city }}</span>
                        <span><i class="bi bi-person me-1"></i>{{ $project->owner->name ?? 'مستخدم محذوف' }}</span>
                        <span><i class="bi bi-calendar3 me-1"></i>{{ $project->created_at->format('d M Y') }}</span>
                        @if($project->estimated_days)
                            <span><i class="bi bi-clock me-1"></i>{{ $project->estimated_days }} يوم تقديري</span>
                        @endif
                    </div>

                    {{-- Progress --}}
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span style="font-weight:700;font-size:.95rem;">تقدم المشروع</span>
                            <span
                                style="font-weight:900;font-size:1.1rem;color:var(--primary);">{{ $project->progress_percentage }}%</span>
                        </div>
                        <div class="progress" style="height:12px;">
                            <div class="progress-bar" style="width:{{ $project->progress_percentage }}%;border-radius:6px;">
                            </div>
                        </div>
                    </div>

                    {{-- Stats Row --}}
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3 text-center">
                            <div style="font-size:1.6rem;font-weight:900;color:var(--danger);">
                                {{ $project->damage_percentage }}%</div>
                            <small class="text-muted">نسبة الضرر</small>
                            <div style="font-size:.72rem;color:var(--text-light);">{{ $project->damage_level }}</div>
                        </div>
                        <div class="col-6 col-md-3 text-center">
                            <div style="font-size:1.6rem;font-weight:900;color:var(--primary);">
                                {{ $project->volunteers->count() }}/{{ $project->volunteers_needed }}</div>
                            <small class="text-muted">المتطوعون</small>
                        </div>
                        <div class="col-6 col-md-3 text-center">
                            <div style="font-size:1.6rem;font-weight:900;color:var(--text-dark);">
                                {{ $project->tasks->count() }}</div>
                            <small class="text-muted">المهام الكلية</small>
                        </div>
                        <div class="col-6 col-md-3 text-center">
                            <div style="font-size:1.6rem;font-weight:900;color:#22c55e;">
                                {{ $project->tasks->where('status', 'completed')->count() }}</div>
                            <small class="text-muted">مهام مكتملة</small>
                        </div>
                    </div>

                    @if($project->funding_type)
                    <div class="mb-4" style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:16px 18px;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-cash-stack" style="font-size:1.3rem;color:#1d4ed8;"></i>
                            <strong style="color:#1d4ed8;">معلومات التمويل</strong>
                        </div>
                        <div class="d-flex flex-wrap gap-4">
                            <div>
                                <div style="font-weight:700;font-size:.95rem;">{{ $project->funding_type_arabic }}</div>
                                <small class="text-muted">نوع التمويل</small>
                            </div>
                            @if($project->funding_organization)
                            <div>
                                <div style="font-weight:700;font-size:.95rem;">{{ $project->funding_organization }}</div>
                                <small class="text-muted">الجهة الداعمة</small>
                            </div>
                            @endif
                            @if($project->funding_amount)
                            <div>
                                <div style="font-weight:700;font-size:.95rem;color:#1d4ed8;">{{ number_format($project->funding_amount, 0) }} ل.س</div>
                                <small class="text-muted">قيمة التمويل</small>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endif

                    <h5 style="font-weight:700;margin-bottom:12px;">وصف المشروع</h5>
                    <p style="color:var(--text-mid);line-height:1.9;">{{ $project->description }}</p>

                    @if($project->notes)
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i><strong>ملاحظة:</strong> {{ $project->notes }}
                        </div>
                    @endif

                    {{-- Required Skills --}}
                    @if(!empty($project->required_skills))
                        <div class="mt-4">
                            <h6 style="font-weight:700;margin-bottom:10px;"><i
                                    class="bi bi-tools me-2 text-primary"></i>المهارات المطلوبة</h6>
                            <div class="d-flex flex-wrap gap-2">
                                @php $allSkills = \App\Models\VolunteerProfile::allSkills(); @endphp
                                @foreach($project->required_skills as $skill)
                                    <span class="badge"
                                        style="background:var(--primary-pale);color:var(--primary);font-size:.85rem;padding:6px 14px;">
                                        {{ $allSkills[$skill] ?? $skill }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Before / After Images --}}
                    @if(!empty($project->before_images_array))
                        <div class="mt-4">
                            <h6 style="font-weight:700;margin-bottom:10px;"><i class="bi bi-images me-2 text-primary"></i>صور
                                قبل الإعمار</h6>
                            <div class="row g-2">
                                @foreach($project->before_images_array as $img)
                                    <div class="col-4 col-md-3">
                                        <img src="{{ asset('storage/' . $img) }}" class="w-100 rounded-3"
                                            style="height:100px;object-fit:cover;cursor:pointer;" data-bs-toggle="modal"
                                            data-bs-target="#imgModal" onclick="showImg('{{ asset('storage/' . $img) }}')">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!empty($project->after_images_array))
                        <div class="mt-4">
                            <h6 style="font-weight:700;margin-bottom:10px;"><i class="bi bi-images me-2 text-success"></i>صور
                                بعد الإعمار</h6>
                            <div class="row g-2">
                                @foreach($project->after_images_array as $img)
                                    <div class="col-4 col-md-3">
                                        <img src="{{ asset('storage/' . $img) }}" class="w-100 rounded-3"
                                            style="height:100px;object-fit:cover;cursor:pointer;" data-bs-toggle="modal"
                                            data-bs-target="#imgModal" onclick="showImg('{{ asset('storage/' . $img) }}')">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Project Updates / Timeline --}}
            @if($project->updates->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-journal-text me-2 text-primary"></i>سجل التحديثات</div>
                    <div class="card-body">
                        @foreach($project->updates as $update)
                            <div class="d-flex gap-3 mb-4">
                                <div
                                    style="flex-shrink:0;width:40px;height:40px;border-radius:50%;background:var(--primary-pale);display:flex;align-items:center;justify-content:center;color:var(--primary);">
                                    <i class="bi bi-journal-check"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong>{{ $update->title }}</strong>
                                        <small class="text-muted">{{ $update->created_at->diffForHumans() }}</small>
                                    </div>
                                    <p style="font-size:.9rem;color:var(--text-mid);margin:6px 0;">{{ $update->description }}</p>
                                    <span
                                        style="background:var(--primary-pale);color:var(--primary);border-radius:20px;padding:2px 10px;font-size:.78rem;font-weight:600;">{{ $update->progress_percentage }}%
                                        تقدم</span>
                                    <small class="text-muted ms-2">بواسطة {{ $update->author->name ?? 'مستخدم محذوف' }}</small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Add Update (owner/admin) --}}
            @if(auth()->check() && (auth()->id() === $project->owner_id || auth()->user()->isAdmin()))
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>إضافة تحديث</div>
                    <div class="card-body">
                        <form action="{{ route('projects.updates.store', $project) }}" method="POST"
                            enctype="multipart/form-data">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-8">
                                    <input type="text" name="title" class="form-control" placeholder="عنوان التحديث" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="number" name="progress_percentage" class="form-control"
                                        placeholder="نسبة التقدم %" min="0" max="100"
                                        value="{{ $project->progress_percentage }}" required>
                                </div>
                                <div class="col-12">
                                    <textarea name="description" class="form-control" rows="3" placeholder="وصف التحديث..."
                                        required minlength="10"></textarea>
                                </div>
                                <div class="col-12">
                                    <input type="file" name="images[]" class="form-control" multiple accept="image/*">
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary">نشر التحديث</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Ratings --}}
            @if($project->ratings->isNotEmpty())
                <div class="card">
                    <div class="card-header"><i class="bi bi-star me-2 text-primary"></i>التقييمات</div>
                    <div class="card-body">
                        @foreach($project->ratings as $rating)
                            <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                                <img src="{{ $rating->rater->avatar_url }}" style="width:38px;height:38px;border-radius:50%;">
                                <div>
                                    <div style="font-weight:600;font-size:.9rem;">{{ $rating->rater->name }}</div>
                                    <div class="stars">
                                        {{ str_repeat('★', $rating->rating) }}{{ str_repeat('☆', 5 - $rating->rating) }}</div>
                                    @if($rating->comment)
                                        <p style="font-size:.88rem;color:var(--text-mid);margin:4px 0 0;">{{ $rating->comment }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Apply / Status Card --}}
            @auth
                @if(auth()->user()->isVolunteer())
                    <div class="card mb-4">
                        <div class="card-body text-center p-4">
                            @if($userApplication)
                                @if($userApplication->status === 'pending')
                                    <div style="font-size:2.5rem;margin-bottom:12px;">⏳</div>
                                    <h6 style="font-weight:700;">طلبك قيد المراجعة</h6>
                                    <p class="text-muted mb-0" style="font-size:.88rem;">سيتم إشعارك عند قبول طلبك</p>
                                @elseif($userApplication->status === 'accepted')
                                    <div style="font-size:2.5rem;margin-bottom:12px;">🎉</div>
                                    <h6 style="font-weight:700;color:var(--primary);">أنت عضو في هذا المشروع!</h6>
                                @else
                                    <div style="font-size:2.5rem;margin-bottom:12px;">😔</div>
                                    <h6 style="font-weight:700;">تم رفض طلبك</h6>
                                    @if($userApplication->rejection_reason)
                                        <p class="text-muted" style="font-size:.85rem;">{{ $userApplication->rejection_reason }}</p>
                                    @endif
                                @endif
                            @elseif($canApply)
                                <div style="font-size:2.5rem;margin-bottom:12px;">🙋</div>
                                <h5 style="font-weight:700;margin-bottom:8px;">هل تريد التطوع؟</h5>
                                <p class="text-muted mb-4" style="font-size:.9rem;">انضم للفريق وساهم في إعادة إعمار هذا المشروع</p>
                                <form action="{{ route('volunteer.apply', $project) }}" method="POST">
                                    @csrf
                                    <div class="mb-3">
                                        <textarea name="message" class="form-control" rows="3"
                                            placeholder="أخبرنا لماذا تريد التطوع (اختياري)"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">ساعات التطوع أسبوعياً</label>
                                        <input type="number" name="available_hours_per_week" class="form-control" min="1" max="60"
                                            value="10" required>
                                    </div>
                                    <button class="btn btn-primary w-100 fw-bold"><i class="bi bi-send me-2"></i>إرسال طلب
                                        التطوع</button>
                                </form>
                            @elseif($project->status !== 'approved')
                                <div style="font-size:2rem;margin-bottom:12px;">🔒</div>
                                <p class="text-muted">لا يمكن التطوع في هذا المشروع حالياً</p>
                            @else
                                <p class="text-muted">اكتمل عدد المتطوعين في هذا المشروع</p>
                            @endif
                        </div>
                    </div>
                @endif
            @else
                <div class="card mb-4">
                    <div class="card-body text-center p-4">
                        <div style="font-size:2.5rem;margin-bottom:12px;">🙋</div>
                        <h6 style="font-weight:700;">تريد التطوع في هذا المشروع؟</h6>
                        <p class="text-muted mb-3" style="font-size:.9rem;">سجّل دخولك للتمكن من التطوع</p>
                        <a href="{{ route('login') }}" class="btn btn-primary w-100">تسجيل الدخول</a>
                        <a href="{{ route('register') }}" class="btn btn-outline-primary w-100 mt-2">إنشاء حساب مجاني</a>
                    </div>
                </div>
            @endauth

            {{-- Volunteer Team --}}
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>فريق التطوع
                    ({{ $project->volunteers->count() }})</div>
                <div class="card-body p-0">
                    @forelse($project->volunteers->where('pivot.status', 'accepted') as $vol)
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <img src="{{ $vol->avatar_url }}" style="width:36px;height:36px;border-radius:50%;">
                            <div class="flex-grow-1">
                                <div style="font-weight:600;font-size:.88rem;">{{ $vol->name }}</div>
                                @if($vol->pivot->role === 'team_leader')
                                    <small style="color:var(--accent);">⭐ قائد الفريق</small>
                                @else
                                    <small class="text-muted">عضو</small>
                                @endif
                            </div>
                            <small class="text-muted">{{ $vol->pivot->hours_contributed }}س</small>
                        </div>
                    @empty
                        <div class="p-3 text-center text-muted" style="font-size:.9rem;">لا يوجد متطوعون بعد</div>
                    @endforelse
                </div>
            </div>

            {{-- Donations --}}
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between">
                    <span><i class="bi bi-heart me-2 text-primary"></i>التبرعات</span>
                    @auth
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse"
                            data-bs-target="#donateForm">تبرع</button>
                    @endauth
                </div>
                <div class="collapse" id="donateForm">
                    <div class="card-body border-bottom">
                        <form action="{{ route('donations.store', $project) }}" method="POST">
                            @csrf
                            <div class="mb-2">
                                <select name="type" class="form-select form-select-sm">
                                    <option value="money">مال</option>
                                    <option value="materials">مواد بناء</option>
                                    <option value="tools">أدوات</option>
                                    <option value="food">طعام</option>
                                    <option value="other">أخرى</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <input type="number" name="amount" class="form-control form-control-sm"
                                    placeholder="المبلغ (اختياري)">
                            </div>
                            <div class="mb-2">
                                <textarea name="description" class="form-control form-control-sm" rows="2"
                                    placeholder="وصف التبرع"></textarea>
                            </div>
                            <button class="btn btn-primary btn-sm w-100">إرسال التبرع</button>
                        </form>
                    </div>
                </div>
                <div class="card-body p-0">
                    @forelse($project->donations->take(5) as $donation)
                        <div class="p-3 border-bottom d-flex gap-2 align-items-center">
                            <span
                                style="font-size:1.2rem;">{{ match ($donation->type) { 'money' => '💰', 'materials' => '🧱', 'tools' => '🔧', 'food' => '🍱', default => '🎁'} }}</span>
                            <div>
                                <div style="font-size:.88rem;font-weight:600;">{{ $donation->type_arabic }}</div>
                                <small class="text-muted">{{ $donation->donor?->name ?? 'متبرع مجهول' }}</small>
                            </div>
                            @if($donation->amount)
                                <span class="ms-auto"
                                    style="font-weight:700;color:var(--primary);">{{ number_format($donation->amount) }} ل.س</span>
                            @endif
                        </div>
                    @empty
                        <div class="p-3 text-center text-muted" style="font-size:.9rem;">لا توجد تبرعات بعد</div>
                    @endforelse
                </div>
            </div>

            {{-- Owner Actions --}}
            @if(auth()->check() && (auth()->id() === $project->owner_id || auth()->user()->isAdmin()))
                <div class="card">
                    <div class="card-header"><i class="bi bi-gear me-2 text-primary"></i>إجراءات المشروع</div>
                    <div class="card-body d-grid gap-2">
                        <a href="{{ route('tasks.index', $project) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-list-task me-1"></i>إدارة المهام
                        </a>
                        <a href="{{ route('applications.index', $project) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-people me-1"></i>طلبات التطوع
                        </a>
                        <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-pencil me-1"></i>تعديل المشروع
                        </a>
                        @if(auth()->user()->isAdmin())
                            @if($project->status === 'pending')
                                <form action="{{ route('admin.projects.approve', $project) }}" method="POST">
                                    @csrf
                                    <button class="btn btn-success btn-sm w-100"><i class="bi bi-check-circle me-1"></i>الموافقة على
                                        المشروع</button>
                                </form>
                                <button class="btn btn-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#rejectForm">رفض
                                    المشروع</button>
                                <div class="collapse" id="rejectForm">
                                    <form action="{{ route('admin.projects.reject', $project) }}" method="POST" class="mt-2">
                                        @csrf
                                        <textarea name="rejection_reason" class="form-control form-control-sm mb-2" rows="2"
                                            placeholder="سبب الرفض..." required></textarea>
                                        <button class="btn btn-danger btn-sm w-100">تأكيد الرفض</button>
                                    </form>
                                </div>
                            @elseif($project->status === 'approved')
                                <form action="{{ route('admin.projects.start', $project) }}" method="POST">
                                    @csrf
                                    <button class="btn btn-info btn-sm w-100 text-white">بدء تنفيذ المشروع</button>
                                </form>
                            @elseif($project->status === 'in_progress')
                                <form action="{{ route('admin.projects.complete', $project) }}" method="POST">
                                    @csrf
                                    <button class="btn btn-success btn-sm w-100">إغلاق كمكتمل ✅</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Image Modal --}}
    <div class="modal fade" id="imgModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0" style="background:transparent;">
                <div class="modal-body p-0 text-center">
                    <img id="modalImg" src="" class="img-fluid rounded-3" style="max-height:80vh;">
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function showImg(src) { document.getElementById('modalImg').src = src; }
        </script>
    @endpush
@endsection