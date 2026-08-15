<?php

namespace App\Http\Controllers;

use App\Models\{Project, User, VolunteerProfile, VolunteerApplication};
use App\Notifications\ProjectStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::with('owner')
            ->whereIn('status', ['approved', 'in_progress', 'completed']);

        if ($request->city)
            $query->where('city', $request->city);
        if ($request->type)
            $query->where('type', $request->type);
        if ($request->priority)
            $query->where('priority', $request->priority);
        if ($request->status)
            $query->where('status', $request->status);
        if ($request->search)
            $query->where('title', 'like', '%' . $request->search . '%');

        $sortBy = $request->sort ?? 'latest';
        match ($sortBy) {
            'priority' => $query->orderByRaw("FIELD(priority,'critical','high','medium','low')"),
            'damage' => $query->orderByDesc('damage_percentage'),
            'progress' => $query->orderByDesc('progress_percentage'),
            default => $query->latest(),
        };

        $projects = $query->paginate(12)->withQueryString();
        $cities = Project::distinct()->pluck('city');

        return view('projects.index', compact('projects', 'cities'));
    }

    public function show(Project $project)
    {
        $project->load(['owner', 'volunteers.volunteerProfile', 'tasks', 'updates.author', 'donations.donor', 'ratings.rater']);
        $canApply = Auth::check() && Auth::user()->isVolunteer()
            && !$project->volunteers()->where('user_id', Auth::id())->exists()
            && $project->status === 'approved';

        $userApplication = Auth::check()
            ? VolunteerApplication::where('project_id', $project->id)->where('user_id', Auth::id())->first()
            : null;

        return view('projects.show', compact('project', 'canApply', 'userApplication'));
    }
    // في حال نريد جعل السماح للمتطوع في االتطوع في مشروع قيد التنفي>
    /*


public function show(Project $project)
{
    $project->load(['owner', 'volunteers.volunteerProfile', 'tasks', 'updates.author', 'donations.donor', 'ratings.rater']);

    // ✅ السماح بالتطوع في approved و in_progress معاً
    $canApply = Auth::check()
        && Auth::user()->isVolunteer()
        && !$project->volunteers()->where('user_id', Auth::id())->exists()
        && in_array($project->status, ['approved', 'in_progress'])  // ← هنا التعديل
        && $project->volunteers_assigned < $project->volunteers_needed;

    $userApplication = Auth::check()
        ? VolunteerApplication::where('project_id', $project->id)
                              ->where('user_id', Auth::id())->first()
        : null;

    return view('projects.show', compact('project', 'canApply', 'userApplication'));
}

    */
    public function create()
    {
        $this->authorize('create', Project::class);
        return view('projects.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:50',
            'type' => 'required|in:shop,workshop,clinic,bakery,restaurant,school,mosque,pharmacy,other',
            'priority' => 'required|in:low,medium,high,critical',
            'damage_percentage' => 'required|integer|min:0|max:100',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'required_skills' => 'nullable|array',
            'volunteers_needed' => 'required|integer|min:1|max:100',
            'estimated_days' => 'required|integer|min:1',
            'estimated_cost' => 'nullable|numeric|min:0',
            'funding_type' => 'nullable|in:self_funded,individual_donations,international_org,government,mixed',
            'funding_organization' => 'nullable|string|max:255',
            'funding_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'before_images.*' => 'nullable|image|max:5120',
        ]);

        $data['owner_id'] = Auth::id();
        $data['status'] = 'pending';

        // Handle image uploads
        if ($request->hasFile('before_images')) {
            $paths = [];
            foreach ($request->file('before_images') as $img) {
                $paths[] = $img->store('projects/before', 'public');
            }
            $data['before_images'] = json_encode($paths);
        }

        $project = Project::create($data);

        return redirect()->route('projects.show', $project)
            ->with('success', 'تم إضافة مشروعك بنجاح! سيتم مراجعته من قبل الإدارة.');
    }

    public function edit(Project $project)
    {
        $this->authorize('update', $project);
        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|min:50',
            'type' => 'required|in:shop,workshop,clinic,bakery,restaurant,school,mosque,pharmacy,other',
            'priority' => 'required|in:low,medium,high,critical',
            'damage_percentage' => 'required|integer|min:0|max:100',
            'address' => 'required|string',
            'city' => 'required|string|max:100',
            'required_skills' => 'nullable|array',
            'volunteers_needed' => 'required|integer|min:1',
            'estimated_days' => 'required|integer|min:1',
            'estimated_cost' => 'nullable|numeric|min:0',
            'funding_type' => 'nullable|in:self_funded,individual_donations,international_org,government,mixed',
            'funding_organization' => 'nullable|string|max:255',
            'funding_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('success', 'تم تحديث المشروع بنجاح.');
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);
        $project->delete();
        return redirect()->route('dashboard')->with('success', 'تم حذف المشروع.');
    }

    // ─── Admin Actions ───────────────────────────────────────
    public function approve(Project $project)
    {
        $project->update(['status' => 'approved']);
        $project->owner->notify(new ProjectStatusChanged($project, 'approved'));
        return back()->with('success', 'تمت الموافقة على المشروع.');
    }

    public function reject(Request $request, Project $project)
    {
        $request->validate(['rejection_reason' => 'required|string|min:10']);
        $project->update(['status' => 'rejected', 'rejection_reason' => $request->rejection_reason]);
        $project->owner->notify(new ProjectStatusChanged($project, 'rejected'));
        return back()->with('success', 'تم رفض المشروع.');
    }

    public function start(Project $project)
    {
        $project->update(['status' => 'in_progress', 'start_date' => now()]);
        return back()->with('success', 'تم بدء تنفيذ المشروع.');
    }

    public function complete(Project $project)
    {
        $project->update(['status' => 'completed', 'end_date' => now(), 'progress_percentage' => 100]);

        // تحديث عداد "المشاريع المكتملة" لكل متطوع شارك فعلياً بهذا المشروع
        foreach ($project->volunteers()->wherePivot('status', 'accepted')->get() as $vol) {
            $vol->pivot->update(['status' => 'completed']);
            $vol->volunteerProfile?->increment('completed_projects');
        }

        return back()->with('success', 'تم إغلاق المشروع كمكتمل.');
    }

    // ─── Image Upload ────────────────────────────────────────
    public function uploadAfterImages(Request $request, Project $project)
    {
        $request->validate(['after_images.*' => 'required|image|max:5120']);

        $paths = json_decode($project->after_images ?? '[]', true);
        foreach ($request->file('after_images') as $img) {
            $paths[] = $img->store('projects/after', 'public');
        }
        $project->update(['after_images' => json_encode($paths)]);

        return back()->with('success', 'تم رفع الصور بنجاح.');
    }

    // ─── My Projects (owner) ─────────────────────────────────
    public function myProjects()
    {
        $projects = Auth::user()->ownedProjects()->with('tasks')->latest()->paginate(10);
        return view('projects.my-projects', compact('projects'));
    }
}