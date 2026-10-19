<?php

namespace App\Services\Referrals;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Models\UserReferral;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * E33 (A) - إسناد الحساب الجديد إلى محيل. يحدث **عند إنشاء الحساب فقط** وبقرار الخادم:
 *  - المصدر الوحيد هو «النية» المخزّنة بالخادم (جلسة/كوكي مشفّر)؛ لا حقل من نموذج التسجيل ولا ترويسة تحدد المحيل.
 *  - إحالة واحدة لكل حساب (UNIQUE referred_user_id) والمحيل لا يتغيّر لاحقًا (لا مسار تحديث): استدعاء ثانٍ يعيد الإحالة القائمة كما هي.
 *  - الإحالة الذاتية مرفوضة، ومحيل مجمَّد/غير موثّق/محذوف لا يُسنَد إليه.
 *  - الفشل لا يكسر التسجيل أبدًا (يُسجَّل ويُتجاهل) ولا يكشف شيئًا للزائر (لا رسالة «رمز غير صالح»: لا تسريب لوجود الرموز/الحسابات، وسلوك E26 للتسجيل لم يتغير).
 *  - الحالة تبدأ pending دائمًا: التسجيل وحده ليس إحالة مؤهَّلة.
 */
class ReferralAttributionService
{
    public function __construct(
        protected ReferralCodeService $codes,
        protected ReferralCampaignService $campaigns,
        protected ReferralIntent $intent,
    ) {}

    /** يُستدعى من تسجيل الحساب. لا يرمي أبدًا. */
    public function attributeFromRequest(User $newUser, Request $request): ?UserReferral
    {
        try {
            // الإسناد عبر الطلب لحساب أُنشئ في هذا الطلب نفسه فقط (حساب قديم لا يُسنَد أبدًا).
            if (! $newUser->wasRecentlyCreated) {
                return null;
            }

            $intent = $this->intent->current($request);

            if ($intent === null) {
                return null;
            }

            $referral = $this->attribute($newUser, $intent['code'], $intent['c'], $intent['s']);
            $this->intent->forget($request); // استُهلكت النية: لا إعادة استعمال ولا نية معلّقة

            return $referral;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function attribute(User $referred, string $code, ?string $campaignSlug = null, string $source = UserReferral::SOURCE_LINK): ?UserReferral
    {
        if (! (bool) config('referrals.enabled')) {
            return null;
        }

        $existing = UserReferral::query()->where('referred_user_id', $referred->id)->first();

        if ($existing !== null) {
            return $existing; // محيل واحد؛ لا يُستبدل أبدًا
        }

        $hit = $this->codes->findActive($code);

        if ($hit === null || (int) $hit['user_id'] === (int) $referred->id) {
            return null; // رمز غير صالح، أو إحالة ذاتية
        }

        $referrer = User::query()->find($hit['user_id']);

        if ($referrer === null || $referrer->is_frozen || $referrer->email_verified_at === null) {
            return null;
        }

        $campaign = $this->campaigns->resolve($campaignSlug);
        $source = in_array($source, [UserReferral::SOURCE_LINK, UserReferral::SOURCE_SHARE_PARAM], true) ? $source : UserReferral::SOURCE_LINK;

        try {
            return UserReferral::query()->forceCreate([
                'referred_user_id' => $referred->id,
                'referrer_user_id' => $referrer->id,
                'referral_code_id' => $hit['id'],
                'referral_campaign_id' => $campaign?->id,
                'source' => $source,
                'status' => UserReferral::STATUS_PENDING,
                'qualification_rule' => $this->ruleFor($campaign),
                'min_account_age_hours' => $campaign?->effectiveMinAgeHours(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return UserReferral::query()->where('referred_user_id', $referred->id)->first(); // سباق: الأول يربح
        }
    }

    protected function ruleFor(?ReferralCampaign $campaign): string
    {
        $rules = (array) config('referrals.qualification.rules');
        $rule = $campaign?->qualification_rule;

        return is_string($rule) && isset($rules[$rule]) ? $rule : (string) config('referrals.qualification.default_rule');
    }
}
