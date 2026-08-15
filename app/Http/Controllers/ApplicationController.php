<?php
namespace App\Http\Controllers;

use App\Models\{VolunteerApplication, Project};
use App\Notifications\ApplicationStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApplicationController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('update', $project);

        $applications = $project->applications()
                                ->with(['volunteer.volunteerProfile'])
                                ->latest()
                                ->paginate(15);

        return view('projects.applications', compact('project', 'applications'));
    }

    public function accept(VolunteerApplication $application)
    {
        $this->authorize('update', $application->project);

        $project = $application->project;

        // Add volunteer to project
        $project->volunteers()->syncWithoutDetaching([
            $application->user_id => [
                'status'    => 'accepted',
                'joined_at' => now()->toDateString(),
            ]
        ]);

        $project->increment('volunteers_assigned');
        $application->update(['status' => 'accepted']);
        $application->volunteer->notify(new ApplicationStatusChanged($application, 'accepted'));

        return back()->with('success', 'تم قبول المتطوع وإضافته للمشروع.');
    }

    public function reject(Request $request, VolunteerApplication $application)
    {
        $this->authorize('update', $application->project);

        $request->validate(['rejection_reason' => 'nullable|string|max:500']);

        $application->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        $application->volunteer->notify(new ApplicationStatusChanged($application, 'rejected'));

        return back()->with('success', 'تم رفض الطلب.');
    }
}