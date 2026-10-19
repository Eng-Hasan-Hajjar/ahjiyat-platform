<?php

namespace App\Services\Referrals;

use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E33 (A) - رموز الإحالة الشخصية.
 *
 *  - يولّدها الخادم فقط من random_int (آمن تشفيريًا) بأبجدية بلا حروف علة ولا محارف ملتبسة، بحروف كبيرة؛ التصادم يعالجه فهرس UNIQUE ثم إعادة محاولة محدودة.
 *  - رمز نشط واحد لكل مستخدم يضمنه UNIQUE(active_for_user_id) (حارس القاعدة الأخير ضد سباق إنشاء رمزين).
 *  - التدوير يعطّل القديم (active_for_user_id = NULL) ويُنشئ جديدًا؛ القديم لا يُسنِد إحالات جديدة بعد الآن، والإحالات القديمة تبقى كما هي.
 *  - البحث بالرمز مخزَّن بالكاش (نتيجة إيجابية 5 دقائق، سلبية دقيقة) ويُفرَّغ عند التدوير؛ يتحقق دائمًا من الصيغة قبل لمس القاعدة.
 */
class ReferralCodeService
{
    public function normalize(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $code = strtoupper(trim($raw));
        $length = (int) config('referrals.code.length');

        if (strlen($code) !== $length) {
            return null;
        }

        $alphabet = (string) config('referrals.code.alphabet');

        return strspn($code, $alphabet) === $length ? $code : null;
    }

    public function activeFor(User $user): ?ReferralCode
    {
        return ReferralCode::query()->where('active_for_user_id', $user->id)->first();
    }

    public function ensureFor(User $user): ReferralCode
    {
        return $this->activeFor($user) ?? $this->createFor($user);
    }

    /** نص رمز المستخدم النشط (مخزَّن 5 دقائق) أو null؛ للقراءة فقط (لا إنشاء) فتُستعمل بأمان في صفحات عامة. */
    public function myCode(User $user): ?string
    {
        $hit = Cache::remember('referrals:mycode:'.$user->id, 300, fn () => $this->activeFor($user)?->code ?? '');

        return $hit === '' ? null : (string) $hit;
    }

    /**
     * يدوّر الرمز: القديم يتعطل فورًا. حدّ يومي صغير يمنع إغراق الجدول. يُسلسَل لكل مستخدم بقفل صف المستخدم.
     */
    public function rotate(User $user): ReferralCode
    {
        $old = null;

        $new = DB::transaction(function () use ($user, &$old) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $recent = ReferralCode::query()->where('user_id', $user->id)->where('rotated_at', '>=', now()->subDay())->count();

            if ($recent >= (int) config('referrals.code.max_rotations_per_day')) {
                throw new ReferralException('rotation_limit', 'بلغت الحد اليومي لتغيير رمز الدعوة. حاول لاحقًا.');
            }

            $old = $this->activeFor($user);

            if ($old !== null) {
                $old->forceFill(['active_for_user_id' => null, 'rotated_at' => now()])->save();
            }

            return $this->createFor($user);
        });

        if ($old !== null) {
            Cache::forget($this->cacheKey($old->code));
        }

        Cache::forget('referrals:mycode:'.$user->id);

        return $new;
    }

    /**
     * يبحث عن رمز **نشط** بصيغة صالحة. يعيد صفًا خفيفًا (id/user_id) لا نموذجًا كاملًا.
     *
     * @return array{id: int, user_id: int, code: string}|null
     */
    public function findActive(mixed $raw): ?array
    {
        $code = $this->normalize($raw);

        if ($code === null) {
            return null;
        }

        $hit = Cache::get($this->cacheKey($code));

        if ($hit === 'none') {
            return null;
        }

        if (is_array($hit)) {
            return $hit;
        }

        $row = ReferralCode::query()->where('code', $code)->whereNotNull('active_for_user_id')->first(['id', 'user_id', 'code']);

        if ($row === null) {
            Cache::put($this->cacheKey($code), 'none', 60);

            return null;
        }

        $value = ['id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'code' => (string) $row->code];
        Cache::put($this->cacheKey($code), $value, 300);

        return $value;
    }

    public function generate(): string
    {
        $alphabet = (string) config('referrals.code.alphabet');
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0, $n = (int) config('referrals.code.length'); $i < $n; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    protected function createFor(User $user): ReferralCode
    {
        $attempts = max(1, (int) config('referrals.code.max_collision_retries'));
        Cache::forget('referrals:mycode:'.$user->id);

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return DB::transaction(fn () => ReferralCode::query()->forceCreate([
                    'user_id' => $user->id,
                    'code' => $this->generate(),
                    'active_for_user_id' => $user->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                // إما تصادم رمز (نعيد بتوليد جديد) وإما سباق أنشأ رمزًا نشطًا لنفس المستخدم (نعيده).
                if (($existing = $this->activeFor($user)) !== null) {
                    return $existing;
                }
            }
        }

        throw new ReferralException('code_generation_failed', 'تعذّر إنشاء رمز الدعوة الآن. حاول لاحقًا.');
    }

    protected function cacheKey(string $code): string
    {
        return 'referrals:code:'.$code;
    }
}
