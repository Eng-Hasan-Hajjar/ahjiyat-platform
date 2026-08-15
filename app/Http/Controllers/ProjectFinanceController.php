<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFinance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectFinanceController extends Controller
{
    /**
     * عرض السجل المالي الكامل للمشروع
     */
    public function index(Project $project)
    {
        $this->authorize('view', $project); // استخدم ProjectPolicy الموجودة لديك

        $finances = $project->finances()
            ->with(['recordedBy', 'verifiedBy'])
            ->latest('entry_date')
            ->paginate(15);

        $summary = [
            'total_donations' => $project->finances()->donations()->verified()->sum('amount'),
            'total_expenses'  => $project->finances()->expenses()->verified()->sum('amount'),
            'pending_count'   => $project->finances()->where('status', 'pending_review')->count(),
        ];
        $summary['balance'] = $summary['total_donations'] - $summary['total_expenses'];

        return view('finances.index', compact('project', 'finances', 'summary'));
    }

    /**
     * نموذج إضافة حركة مالية جديدة (تبرع أو مصروف)
     */
    public function create(Project $project, Request $request)
    {
        $this->authorize('update', $project);
        $entryType = $request->query('type', 'expense'); // donation | expense
        return view('finances.create', compact('project', 'entryType'));
    }

    /**
     * حفظ حركة مالية جديدة
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'entry_type'      => 'required|in:donation,expense',
            'category'        => 'required|string|max:50',
            'amount'          => 'required|numeric|min:0.01',
            'currency'        => 'nullable|string|max:10',
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:2000',
            'donor_name'      => 'nullable|string|max:255',
            'donor_anonymous' => 'nullable|boolean',
            'entry_date'      => 'required|date|before_or_equal:today',
            'attachment'      => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('finance-attachments', 'public');
        }

        ProjectFinance::create([
            'project_id'      => $project->id,
            'recorded_by'     => auth()->id(),
            'entry_type'      => $validated['entry_type'],
            'category'        => $validated['category'],
            'amount'          => $validated['amount'],
            'currency'        => $validated['currency'] ?? 'SYP',
            'title'           => $validated['title'],
            'description'     => $validated['description'] ?? null,
            'donor_name'      => $validated['donor_name'] ?? null,
            'donor_anonymous' => $request->boolean('donor_anonymous'),
            'attachment_path' => $attachmentPath,
            'entry_date'      => $validated['entry_date'],
            'status'          => 'pending_review',
        ]);

        return redirect()
            ->route('finances.index', $project)
            ->with('success', 'تم تسجيل الحركة المالية بنجاح، وهي الآن بانتظار مراجعة المشرف.');
    }

    /**
     * اعتماد حركة مالية (للمشرف فقط)
     */
    public function verify(ProjectFinance $finance)
    {
        $this->authorize('verifyFinance', $finance->project); // أضف هذا الـ policy method

        $finance->update([
            'status'      => 'verified',
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ]);

        return back()->with('success', 'تم اعتماد الحركة المالية.');
    }

    /**
     * رفض حركة مالية (للمشرف فقط)
     */
    public function reject(Request $request, ProjectFinance $finance)
    {
        $this->authorize('verifyFinance', $finance->project);

        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $finance->update([
            'status'            => 'rejected',
            'verified_by'       => auth()->id(),
            'verified_at'       => now(),
            'rejection_reason'  => $request->rejection_reason,
        ]);

        return back()->with('success', 'تم رفض الحركة المالية مع تسجيل السبب.');
    }

    /**
     * حذف حركة مالية (فقط إذا كانت لا تزال pending_review ولم تُعتمد بعد)
     */
    public function destroy(ProjectFinance $finance)
    {
        $this->authorize('update', $finance->project);

        if ($finance->status === 'verified') {
            return back()->with('error', 'لا يمكن حذف حركة مالية تم اعتمادها بالفعل، حفاظاً على سجل التدقيق.');
        }

        if ($finance->attachment_path) {
            Storage::disk('public')->delete($finance->attachment_path);
        }

        $finance->delete();

        return back()->with('success', 'تم حذف الحركة المالية.');
    }
}