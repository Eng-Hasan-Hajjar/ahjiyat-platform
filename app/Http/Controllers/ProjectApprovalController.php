<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectApprovalController extends Controller
{
    /**
     * عرض صفحة الموافقات الخاصة بالمشروع (للمالك: رفع، للمشرف: مراجعة)
     */
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        $approvals = $project->approvals()->with(['submittedBy', 'reviewedBy'])->latest()->get();

        return view('approvals.index', compact('project', 'approvals'));
    }

    /**
     * رفع مستند الموافقة الخطية الموقّعة (PDF ممسوح ضوئياً)
     * يُسمح فقط لصاحب المشروع نفسه
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'document'          => 'required|file|mimes:pdf|max:10240', // 10MB
            'owner_national_id' => 'nullable|string|max:50',
        ]);

        $path = $request->file('document')->store('project-approvals/' . $project->id, 'public');

        ProjectApproval::create([
            'project_id'        => $project->id,
            'submitted_by'      => auth()->id(),
            'document_path'     => $path,
            'owner_national_id' => $validated['owner_national_id'] ?? null,
            'status'             => 'pending_review',
        ]);

        return redirect()
            ->route('approvals.index', $project)
            ->with('success', 'تم رفع مستند الموافقة بنجاح، وهو الآن بانتظار مراجعة المشرف.');
    }

    /**
     * اعتماد الموافقة الخطية من قبل المشرف
     * عند الاعتماد: يُحدَّث has_approved_consent على المشروع تلقائياً
     */
    public function approve(Request $request, ProjectApproval $approval)
    {
        $this->authorize('reviewApprovals', $approval->project);

        $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $approval->update([
            'status'        => 'approved',
            'reviewed_by'   => auth()->id(),
            'reviewed_at'   => now(),
            'review_notes'  => $request->review_notes,
        ]);

        // تحديث القيد المنطقي: المشروع أصبح يملك موافقة معتمدة
        $approval->project->update(['has_approved_consent' => true]);

        return back()->with('success', 'تم اعتماد المستند رسمياً، ويمكن الآن بدء تنفيذ المشروع.');
    }

    /**
     * رفض الموافقة الخطية (مستند غير واضح، توقيع غير مطابق، إلخ)
     */
    public function reject(Request $request, ProjectApproval $approval)
    {
        $this->authorize('reviewApprovals', $approval->project);

        $request->validate([
            'review_notes' => 'required|string|max:1000',
        ]);

        $approval->update([
            'status'        => 'rejected',
            'reviewed_by'   => auth()->id(),
            'reviewed_at'   => now(),
            'review_notes'  => $request->review_notes,
        ]);

        return back()->with('success', 'تم رفض المستند مع توضيح السبب لصاحب المشروع.');
    }

    /**
     * حذف مستند (فقط إذا كان قيد المراجعة ولم يُعتمد/يُرفض بعد)
     */
    public function destroy(ProjectApproval $approval)
    {
        $this->authorize('update', $approval->project);

        if ($approval->status !== 'pending_review') {
            return back()->with('error', 'لا يمكن حذف مستند تمت مراجعته بالفعل.');
        }

        Storage::disk('public')->delete($approval->document_path);
        $approval->delete();

        return back()->with('success', 'تم حذف المستند.');
    }
}