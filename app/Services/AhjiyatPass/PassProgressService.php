<?php

namespace App\Services\AhjiyatPass;

use App\Models\AhjiyatPass;
use App\Models\User;
use App\Models\UserPassProgress;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * E40 - تقدّم اللاعب في المسار: إنشاء كسول لصف واحد (unique(user, pass)) وقراءته. لا إنشاء جماعي عند تفعيل المسار.
 * الشريحة الأولية تُحسب من عتبات المسار (الشريحة 1 عادةً 0 نقطة فتُبلَغ عند الانضمام).
 */
class PassProgressService
{
    public function __construct(protected PassDefinitionCache $cache) {}

    public function find(User $user, AhjiyatPass $pass): ?UserPassProgress
    {
        return UserPassProgress::query()->where('user_id', $user->getKey())->where('ahjiyat_pass_id', $pass->getKey())->first();
    }

    /** @throws PassException مجمَّد أو مسار غير منشور */
    public function ensure(User $user, AhjiyatPass $pass): UserPassProgress
    {
        if ($user->is_frozen) {
            throw new PassException('frozen', 'حسابك مقيَّد؛ لا يمكن المشاركة في المسار.');
        }

        if ($pass->locked_at === null) {
            throw new PassException('not_published', 'هذا المسار غير متاح.');
        }

        $existing = $this->find($user, $pass);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $progress = new UserPassProgress;
            $progress->forceFill([
                'user_id' => $user->getKey(),
                'ahjiyat_pass_id' => $pass->getKey(),
                'pass_points' => 0,
                'highest_tier' => $this->cache->track($pass)->tierAt(0),
                'joined_at' => now(),
            ])->save();

            return $progress;
        } catch (UniqueConstraintViolationException) {
            // سباق: إنشاء متزامن بنفس المفتاح ⇒ نعيد الصف الفائز.
            return $this->find($user, $pass) ?? throw new PassException('busy', 'تعذّر إنشاء تقدّمك، أعد المحاولة.');
        }
    }
}
