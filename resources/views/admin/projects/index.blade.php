@extends('layouts.app')
@section('title', 'إدارة المشاريع')
@section('content')

    <div class="page-header mb-4">
        <h1><i class="bi bi-buildings-fill me-2"></i>إدارة المشاريع</h1>
        <p>مراجعة وإدارة جميع المشاريع المسجّلة</p>
    </div>

    {{-- Filter --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">الحالة</label>
                        <select name="status" class="form-select">
                            <option value="">كل الحالات</option>
                            @foreach(['pending' => 'معلق', 'approved' => 'موافق', 'in_progress' => 'جارٍ', 'completed' => 'مكتمل', 'rejected' => 'مرفوض'] as $v => $l)
                                <option value="{{ $v }}" {{ request('status') === $v ? 'selected' : '' }}>
                                    {{ $l }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">المدينة</label>
                        <select name="city" class="form-select">
                            <option value="">كل المدن</option>
                            @foreach($cities as $c)
                                <option value="{{ $c }}" {{ request('city') === $c ? 'selected' : '' }}>
                                    {{ $c }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>بحث
                        </button>
                        <a href="{{ route('admin.projects') }}" class="btn btn-outline-secondary">
                            إعادة ضبط
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المشروع</th>
                            <th>الحالة</th>
                            <th>الأولوية</th>
                            <th>الضرر</th>
                            <th>المدينة</th>
                            <th>المتطوعون</th>
                            <th>التاريخ</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($projects as $p)
                            <tr>
                                <td><span class="text-muted" style="font-weight:700;">#{{ $p->id }}</span></td>
                                <td>
                                    <div style="font-weight:600;font-size:.9rem;">
                                        {{ Str::limit($p->title, 35) }}
                                    </div>
                                    <small class="text-muted">{{ $p->owner->name ?? 'مستخدم محذوف' }}</small>
                                </td>
                                <td>
                                    <span class="badge status-{{ $p->status }}">
                                        {{ $p->status_arabic }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge priority-{{ $p->priority }}">
                                        {{ $p->priority_arabic }}
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight:700;
                                                 color:{{ $p->priority_color }};">
                                        {{ $p->damage_percentage }}%
                                    </span>
                                </td>
                                <td>{{ $p->city }}</td>
                                <td>
                                    <span style="font-weight:600;">
                                        {{ $p->volunteers_assigned }}/{{ $p->volunteers_needed }}
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        {{ $p->created_at->format('d/m/Y') }}
                                    </small>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('projects.show', $p) }}" class="btn btn-sm btn-outline-primary"
                                            title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @if($p->status === 'pending')
                                            <form action="{{ route('admin.projects.approve', $p) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-success" title="موافقة">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                            <form action="{{ route('admin.projects.reject', $p) }}" method="POST" class="d-inline"
                                                onsubmit="return confirm('تأكيد الرفض؟')">
                                                @csrf
                                                <input type="hidden" name="rejection_reason" value="رُفض من قبل الإدارة">
                                                <button class="btn btn-sm btn-outline-danger" title="رفض">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </form>
                                        @elseif($p->status === 'approved')
                                            <form action="{{ route('admin.projects.start', $p) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-info text-white" title="بدء التنفيذ">
                                                    <i class="bi bi-play-fill"></i>
                                                </button>
                                            </form>
                                        @elseif($p->status === 'in_progress')
                                            <form action="{{ route('admin.projects.complete', $p) }}" method="POST"
                                                class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-success" title="إنهاء">
                                                    <i class="bi bi-check2-all"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    لا توجد مشاريع
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $projects->links() }}</div>

@endsection