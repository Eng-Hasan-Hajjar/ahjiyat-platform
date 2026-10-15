<?php

namespace App\Http\Requests\Auth;

use App\Support\Security\Fingerprint;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * E26: مسار الدخول الفعلي (كان صنفًا ميتًا غير مستعمل، وPOST /login بلا أي تحديد معدّل -> تخمين/حشو بيانات اعتماد بلا حدّ).
 *  - مفتاحان: (بريد مُجزَّأ + IP) لكل حساب من عنوان، وIP وحده لرشّ كلمات مرور على حسابات متعددة (سقف أعلى كي لا يضرّ شبكة مشتركة كجامعة).
 *  - لا بريد خام بالمفاتيح (بصمة SHA-256)، والنجاح يمسح مفتاح (حساب+IP) فقط؛ سقف الـIP يبقى حتى انتهاء نافذته.
 *  - رسالة واحدة موحّدة لـ"بريد غير موجود" و"كلمة مرور خاطئة"، مع معادلة زمنية: حساب غير موجود يدفع كلفة تجزئة مماثلة (لا تعداد بالزمن).
 *  - قفل الحساب لا يُسجَّل بالسجل إلا مرة واحدة لكل نافذة قفل (لا إغراق للسجلات)، ببصمة البريد لا الخام.
 */
class LoginRequest extends FormRequest
{
    private static ?string $dummyHash = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'password.required' => 'كلمة المرور مطلوبة.',
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->equalizeTiming();
            RateLimiter::hit($this->throttleKey(), $this->decaySeconds());
            RateLimiter::hit($this->ipThrottleKey(), $this->decaySeconds());

            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        $accountLocked = RateLimiter::tooManyAttempts($this->throttleKey(), (int) config('security.login.max_attempts', 5));
        $ipLocked = RateLimiter::tooManyAttempts($this->ipThrottleKey(), (int) config('security.login.ip_max_attempts', 30));

        if (! $accountLocked && ! $ipLocked) {
            return;
        }

        $key = $accountLocked ? $this->throttleKey() : $this->ipThrottleKey();
        $seconds = max(1, RateLimiter::availableIn($key));

        event(new Lockout($this));

        // سطر سجل واحد لكل نافذة قفل (Cache::add ذرّي)، لا سطر لكل محاولة محجوبة.
        if (Cache::add('login-lockout-logged:'.$key, 1, $seconds)) {
            Log::warning('security.login_lockout', [
                'scope' => $accountLocked ? 'account_ip' : 'ip',
                'email_fp' => Fingerprint::email($this->input('email')),
                'ip' => $this->ip(),
                'retry_after' => $seconds,
            ]);
        }

        throw ValidationException::withMessages(['email' => "محاولات دخول كثيرة. حاول مجددًا بعد {$seconds} ثانية."]);
    }

    public function throttleKey(): string
    {
        return 'login:acct:'.Fingerprint::email($this->input('email')).'|'.$this->ip();
    }

    public function ipThrottleKey(): string
    {
        return 'login:ip:'.$this->ip();
    }

    protected function decaySeconds(): int
    {
        return max(1, (int) config('security.login.decay_seconds', 300));
    }

    /**
     * Auth::attempt لا يجزّئ كلمة المرور حين لا يوجد مستخدم بالبريد، فيصير الرد أسرع بكثير => تعداد حسابات بالزمن. ندفع كلفة تجزئة مماثلة للحساب غير الموجود.
     */
    protected function equalizeTiming(): void
    {
        $exists = \App\Models\User::query()->where('email', (string) $this->input('email'))->exists();

        if (! $exists) {
            self::$dummyHash ??= Hash::make('e26-timing-equalizer');
            Hash::check((string) $this->input('password'), self::$dummyHash);
        }
    }
}
