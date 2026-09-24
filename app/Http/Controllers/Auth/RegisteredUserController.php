<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PlatformSettingsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function __construct(protected PlatformSettingsService $settings) {}

    public function create()
    {
        if (! $this->settings->get('access', 'allow_registration')) {
            return redirect()->route('login')->with('error', 'إنشاء حسابات جديدة متوقَّف حاليًا. يمكنك تسجيل الدخول إن كان لديك حساب.');
        }

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->settings->get('access', 'allow_registration'), 403, 'إنشاء حسابات جديدة متوقَّف حاليًا.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $user->assignRole('player');

            Wallet::create([
                'user_id' => $user->id,
                'currency_id' => app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency()->id,
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}