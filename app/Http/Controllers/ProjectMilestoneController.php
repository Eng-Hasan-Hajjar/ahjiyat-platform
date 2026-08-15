<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\Request;

class ProjectMilestoneController extends Controller
{
    /**
     * إنشاء مرحلة جديدة للمشروع (دهان، سباكة، كهرباء، إلخ)
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
        ]);

        $nextOrder = $project->milestones()->max('order_index') + 1;

        ProjectMilestone::create([
            'project_id'   => $project->id,
            'title'        => $validated['title'],
            'description'  => $validated['description'] ?? null,
            'planned_date' => $validated['planned_date'] ?? null,
            'order_index'  => $nextOrder,
            'status'       => 'not_started',
            'created_by'   => auth()->id(),
        ]);

        return back()->with('success', 'تمت إضافة المرحلة الجديدة بنجاح.');
    }

    /**
     * تحديث حالة المرحلة (لم تبدأ / قيد التنفيذ / مكتملة)
     */
    public function updateStatus(Request $request, ProjectMilestone $milestone)
    {
        $this->authorize('update', $milestone->project);

        $validated = $request->validate([
            'status' => 'required|in:not_started,in_progress,completed',
        ]);

        $milestone->status = $validated['status'];

        if ($validated['status'] === 'completed') {
            $milestone->completed_date = now();
        }

        $milestone->save();

        // إعادة احتساب نسبة تقدم المشروع تلقائياً بناءً على المراحل المكتملة
        $this->recalculateProjectProgress($milestone->project);

        return back()->with('success', 'تم تحديث حالة المرحلة.');
    }

    /**
     * تعديل بيانات مرحلة موجودة
     */
    public function update(Request $request, ProjectMilestone $milestone)
    {
        $this->authorize('update', $milestone->project);

        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'planned_date' => 'nullable|date',
        ]);

        $milestone->update($validated);

        return back()->with('success', 'تم تحديث بيانات المرحلة.');
    }

    /**
     * حذف مرحلة (مع تحذير: سيتم فك ارتباط الوسائط المرتبطة بها وليس حذفها)
     */
    public function destroy(ProjectMilestone $milestone)
    {
        $this->authorize('update', $milestone->project);

        $project = $milestone->project;
        $milestone->delete();
        $this->recalculateProjectProgress($project);

        return back()->with('success', 'تم حذف المرحلة.');
    }

    /**
     * إعادة ترتيب المراحل (سحب وإفلات بالواجهة)
     */
    public function reorder(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:project_milestones,id',
        ]);

        foreach ($validated['order'] as $index => $milestoneId) {
            ProjectMilestone::where('id', $milestoneId)
                ->where('project_id', $project->id) // تأمين إضافي: المرحلة تخص هذا المشروع فعلاً
                ->update(['order_index' => $index]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * منطق مساعد: تحديث نسبة تقدم المشروع بناءً على نسبة المراحل المكتملة
     * (يعمل بالتوازي مع آلية التقدم الحالية المبنية على المهام Tasks
     *  دون استبدالها — يأخذ الأعلى بين الاثنين لتفادي التراجع بالنسبة)
     */
    private function recalculateProjectProgress(Project $project): void
    {
        $total = $project->milestones()->count();
        if ($total === 0) {
            return;
        }

        $completed = $project->milestones()->where('status', 'completed')->count();
        $milestoneProgress = (int) round(($completed / $total) * 100);

        if ($milestoneProgress > $project->progress_percentage) {
            $project->update(['progress_percentage' => $milestoneProgress]);
        }
    }
}