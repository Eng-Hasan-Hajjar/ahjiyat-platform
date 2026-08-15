<?php

namespace App\Http\Controllers;

use App\Models\{User, Project, Task, Donation, Announcement, Rating, VolunteerApplication};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!auth()->user()?->isAdmin()) abort(403);
            return $next($request);
        });
    }

    // ─── Users Management ────────────────────────────────────
    public function users(Request $request)
    {
        $query = User::query();

        if ($request->role)   $query->where('role', $request->role);
        if ($request->search) $query->where('name', 'like', '%'.$request->search.'%')
                                    ->orWhere('email', 'like', '%'.$request->search.'%');

        $users = $query->latest()->paginate(20)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function showUser(User $user)
    {
        $user->load(['volunteerProfile', 'ownedProjects', 'assignedProjects', 'ratingsReceived']);
        return view('admin.users.show', compact('user'));
    }

    public function toggleUser(User $user)
    {
        $user->update(['is_active' => !$user->is_active]);
        $status = $user->is_active ? 'تفعيل' : 'تعطيل';
        return back()->with('success', "تم {$status} الحساب بنجاح.");
    }

  public function deleteUser(User $user)
    {
        DB::transaction(function () use ($user) {
            if ($user->isVolunteer()) {
                $this->cleanupVolunteerBeforeDelete($user);
            }

            $user->delete();
        });

        return redirect()->route('admin.users')->with('success', 'تم حذف المستخدم.');
    }

    /**
     * تنظيف الارتباطات النشطة لمتطوع قبل حذف حسابه:
     * 1) إلغاء إسناد أي مهام غير مكتملة له (تصير متاحة لإعادة الإسناد لمتطوع آخر)
     * 2) رفض أي طلبات تطوع معلّقة تلقائياً
     * 3) إنقاص عداد volunteers_assigned وتحويل حالة انضمامه لكل مشروع كان مقبولاً فيه إلى "removed"
     *
     * ملاحظة: لا نلمس ملفه الشخصي (VolunteerProfile) ولا نقاطه أو ساعاته أو مشاريعه المكتملة —
     * هذا سجل تاريخي حقيقي ويبقى محفوظاً كما هو حتى بعد حذف الحساب.
     */
    private function cleanupVolunteerBeforeDelete(User $user): void
    {
        // 1) إلغاء إسناد المهام غير المكتملة، لتصير متاحة لإعادة الإسناد
        Task::where('assigned_to', $user->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update([
                'assigned_to' => null,
                'status'      => 'pending',
            ]);

        // 2) رفض طلبات التطوع المعلّقة تلقائياً
        VolunteerApplication::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update([
                'status'           => 'rejected',
                'rejection_reason' => 'تم إلغاء الطلب تلقائياً بسبب حذف حساب المتطوع.',
            ]);

        // 3) تحديث كل مشروع كان منضماً له فعلياً (حالة accepted فقط)
        $acceptedProjects = $user->assignedProjects()->wherePivot('status', 'accepted')->get();

        foreach ($acceptedProjects as $project) {
            if ($project->volunteers_assigned > 0) {
                $project->decrement('volunteers_assigned');
            }

            $project->volunteers()->updateExistingPivot($user->id, ['status' => 'removed']);
        }
    }

    // ─── Projects Management ─────────────────────────────────
    public function projects(Request $request)
    {
        $query = Project::with('owner');

        if ($request->status) $query->where('status', $request->status);
        if ($request->city)   $query->where('city', $request->city);

        $projects = $query->latest()->paginate(20)->withQueryString();
        $cities   = Project::distinct()->pluck('city');

        return view('admin.projects.index', compact('projects', 'cities'));
    }

    // ─── Announcements ───────────────────────────────────────
    public function announcements()
    {
        $announcements = Announcement::with('author')->latest()->paginate(15);
        return view('admin.announcements.index', compact('announcements'));
    }

    public function createAnnouncement()
    {
        return view('admin.announcements.create');
    }

    public function storeAnnouncement(Request $request)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'content'      => 'required|string|min:10',
            'target'       => 'required|in:all,volunteers,owners',
            'is_published' => 'nullable|boolean',
        ]);

        $data['user_id']      = auth()->id();
        $data['is_published'] = $request->boolean('is_published', true);

        Announcement::create($data);

        return redirect()->route('admin.announcements')->with('success', 'تم نشر الإعلان بنجاح.');
    }

    public function destroyAnnouncement(Announcement $announcement)
    {
        $announcement->delete();
        return back()->with('success', 'تم حذف الإعلان.');
    }

    // ─── Create User ─────────────────────────────────────
    public function createUser()
    {
        return view('admin.users.create');
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|in:volunteer,project_owner,admin,committee',
            'city'     => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|min:8|confirmed',
            'is_active'=> 'nullable|boolean',
        ]);

        $data['password']  = Hash::make($data['password']);
        $data['is_active'] = $request->boolean('is_active', true);

        $user = \App\Models\User::create($data);

        if ($user->isVolunteer()) {
            \App\Models\VolunteerProfile::create(['user_id' => $user->id]);
        }

        return redirect()->route('admin.users')->with('success', 'تم إنشاء الحساب بنجاح.');
    }

    // ─── Reports ─────────────────────────────────────────────
    public function reports()
    {
        $stats = [
            'projects_by_status' => Project::selectRaw('status, COUNT(*) as count')
                                           ->groupBy('status')->pluck('count', 'status'),
            'projects_by_city'   => Project::selectRaw('city, COUNT(*) as count')
                                           ->groupBy('city')->orderByDesc('count')->take(10)->pluck('count', 'city'),
            'projects_by_type'   => Project::selectRaw('type, COUNT(*) as count')
                                           ->groupBy('type')->pluck('count', 'type'),
            'volunteers_by_city' => User::where('role', 'volunteer')
                                        ->selectRaw('city, COUNT(*) as count')
                                        ->groupBy('city')->orderByDesc('count')->take(10)->pluck('count', 'city'),
            'monthly_projects'   => Project::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
                                           ->whereYear('created_at', now()->year)
                                           ->groupBy('month')->pluck('count', 'month'),
            'total_donations'    => Donation::sum('amount'),
            'top_volunteers'     => User::where('role','volunteer')
                                        ->with('volunteerProfile')
                                        ->whereHas('volunteerProfile', fn($q) => $q->orderByDesc('points'))
                                        ->take(5)->get()
                                        ->sortByDesc(fn($u) => $u->volunteerProfile->points ?? 0),
        ];

        return view('admin.reports', compact('stats'));
    }

    // ─── Donations ───────────────────────────────────────────
    public function donations()
    {
        $donations = Donation::with(['project', 'donor'])->latest()->paginate(20);
        $total     = Donation::sum('amount');

        return view('admin.donations', compact('donations', 'total'));
    }
}