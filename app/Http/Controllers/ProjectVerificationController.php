<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectVerificationController extends Controller
{
    /**
     * عرض قائمة المشاريع المعلقة للمراجعة من قبل اللجنة
     */
    public function index()
    {
        $projects = Project::where('status', 'pending')
            ->with(['owner', 'verification'])
            ->latest()
            ->paginate(15);

        return view('committee.index', compact('projects'));
    }

    /**
     * عرض تفاصيل المشروع للمراجعة (الضرر، النسب، القصة)
     */
    public function show(Project $project)
    {
        $project->load(['owner', 'tasks', 'verification', 'beforeMedia', 'latestApproval']);
        return view('committee.show', compact('project'));
    }

    /**
     * معالجة قرار اللجنة (موافقة أو رفض)
     */
    public function review(Request $request, Project $project)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $project->verification()->updateOrCreate(
            ['project_id' => $project->id],
            [
                'reviewer_id' => Auth::id(),
                'status'      => $request->status,
                'notes'       => $request->notes,
            ]
        );

        $project->update([
            'status'           => $request->status === 'approved' ? 'approved' : 'rejected',
            'rejection_reason' => $request->status === 'rejected' ? $request->notes : $project->rejection_reason,
        ]);

        if (method_exists($project->owner, 'notify')) {
            $project->owner->notify(new \App\Notifications\ProjectStatusChanged($project, $request->status));
        }

        return redirect()->route('committee.index')
            ->with('success', $request->status === 'approved'
                ? 'تمت الموافقة على المشروع بنجاح.'
                : 'تم رفض المشروع وإشعار صاحب المشروع.');
    }
}