@extends('layouts.app')
@section('title', 'إعدادات النظام')
@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-gear me-2"></i>إعدادات النظام</h1>
    <p>إدارة إعدادات المنصة العامة</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-primary"></i>معلومات المنصة</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">اسم المنصة</label>
                        <input type="text" class="form-control" value="{{ config('app.name') }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">بيئة التطبيق</label>
                        <input type="text" class="form-control" value="{{ config('app.env') }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">إصدار Laravel</label>
                        <input type="text" class="form-control" value="{{ app()->version() }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">إصدار PHP</label>
                        <input type="text" class="form-control" value="{{ phpversion() }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">قاعدة البيانات</label>
                        <input type="text" class="form-control" value="{{ config('database.default') }}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">المنطقة الزمنية</label>
                        <input type="text" class="form-control" value="{{ config('app.timezone') }}" readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-bar-chart me-2 text-primary"></i>إحصائيات سريعة</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <tbody>
                            @php
                                $rows = [
                                    ['المستخدمون الكل',              \App\Models\User::count()],
                                    ['المتطوعون',                     \App\Models\User::where('role','volunteer')->count()],
                                    ['أصحاب المشاريع',                \App\Models\User::where('role','project_owner')->count()],
                                    ['المشاريع الكلية',               \App\Models\Project::count()],
                                    ['المشاريع المعلّقة',             \App\Models\Project::where('status','pending')->count()],
                                    ['المشاريع النشطة',               \App\Models\Project::whereIn('status',['approved','in_progress'])->count()],
                                    ['المشاريع المكتملة',             \App\Models\Project::where('status','completed')->count()],
                                    ['المهام الكلية',                  \App\Models\Task::count()],
                                    ['المهام المكتملة',                \App\Models\Task::where('status','completed')->count()],
                                    ['إجمالي التبرعات',               number_format(\App\Models\Donation::sum('amount')) . ' ل.س'],
                                    ['إجمالي الإشعارات',              \Illuminate\Notifications\DatabaseNotification::count()],
                                ];
                            @endphp
                            @foreach($rows as [$label, $value])
                            <tr>
                                <td style="font-weight:600;color:var(--text-mid);">{{ $label }}</td>
                                <td style="font-weight:700;color:var(--primary);">{{ $value }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-tools me-2 text-primary"></i>أدوات النظام</div>
            <div class="card-body d-grid gap-2">
                <form action="{{ route('admin.cache.clear') }}" method="POST" onsubmit="return confirm('مسح الكاش؟')">
                    @csrf
                    <button class="btn btn-outline-warning w-100 btn-sm fw-bold">
                        <i class="bi bi-trash me-2"></i>مسح الكاش
                    </button>
                </form>
                <a href="{{ route('admin.reports') }}" class="btn btn-outline-primary btn-sm fw-bold">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>عرض التقارير
                </a>
                <a href="{{ route('admin.announcements.create') }}" class="btn btn-outline-primary btn-sm fw-bold">
                    <i class="bi bi-megaphone me-2"></i>إعلان جديد
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>آخر نشاط</div>
            <div class="card-body p-0">
                @foreach(\App\Models\User::latest()->take(5)->get() as $u)
                <div class="p-3 border-bottom d-flex gap-2 align-items-center">
                    <img src="{{ $u->avatar_url }}" style="width:32px;height:32px;border-radius:50%;">
                    <div>
                        <div style="font-size:.85rem;font-weight:600;">{{ $u->name }}</div>
                        <small class="text-muted">{{ $u->created_at->diffForHumans() }}</small>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection