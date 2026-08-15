<?php

namespace App\Http\Controllers;

use App\Models\{Project, User, Task, Announcement, Donation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        if ($user->isVolunteer()) {
            return $this->volunteerDashboard($user);
        }

        if ($user->role === 'committee') {
            return $this->committeeDashboard($user);
        }

        return $this->ownerDashboard($user);
    }

    private function committeeDashboard(User $user)
    {
        $stats = [
            'pending_review' => Project::where('status', 'pending')->count(),
            'approved_by_me' => \App\Models\ProjectVerification::where('reviewer_id', $user->id)->where('status', 'approved')->count(),
            'rejected_by_me' => \App\Models\ProjectVerification::where('reviewer_id', $user->id)->where('status', 'rejected')->count(),
            'total_reviewed' => \App\Models\ProjectVerification::where('reviewer_id', $user->id)->count(),
        ];

        $pendingProjects = Project::where('status', 'pending')
            ->with('owner')
            ->latest()
            ->take(8)
            ->get();

        return view('dashboard.committee', compact('user', 'stats', 'pendingProjects'));
    }


/*
    private function adminDashboard()
    {
        $stats = [
            'total_users' => User::count(),
            'total_volunteers' => User::where('role', 'volunteer')->count(),
            'total_owners' => User::where('role', 'project_owner')->count(),
            'total_projects' => Project::count(),
            'pending_projects' => Project::pending()->count(),
            'active_projects' => Project::inProgress()->count(),
            'completed_projects' => Project::completed()->count(),
            'total_donations' => Donation::sum('amount'),
        ];

        $recentProjects = Project::with('owner')->latest()->take(5)->get();
        $recentUsers = User::latest()->take(5)->get();
        $announcements = Announcement::where('is_published', true)->latest()->take(3)->get();
        $projectsByStatus = Project::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $projectsByCity = Project::selectRaw('city, COUNT(*) as count')->groupBy('city')->orderByDesc('count')->take(5)->pluck('count', 'city');

        return view('dashboard.admin', compact(
            'stats',
            'recentProjects',
            'recentUsers',
            'announcements',
            'projectsByStatus',
            'projectsByCity'
        ));
    }
*/
private function adminDashboard()
    {
        $user = Auth::user();

        $stats = [
            'total_users'        => User::count(),
            'total_volunteers'   => User::where('role', 'volunteer')->count(),
            'total_owners'       => User::where('role', 'project_owner')->count(),
            'total_committee'    => User::where('role', 'committee')->count(),
            'total_projects'     => Project::count(),
            'pending_projects'   => Project::pending()->count(),
            'active_projects'    => Project::inProgress()->count(),
            'completed_projects' => Project::completed()->count(),
            'total_donations'    => Donation::sum('amount'),
        ];

        // ─── بنود بانتظار إجراء (من ميزات اللجنة/المتابعة المالية) ───
        $actionItems = [
            'pending_verifications' => \App\Models\ProjectVerification::where('status', 'pending')->count(),
            'pending_finance'       => \App\Models\ProjectFinance::where('status', 'pending_review')->count(),
            'pending_consents'      => \App\Models\ProjectApproval::where('status', 'pending_review')->count(),
        ];

        // ─── ملخص مالي موثّق ومعتمد ───
        $financeSummary = [
            'verified_donations' => (float) \App\Models\ProjectFinance::where('entry_type', 'donation')->where('status', 'verified')->sum('amount'),
            'verified_expenses'  => (float) \App\Models\ProjectFinance::where('entry_type', 'expense')->where('status', 'verified')->sum('amount'),
        ];

        $recentProjects = Project::with('owner')->latest()->take(5)->get();
        $recentUsers = User::latest()->take(5)->get();
        $announcements = Announcement::where('is_published', true)->latest()->take(3)->get();
        $projectsByStatus = Project::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $projectsByCity = Project::selectRaw('city, COUNT(*) as count')->groupBy('city')->orderByDesc('count')->take(5)->pluck('count', 'city');

        return view('dashboard.admin', compact(
            'user',
            'stats',
            'actionItems',
            'financeSummary',
            'recentProjects',
            'recentUsers',
            'announcements',
            'projectsByStatus',
            'projectsByCity'
        ));
    }
    private function volunteerDashboard(User $user)
    {
        $profile = $user->volunteerProfile;
        $myProjects = $user->assignedProjects()->with('tasks')->get();
        $myTasks = Task::where('assigned_to', $user->id)->where('status', '!=', 'completed')->get();
        $availableProjects = Project::approved()
            ->whereColumn('volunteers_needed', '>', 'volunteers_assigned')
            ->latest()->take(6)->get();
        $announcements = Announcement::where('is_published', true)
            ->whereIn('target', ['all', 'volunteers'])->latest()->take(3)->get();

        $completedCount = $user->assignedProjects()->wherePivot('status', 'completed')->count();
        $totalHours = $profile?->total_hours_contributed ?? 0;
        $points = $profile?->points ?? 0;

        return view('dashboard.volunteer', compact(
            'user',
            'profile',
            'myProjects',
            'myTasks',
            'availableProjects',
            'announcements',
            'completedCount',
            'totalHours',
            'points'
        ));
    }

    private function ownerDashboard(User $user)
    {
        $projects = $user->ownedProjects()->with(['tasks', 'volunteers'])->latest()->get();
        $stats = [
            'total_projects' => $projects->count(),
            'active_projects' => $projects->where('status', 'in_progress')->count(),
            'completed_projects' => $projects->where('status', 'completed')->count(),
            'pending_projects' => $projects->where('status', 'pending')->count(),
        ];
        $recentApplications = \App\Models\VolunteerApplication::whereIn('project_id', $projects->pluck('id'))
            ->with(['volunteer', 'project'])->latest()->take(5)->get();
        $announcements = Announcement::where('is_published', true)
            ->whereIn('target', ['all', 'owners'])->latest()->take(3)->get();

        return view('dashboard.owner', compact(
            'user',
            'projects',
            'stats',
            'recentApplications',
            'announcements'
        ));
    }
}