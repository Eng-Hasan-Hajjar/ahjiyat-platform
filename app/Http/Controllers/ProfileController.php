<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Services\Security\AccountSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile.edit', ['user' => Auth::user()]);
    }

    public function update(UpdateProfileRequest $request, AccountSecurityService $security): RedirectResponse
    {
        $user = Auth::user();
        $user->fill($request->profileData());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $emailChanged = $user->isDirty('email');

        $user->save();

        // E26: تغيير البريد يُبطل جلسات الأجهزة الأخرى ورموز API ويدوّر remember_token (الجلسة الحالية تبقى).
        if ($emailChanged) {
            $security->revokeCredentials($user, $request->session()->getId());
        }

        return back()->with('success', 'تم تحديث بياناتك بنجاح.');
    }
}
