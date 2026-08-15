@extends('layouts.app')
@section('title', 'الموافقة الخطية - ' . $project->title)

@push('styles')
<style>
.consent-status-banner {
    border-radius: var(--radius);
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 24px;
}
.consent-status-banner.approved { background: linear-gradient(135deg,#dcfce7,#bbf7d0); }
.consent-status-banner.pending  { background: linear-gradient(135deg,#fef3c7,#fde9b8); }
.consent-status-banner.missing  { background: linear-gradient(135deg,#fee2e2,#fecaca); }
.consent-icon { font-size: 2.5rem; }
.approval-doc-card {
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 18px;
    margin-bottom: 12px;
}
</style>
@endpush

@section('content')
<div class="page-header mb-4">
    <h1><i class="bi bi-file-earmark-check me-2"></i>الموافقة الخطية الرسمية</h1>
    <p>{{ $project->title }} — توثيق قانوني لموافقة مالك المنشأة على إجراء أعمال إعادة الإعمار</p>
</div>

@include('projects.nav-tabs', ['project' => $project, 'active' => 'approvals'])

{{-- Status Banner --}}
@if($project->has_approved_consent)
    <div class="consent-status-banner approved">
        <div class="consent-icon">✅</div>
        <div>
            <h5 class="fw-bold mb-1" style="color:#15803d;">الموافقة الخطية معتمدة رسمياً</h5>
            <p class="mb-0 text-muted">تم التحقق من موافقة مالك المنشأة، ويمكن المتابعة بتنفيذ المشروع رسمياً.</p>
        </div>
    </div>
@elseif($approvals->where('status', 'pending_review')->count() > 0)
    <div class="consent-status-banner pending">
        <div class="consent-icon">⏳</div>
        <div>
            <h5 class="fw-bold mb-1" style="color:#b45309;">المستند بانتظار مراجعة المشرف</h5>
            <p class="mb-0 text-muted">تم رفع مستند الموافقة، وهو الآن قيد المراجعة من قبل إدارة المنصة.</p>
        </div>
    </div>
@else
    <div class="consent-status-banner missing">
        <div class="consent-icon">⚠️</div>
        <div>
            <h5 class="fw-bold mb-1" style="color:#b91c1c;">لا توجد موافقة خطية بعد</h5>
            <p class="mb-0 text-muted">يجب على صاحب المشروع رفع مستند الموافقة الموقّع قبل بدء التنفيذ الرسمي.</p>
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- Upload Form - Owner only, and only if no pending/approved doc exists --}}
    @can('update', $project)
        @if(!$project->has_approved_consent)
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-upload me-2 text-primary"></i>رفع مستند الموافقة</div>
                <div class="card-body">
                    <form action="{{ route('approvals.store', $project) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">ملف الموافقة الموقّعة (PDF) <span class="text-danger">*</span></label>
                            <input type="file" name="document" class="form-control @error('document') is-invalid @enderror" accept=".pdf" required>
                            <small class="text-muted">يُرجى رفع نسخة ممسوحة ضوئياً (Scan) من المستند موقّعاً يدوياً من مالك المنشأة. PDF فقط، حتى 10 ميجابايت.</small>
                            @error('document')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">رقم الهوية الوطنية للمالك</label>
                            <input type="text" name="owner_national_id" class="form-control" placeholder="اختياري - للتوثيق فقط">
                            <small class="text-muted">يُعرض جزئياً فقط للحفاظ على الخصوصية</small>
                        </div>

                        <div class="alert alert-info d-flex gap-2 align-items-start">
                            <i class="bi bi-info-circle-fill mt-1"></i>
                            <small>هذا المستند يجعل المشروع رسمياً وموثقاً قانونياً، ويُراجَع من قبل المشرف قبل اعتماده.</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>رفع المستند</button>
                    </form>
                </div>
            </div>
        </div>
        @endif
    @endcan

    {{-- Approvals List --}}
    <div class="col-lg-{{ (!$project->has_approved_consent && auth()->user()->can('update', $project)) ? 7 : 12 }}">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>سجل المستندات المرفوعة</div>
            <div class="card-body">
                @forelse($approvals as $approval)
                <div class="approval-doc-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:10px;background:var(--primary-pale);display:flex;align-items:center;justify-content:center;font-size:1.3rem;">📄</div>
                            <div>
                                <div style="font-weight:700;">مستند موافقة #{{ $approval->id }}</div>
                                <small class="text-muted">رُفع بواسطة {{ $approval->submittedBy->name ?? '-' }} في {{ $approval->created_at->format('Y-m-d') }}</small>
                                @if($approval->masked_national_id)
                                    <div><small class="text-muted">رقم الهوية: {{ $approval->masked_national_id }}</small></div>
                                @endif
                            </div>
                        </div>
                        <span class="badge status-{{ $approval->status === 'approved' ? 'completed' : ($approval->status === 'rejected' ? 'rejected' : 'pending') }}">
                            {{ $approval->status_arabic }}
                        </span>
                    </div>

                    @if($approval->review_notes)
                        <div class="alert {{ $approval->status === 'rejected' ? 'alert-danger' : 'alert-success' }} mt-3 mb-0 py-2 px-3" style="font-size:.88rem;">
                            <strong>ملاحظات المراجعة:</strong> {{ $approval->review_notes }}
                        </div>
                    @endif

                    <div class="d-flex gap-2 mt-3">
                        <a href="{{ $approval->document_url }}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>عرض المستند
                        </a>

                        @can('reviewApprovals', $project)
                            @if($approval->status === 'pending_review')
                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal{{ $approval->id }}">
                                <i class="bi bi-check-lg me-1"></i>اعتماد
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectApprovalModal{{ $approval->id }}">
                                <i class="bi bi-x-lg me-1"></i>رفض
                            </button>
                            @endif
                        @endcan

                        @can('update', $project)
                            @if($approval->status === 'pending_review')
                            <form action="{{ route('approvals.destroy', $approval) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا المستند؟')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i></button>
                            </form>
                            @endif
                        @endcan
                    </div>
                </div>

                {{-- Approve Modal --}}
                @can('reviewApprovals', $project)
                <div class="modal fade" id="approveModal{{ $approval->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form action="{{ route('admin.approvals.approve', $approval) }}" method="POST">
                                @csrf
                                <div class="modal-header">
                                    <h6 class="modal-title">اعتماد مستند الموافقة</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="text-muted small">بعد الاعتماد، سيصبح المشروع موثقاً رسمياً ويمكن بدء التنفيذ.</p>
                                    <textarea name="review_notes" class="form-control" rows="2" placeholder="ملاحظات (اختياري)"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                                    <button type="submit" class="btn btn-success">تأكيد الاعتماد</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="rejectApprovalModal{{ $approval->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form action="{{ route('admin.approvals.reject', $approval) }}" method="POST">
                                @csrf
                                <div class="modal-header">
                                    <h6 class="modal-title">رفض مستند الموافقة</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <textarea name="review_notes" class="form-control" rows="3" required placeholder="وضّح سبب الرفض (مستند غير واضح، توقيع غير مطابق، إلخ)..."></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                                    <button type="submit" class="btn btn-danger">تأكيد الرفض</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                @endcan

                @empty
                <div class="text-center py-5 text-muted">
                    <div style="font-size:2.5rem;">📄</div>
                    لم يتم رفع أي مستند موافقة بعد
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection