@extends('layouts.app')
@section('title', 'المتابعة المالية - ' . $project->title)

@push('styles')
<style>
.finance-summary-card { border-radius: var(--radius); padding: 24px; text-align: center; }
.finance-summary-card .num { font-size: 1.9rem; font-weight: 900; font-family: 'Cairo', sans-serif; }
.entry-row { transition: background .15s; }
.entry-row:hover { background: var(--primary-pale); }
.donor-pill { font-size: .78rem; background: #f1f5f9; color: var(--text-mid); padding: 2px 10px; border-radius: 20px; }
</style>
@endpush

@section('content')
<div class="page-header mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="bi bi-cash-stack me-2"></i>المتابعة المالية</h1>
            <p>{{ $project->title }} — سجل التبرعات والمصروفات الموثّقة</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('finances.create', $project) }}?type=donation" class="btn btn-sm" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.4);font-weight:600;">
                <i class="bi bi-plus-circle me-1"></i>تسجيل تبرع
            </a>
            <a href="{{ route('finances.create', $project) }}?type=expense" class="btn btn-sm" style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.4);font-weight:600;">
                <i class="bi bi-plus-circle me-1"></i>تسجيل مصروف
            </a>
        </div>
    </div>
</div>

@include('projects.nav-tabs', ['project' => $project, 'active' => 'finances'])

{{-- Financial Summary --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card finance-summary-card" style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);">
            <div class="num" style="color:#15803d;">{{ number_format($summary['total_donations'], 0) }}</div>
            <div class="text-muted small mt-1">إجمالي التبرعات الموثّقة (ل.س)</div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card finance-summary-card" style="background:linear-gradient(135deg,#fee2e2,#fecaca);">
            <div class="num" style="color:#b91c1c;">{{ number_format($summary['total_expenses'], 0) }}</div>
            <div class="text-muted small mt-1">إجمالي المصروفات الموثّقة (ل.س)</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card finance-summary-card" style="background:linear-gradient(135deg,#E3F2FD,#bde0fb);">
            <div class="num" style="color:#1d4ed8;">{{ number_format($summary['balance'], 0) }}</div>
            <div class="text-muted small mt-1">الرصيد المتبقي (ل.س)</div>
        </div>
    </div>
</div>

@if($summary['pending_count'] > 0)
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-hourglass-split fs-5"></i>
    <div>يوجد <strong>{{ $summary['pending_count'] }}</strong> حركة مالية بانتظار مراجعة المشرف واعتمادها.</div>
</div>
@endif

{{-- Finance Records Table --}}
<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2 text-primary"></i>سجل الحركات المالية</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>النوع</th>
                        <th>العنوان</th>
                        <th>التصنيف</th>
                        <th>المبلغ</th>
                        <th>التاريخ</th>
                        <th>المُدخِل / المتبرع</th>
                        <th>الحالة</th>
                        <th>مرفق</th>
                        @can('verifyFinance', $project)
                        <th>إجراءات</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse($finances as $f)
                    <tr class="entry-row">
                        <td>
                            <span class="badge" style="background:{{ $f->entry_type === 'donation' ? '#dcfce7' : '#fee2e2' }};color:{{ $f->entry_type === 'donation' ? '#15803d' : '#b91c1c' }};">
                                <i class="bi bi-{{ $f->entry_type === 'donation' ? 'arrow-down-circle' : 'arrow-up-circle' }} me-1"></i>{{ $f->entry_type_arabic }}
                            </span>
                        </td>
                        <td style="font-weight:600;">{{ $f->title }}</td>
                        <td><small class="text-muted">{{ $f->category_arabic }}</small></td>
                        <td style="font-weight:700;color:{{ $f->entry_type === 'donation' ? '#15803d' : '#b91c1c' }};">
                            {{ number_format($f->amount, 2) }} {{ $f->currency }}
                        </td>
                        <td><small>{{ $f->entry_date->format('Y-m-d') }}</small></td>
                        <td>
                            @if($f->entry_type === 'donation')
                                <span class="donor-pill">{{ $f->donor_display_name }}</span>
                            @else
                                <small class="text-muted">{{ $f->recordedBy->name ?? '-' }}</small>
                            @endif
                        </td>
                        <td>
                            <span class="badge status-{{ $f->status === 'verified' ? 'completed' : ($f->status === 'rejected' ? 'rejected' : 'pending') }}">
                                {{ $f->status_arabic }}
                            </span>
                            @if($f->status === 'rejected' && $f->rejection_reason)
                                <i class="bi bi-info-circle text-danger ms-1" title="{{ $f->rejection_reason }}"></i>
                            @endif
                        </td>
                        <td>
                            @if($f->attachment_path)
                                <a href="{{ asset('storage/' . $f->attachment_path) }}" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                    <i class="bi bi-paperclip"></i>
                                </a>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        @can('verifyFinance', $project)
                        <td>
                            @if($f->status === 'pending_review')
                            <div class="d-flex gap-1">
                                <form action="{{ route('admin.finances.verify', $f) }}" method="POST">
                                    @csrf
                                    <button class="btn btn-sm btn-success py-0 px-2" title="اعتماد"><i class="bi bi-check-lg"></i></button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $f->id }}" title="رفض">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>

                            {{-- Reject Modal --}}
                            <div class="modal fade" id="rejectModal{{ $f->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form action="{{ route('admin.finances.reject', $f) }}" method="POST">
                                            @csrf
                                            <div class="modal-header">
                                                <h6 class="modal-title">سبب رفض الحركة المالية</h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="وضّح سبب الرفض..."></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                <button type="submit" class="btn btn-danger">تأكيد الرفض</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        @endcan
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <div style="font-size:2.5rem;">💰</div>
                            لا توجد حركات مالية مسجّلة بعد
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($finances->hasPages())
    <div class="card-footer bg-white">
        {{ $finances->links() }}
    </div>
    @endif
</div>
@endsection