<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\AuditService;
use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;

class UserController extends Controller
{
    public function index(Request $request)
    {
      //  $query = User::with('roles');

    $query = User::with([
    'roles',
    'sessions'
]);


        // 🔹 البحث
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%");
            });
        }

        // 🔹 فلتر الدور
        if ($request->filled('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        $users = $query->latest()->paginate(12);
        $roles = Role::all();

        return view('admin.users.index', compact('users','roles'));
    }

    public function create()
    {
        $roles = Role::all();
        return view('admin.users.create', compact('roles'));
    }


    public function store(UserStoreRequest $request)
{
    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'email_verified_at' => now(),
    ]);

    $user->roles()->sync($request->roles);

    return redirect()->route('admin.users.index')
        ->with('success','تم إنشاء المستخدم بنجاح');
}
/*
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8|confirmed',
            'roles' => 'required|array|min:1',
        ],[
            'password.confirmed' => 'كلمة المرور غير متطابقة مع التأكيد',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
        ]);

        $user->roles()->sync($request->roles);

        AuditService::log(
            'created',
            'إنشاء مستخدم: '.$user->email,
            'User',
            $user->id
        );

        return redirect()->route('admin.users.index')
            ->with('success','تم إنشاء المستخدم بنجاح');
    }
*/
    public function edit(User $user)
    {
        $roles = Role::all();
        $userRoles = $user->roles->pluck('id')->toArray();

        return view('admin.users.edit', compact('user','roles','userRoles'));
    }

    public function update(UserUpdateRequest $request, User $user)
{
    $user->update([
        'name' => $request->name,
        'email' => $request->email,
    ]);

    if ($request->filled('password')) {
        $user->update([
            'password' => Hash::make($request->password),
        ]);
    }

    $user->roles()->sync($request->roles);

    return redirect()->route('admin.users.index')
        ->with('success','تم تحديث المستخدم');
}


/*
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'roles' => 'required|array|min:1',
            'password' => 'nullable|min:8|confirmed',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        if ($request->filled('password')) {
            $user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        $user->roles()->sync($request->roles);

        AuditService::log(
            'updated',
            'تعديل مستخدم: '.$user->email,
            'User',
            $user->id
        );

        return redirect()->route('admin.users.index')
            ->with('success','تم تحديث المستخدم بنجاح');
    }
*/
    public function destroy(User $user)
    {
        if ($user->hasRole('super_admin')) {
            return back()->withErrors('لا يمكن حذف Super Admin');
        }

        AuditService::log(
            'deleted',
            'حذف مستخدم: '.$user->email,
            'User',
            $user->id
        );

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success','تم حذف المستخدم');
    }






}
