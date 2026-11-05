<?php

namespace App\Services\Cinematic\Media;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * CIN-1 - روابط تشغيل موقَّعة قصيرة العمر ومربوطة بالمستخدم. الرابط وحده ليس صلاحية: المسار يعيد فحص الدخول والنسخة والملكية عند كل طلب (وطلبات Range).
 * لا ادّعاء بحماية مطلقة من النسخ: من يملك حق المشاهدة يستطيع تقنيًا تسجيل الشاشة.
 */
class CinematicMediaLinks
{
    public const SCOPE_SCENE = 'scene';

    public const SCOPE_STORY = 'story';

    public function url(CinematicMediaAsset $asset, string $scope, string $scopeId, User $user, bool $preview = false): string
    {
        return URL::temporarySignedRoute('cinematic.media', now()->addSeconds((int) config('cinematic_stories.media.link_ttl_seconds', 900)), [
            'asset' => $asset->public_id,
            't' => $scope,
            's' => $scopeId,
            'k' => $this->binding($user),
            'p' => $preview ? 1 : 0,
        ]);
    }

    /** بصمة ربط المستخدم بالرابط (HMAC بمفتاح التطبيق): رابط مسرَّب لا يعمل لحساب آخر. */
    public function binding(User $user): string
    {
        return substr(hash_hmac('sha256', 'cinematic-media:'.$user->id, (string) config('app.key')), 0, 24);
    }

    public function bindingMatches(User $user, string $given): bool
    {
        return hash_equals($this->binding($user), $given);
    }
}
