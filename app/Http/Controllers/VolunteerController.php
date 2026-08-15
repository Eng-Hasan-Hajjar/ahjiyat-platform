<?php

namespace App\Http\Controllers;

use App\Models\{User, VolunteerProfile, VolunteerApplication, Project};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VolunteerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'volunteer')
                     ->where('is_active', true)
                     ->with('volunteerProfile');

        if ($request->skill) {
            $query->whereHas('volunteerProfile', function ($q) use ($request) {
                $q->whereJsonContains('skills', $request->skill);
            });
        }

        if ($request->city) {
            $query->where('city', $request->city);
        }

        if ($request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $volunteers = $query->paginate(15)->withQueryString();
        $skills     = \App\Models\VolunteerProfile::allSkills();
        $cities     = User::where('role', 'volunteer')->distinct()->pluck('city')->filter();

        return view('volunteers.index', compact('volunteers', 'skills', 'cities'));
    }

    public function show(User $volunteer)
    {
        abort_unless($volunteer->isVolunteer(), 404);
        $volunteer->load(['volunteerProfile', 'assignedProjects' => function ($q) {
            $q->wherePivot('status', 'completed');
        }]);
        $ratings = $volunteer->ratingsReceived()->with('rater', 'project')->latest()->take(10)->get();
        $avgRating = $volunteer->volunteerProfile?->rating ?? 0;

        return view('volunteers.show', compact('volunteer', 'ratings', 'avgRating'));
    }

    public function profile()
    {
        $user    = Auth::user();
        $profile = $user->volunteerProfile ?? new VolunteerProfile(['user_id' => $user->id]);
        $skills  = VolunteerProfile::allSkills();

        return view('volunteers.profile', compact('user', 'profile', 'skills'));
    }

public function updateProfile(Request $request)
{
    $user = Auth::user();

    $userData = $request->validate([
        'name'    => 'required|string|max:255',
        'phone'   => 'nullable|string|max:20',
        'address' => 'nullable|string',
        'city'    => 'nullable|string|max:100',
        'bio'     => 'nullable|string|max:1000',
    ]);

    $profileData = $request->validate([
        'skills'             => 'nullable|array',
        'other_skill_note'   => 'nullable|string|max:255',
        'hours_per_week'     => 'nullable|integer|min:0|max:168',
        'experience_level'   => 'nullable|in:beginner,intermediate,expert',
        'has_vehicle'        => 'nullable|boolean',
        'travel_distance_km' => 'nullable|integer|min:1|max:500',
        'availability'       => 'nullable|array',
    ]);

    // ✅ معالجة رفع الصورة
    if ($request->hasFile('avatar')) {
        $request->validate(['avatar' => 'image|mimes:jpg,jpeg,png,gif|max:2048']);

        // حذف الصورة القديمة
        if ($user->avatar && file_exists(storage_path('app/public/' . $user->avatar))) {
            unlink(storage_path('app/public/' . $user->avatar));
        }

        // حفظ الصورة الجديدة
        $path = $request->file('avatar')->store('avatars', 'public');
        $userData['avatar'] = $path;
    }

    $profileData['has_vehicle'] = $request->boolean('has_vehicle');

    $user->update($userData);

    $user->volunteerProfile()->updateOrCreate(
        ['user_id' => $user->id],
        $profileData
    );

    return redirect()->route('volunteer.profile')
                     ->with('success', 'تم تحديث ملفك الشخصي بنجاح. ✅');
}

    // ─── Apply to Project ────────────────────────────────────
    public function apply(Request $request, Project $project)
    {
        abort_unless(Auth::user()->isVolunteer(), 403);

        $existing = VolunteerApplication::where('project_id', $project->id)
                        ->where('user_id', Auth::id())->first();

        if ($existing) {
            return back()->with('error', 'لقد تقدمت بالفعل لهذا المشروع.');
        }

        $data = $request->validate([
            'message'                => 'nullable|string|max:500',
            'offered_skills'         => 'nullable|array',
            'available_hours_per_week' => 'required|integer|min:1|max:168',
        ]);

        $data['project_id'] = $project->id;
        $data['user_id']    = Auth::id();

        VolunteerApplication::create($data);

        return back()->with('success', 'تم إرسال طلب التطوع بنجاح! سيتم مراجعته من قبل صاحب المشروع.');
    }

    // ─── My Applications ─────────────────────────────────────
    public function myApplications()
    {
        $applications = Auth::user()->applications()
                            ->with('project.owner')
                            ->latest()
                            ->paginate(10);

        return view('volunteers.my-applications', compact('applications'));
    }

    // ─── Leaderboard ─────────────────────────────────────────
    // ─── Leaderboard ─────────────────────────────────────────
    public function leaderboard()
    {
        $ranked = User::where('role', 'volunteer')
                       ->with('volunteerProfile')
                       ->whereHas('volunteerProfile', fn($q) => $q->where('points', '>', 0))
                       ->get()
                       ->sortByDesc(fn($u) => $u->volunteerProfile->points ?? 0)
                       ->values();

        $volunteers  = $ranked->take(20);
        $totalRanked = $ranked->count();

        // ترتيب المستخدم الحالي بين كل المتطوعين، حتى لو مش ضمن أفضل 20
        $myRank = null;
        if (Auth::check() && Auth::user()->isVolunteer()) {
            $index  = $ranked->search(fn($u) => $u->id === Auth::id());
            $myRank = $index === false ? null : $index + 1;
        }

        $tiers = VolunteerProfile::badgeTiers();

        return view('volunteers.leaderboard', compact('volunteers', 'totalRanked', 'myRank', 'tiers'));
    }
}