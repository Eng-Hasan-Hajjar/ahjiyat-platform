@extends('layouts.app')
@section('title', 'إدارة المستخدمين')
@section('content')

    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1><i class="bi bi-people-fill me-2"></i>إدارة المستخدمين</h1>
                <p>عرض وإدارة جميع المستخدمين المسجّلين في المنصة</p>
            </div>
            <a href="{{ route('admin.users.create') }}" class="btn btn-sm" style="background:rgba(255,255,255,.25);color:#fff;
                      border:1px solid rgba(255,255,255,.4);font-weight:600;">
                <i class="bi bi-person-plus me-1"></i>مستخدم جديد
            </a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">بحث</label>
                        <input type="text" name="search" class="form-control" placeholder="ابحث بالاسم أو البريد..."
                            value="{{ request('search') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">الدور</label>
                        <select name="role" class="form-select">
                            <option value="">كل الأدوار</option>
                            <option value="volunteer" {{ request('role') === 'volunteer' ? 'selected' : '' }}>
                                متطوع
                            </option>
                            <option value="project_owner" {{ request('role') === 'project_owner' ? 'selected' : '' }}>
                                صاحب مشروع
                            </option>
                            <option value="admin" {{ request('role') === 'admin' ? 'selected' : '' }}>
                                مدير
                            </option>
                            <option value="committee" {{ request('role') === 'committee' ? 'selected' : '' }}>
                                عضو لجنة
                            </option>
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button class="btn btn-primary">
                            <i class="bi bi-search me-1"></i>بحث
                        </button>
                        <a href="{{ route('admin.users') }}" class="btn btn-outline-secondary">
                            إعادة ضبط
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-people me-2 text-primary"></i>
                المستخدمون ({{ $users->total() }})
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>الدور</th>
                            <th>المدينة</th>
                            <th>تاريخ التسجيل</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $user)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="{{ $user->avatar_url }}" style="width:36px;height:36px;border-radius:50%;">
                                        <div>
                                            <div style="font-weight:600;font-size:.9rem;">
                                                {{ $user->name }}
                                            </div>
                                            <small class="text-muted">{{ $user->email }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" style="background:var(--primary-pale);color:var(--primary);">
                                        {{ $user->role_arabic }}
                                    </span>
                                </td>
                                <td>{{ $user->city ?? '—' }}</td>
                                <td>
                                    <small class="text-muted">
                                        {{ $user->created_at->format('d/m/Y') }}
                                    </small>
                                </td>
                                <td>
                                    @if($user->is_active)
                                        <span class="badge" style="background:#dcfce7;color:#15803d;">نشط</span>
                                    @else
                                        <span class="badge" style="background:#fecaca;color:#b91c1c;">معطّل</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-primary"
                                            title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.users.toggle', $user) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button
                                                class="btn btn-sm {{ $user->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                title="{{ $user->is_active ? 'تعطيل' : 'تفعيل' }}">
                                                <i class="bi bi-{{ $user->is_active ? 'pause' : 'play' }}-circle"></i>
                                            </button>
                                        </form>
                                        @if(!$user->isAdmin())
                                            <form action="{{ route('admin.users.delete', $user) }}" method="POST" class="d-inline"
                                                onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم؟')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" title="حذف">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>

@endsection