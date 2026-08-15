@extends('layouts.app')
@section('title', 'التبرعات')
@section('content')

<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="bi bi-heart me-2"></i>التبرعات</h1>
            <p>إجمالي التبرعات المستلمة: <strong>{{ number_format($total) }} ل.س</strong></p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>المتبرع</th>
                        <th>المشروع</th>
                        <th>النوع</th>
                        <th>الوصف</th>
                        <th>المبلغ</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($donations as $d)
                    <tr>
                        <td>
                            @if($d->donor)
                                <div class="d-flex align-items-center gap-2">
                                    <img src="{{ $d->donor->avatar_url }}"
                                         style="width:32px;height:32px;border-radius:50%;">
                                    <span style="font-size:.9rem;font-weight:600;">
                                        {{ $d->donor->name }}
                                    </span>
                                </div>
                            @else
                                <span class="text-muted">مجهول</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('projects.show', $d->project) }}"
                               style="font-size:.88rem;color:var(--primary);">
                                {{ Str::limit($d->project->title, 30) }}
                            </a>
                        </td>
                        <td>
                            <span class="badge"
                                  style="background:var(--primary-pale);color:var(--primary);">
                                {{ $d->type_arabic }}
                            </span>
                        </td>
                        <td>
                            <small class="text-muted">
                                {{ Str::limit($d->description ?? '—', 40) }}
                            </small>
                        </td>
                        <td>
                            @if($d->amount)
                                <strong style="color:var(--primary);">
                                    {{ number_format($d->amount) }} ل.س
                                </strong>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge"
                                  style="background:#dcfce7;color:#15803d;">
                                {{ $d->status === 'received' ? 'مستلم' : 'مستخدم' }}
                            </span>
                        </td>
                        <td>
                            <small class="text-muted">
                                {{ $d->created_at->format('d/m/Y') }}
                            </small>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            لا توجد تبرعات بعد
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">{{ $donations->links() }}</div>

@endsection