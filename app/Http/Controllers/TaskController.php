<?php

namespace App\Http\Controllers;

use App\Models\{Task, Project};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        $tasks = $project->tasks()->with('assignee', 'creator')->get()->groupBy('status');
        $volunteers = $project->volunteers()->get();

        return view('tasks.index', compact('project', 'tasks', 'volunteers'));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'required|in:low,medium,high',
            'required_skill' => 'nullable|string',
            'estimated_hours' => 'required|integer|min:1',
            'due_date' => 'nullable|date|after:today',
            'notes' => 'nullable|string',
        ]);

        $data['project_id'] = $project->id;
        $data['created_by'] = Auth::id();

        Task::create($data);

        return back()->with('success', 'تم إضافة المهمة بنجاح.');
    }

    public function update(Request $request, Task $task)
    {
        $this->authorize('update', $task->project);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'status' => 'required|in:pending,in_progress,completed,cancelled',
            'priority' => 'required|in:low,medium,high',
            'required_skill' => 'nullable|string',
            'estimated_hours' => 'required|integer|min:1',
            'actual_hours' => 'nullable|integer|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($data['status'] === 'completed' && $task->status !== 'completed') {
            $data['completed_at'] = now();
            $this->awardPointsForCompletedTask($task, $data['actual_hours'] ?? null);
        }

        $task->update($data);

        // Update project progress
        $this->updateProjectProgress($task->project);

        return back()->with('success', 'تم تحديث المهمة.');
    }

    public function destroy(Task $task)
    {
        $this->authorize('update', $task->project);
        $task->delete();
        return back()->with('success', 'تم حذف المهمة.');
    }

    public function updateStatus(Request $request, Task $task)
    {
        $request->validate(['status' => 'required|in:pending,in_progress,completed,cancelled']);

        // Allow assignee to update own task status
        $user = Auth::user();
        if ($task->assigned_to !== $user->id && !$user->isAdmin()) {
            $this->authorize('update', $task->project);
        }

        $updateData = ['status' => $request->status];

        if ($request->status === 'completed' && $task->status !== 'completed') {
            $updateData['completed_at'] = now();
            $this->awardPointsForCompletedTask($task);
        }

        $task->update($updateData);
        $this->updateProjectProgress($task->project);

        return back()->with('success', 'تم تحديث حالة المهمة.');
    }

    /**
     * منح النقاط وساعات التطوع للمتطوع صاحب المهمة عند إنهائها،
     * موحّدة بمكان واحد بغض النظر عن طريقة إنهاء المهمة بالواجهة.
     */
    private function awardPointsForCompletedTask(Task $task, ?int $actualHours = null): void
    {
        if ($task->assignee && $task->assignee->isVolunteer()) {
            $profile = $task->assignee->volunteerProfile;
            if ($profile) {
                $hours = $actualHours ?? $task->estimated_hours;
                $profile->increment('points', $hours * 5);
                $profile->increment('total_hours_contributed', $hours);
            }
        }
    }

    private function updateProjectProgress(Project $project): void
    {
        $total = $project->tasks()->count();
        if ($total === 0)
            return;

        $completed = $project->tasks()->where('status', 'completed')->count();
        $progress = round(($completed / $total) * 100);
        $project->update(['progress_percentage' => $progress]);
    }
}