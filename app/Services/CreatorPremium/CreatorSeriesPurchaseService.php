<?php

namespace App\Services\CreatorPremium;

use App\Models\CreatorEarningAccount;
use App\Models\CreatorProfile;
use App\Models\CreatorSeries;
use App\Models\CreatorSeriesSaleOffer;
use App\Models\DigitalPurchase;
use App\Models\User;
use App\Services\Commerce\CommerceException;
use App\Services\Commerce\CommercePaymentProviderRegistry;
use App\Services\Commerce\DigitalPurchaseService;
use App\Services\CreatorSeries\CreatorSeriesVisibilityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * E43 - إنشاء طلب شراء وصول سلسلة مميزة (DigitalPurchase من نوع creator_series_access). **الخادم وحده** يحدد السعر والعملة والمبدع والمنتج من قاعدة البيانات؛
 * العميل لا يرسل سعرًا ولا عملة ولا مبدعًا ولا حصة ولا purchase_id (لا IDOR، لا spoofing). لا يمنح وصولًا: الوصول يأتي من التحقق المالي E41 حصرًا (معالج المنتج).
 *
 *  - مستضاف فقط: الدفع عبر مزوّد يدعم صفحة دفع مستضافة (E41). لا مسار «تحويل يدوي» لسلاسل المبدعين (لا مراجعة إثبات يدوي هنا)، ولا بوابة جديدة.
 *  - طلب مفتوح/محقَّق واحد لكل (مستخدم، سلسلة) بقيد القاعدة (open_key) + request_key يفكّ النقر المزدوج. من يملك الوصول لا يشتري ثانيةً.
 *  - يُرفض: ميزة معطَّلة، مشترٍ مجمَّد، المبدع نفسه، سلسلة غير ظاهرة (إشراف/نشر)، عرض غير نشط، تسييل غير مفعَّل/موقوف، عملة لا تطابق حساب المبدع، سعر خارج الحدود.
 *  - الأقفال: حساب أرباح المبدع ← صف العرض (الترتيب نفسه في خدمة العروض): إيقاف التسييل/تغيير السعر/إيقاف العرض لا يتقاطع مع إنشاء الطلب (يُحسم قبله أو بعده، لا وسطه).
 *  - لقطة الطلب: product_id = رقم السلسلة، product_public_id = معرّفها العام، product_name = عنوانها، amount_minor/currency = سعر العرض لحظة الإنشاء (ثابتة؛ تغيير السعر لاحقًا لا يمسها).
 *  - طلب مفتوح قائم يُعاد كما هو بلقطته (لا إعادة تسعير صامتة).
 */
class CreatorSeriesPurchaseService
{
    public function __construct(
        protected CreatorPremiumPolicy $policy,
        protected CreatorSeriesOfferService $offers,
        protected CreatorPremiumAccess $access,
        protected CreatorSeriesVisibilityService $visibility,
        protected CommercePaymentProviderRegistry $providers,
        protected DigitalPurchaseService $core,
    ) {}

    /** مفتاح الطلب المفتوح للمستخدم/السلسلة (يطابق DigitalPurchase::booted). */
    public static function openKey(int $seriesId, int $userId): string
    {
        return DigitalPurchase::PRODUCT_CREATOR_SERIES.':'.$seriesId.':'.$userId;
    }

    /** الطلب المفتوح/المحقَّق القائم للمستخدم على هذه السلسلة (لزر «تابع الدفع» في صفحة السلسلة). */
    public function keyedPurchase(User $user, CreatorSeries|int $series): ?DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('open_key', self::openKey($series instanceof CreatorSeries ? (int) $series->getKey() : $series, (int) $user->getKey()))
            ->first();
    }

    /**
     * @throws CreatorPremiumException
     */
    public function request(User $user, CreatorSeries $series, ?string $requestKey = null): DigitalPurchase
    {
        $this->policy->assertEnabled();

        if ($user->is_frozen) {
            throw new CreatorPremiumException('frozen', 'حسابك مقيَّد؛ لا يمكن شراء الوصول.');
        }

        if (! (bool) config('commerce.enabled', true)) {
            throw new CreatorPremiumException('commerce_disabled', 'الدفع الإلكتروني غير متاح حاليًا.');
        }

        $requestKey = $this->cleanKey($requestKey);

        $fresh = CreatorSeries::query()->with('creator:id,user_id')->find($series->getKey());

        if ($fresh === null || ! $this->visibility->isVisible($fresh)) {
            throw new CreatorPremiumException('series_unavailable', 'هذه السلسلة غير متاحة حاليًا.');
        }

        if ((int) $fresh->creator->user_id === (int) $user->getKey()) {
            throw new CreatorPremiumException('own_series', 'هذه سلسلتك؛ يمكنك معاينتها دون شراء.');
        }

        if ($this->access->ownsSeries($user, $fresh)) {
            throw new CreatorPremiumException('already_owned', 'أنت تملك الوصول إلى هذه السلسلة بالفعل.');
        }

        if ($requestKey !== null) {
            $byKey = $this->byKey($user, $fresh, $requestKey);

            if ($byKey !== null) {
                return $this->reuse($byKey, $fresh);
            }
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $keyed = $this->keyedPurchase($user, $fresh);

            if ($keyed !== null) {
                if ($keyed->isVerified()) {
                    throw new CreatorPremiumException('already_owned', 'أنت تملك الوصول إلى هذه السلسلة بالفعل.');
                }

                if (! $this->expireIfStale($keyed)) {
                    return $this->reuse($keyed->fresh(), $fresh);
                }
            }

            // مفتاح الطلب فريد لكل مستخدم عبر كل المنتجات (E41): مفتاح استُعمل لطلب آخر (سلسلة/منتج غير هذا) لا يُعاد ولا يصطدم بالقيد؛ يُنشأ الطلب بلا مفتاح
            // (النقر المزدوج على هذه السلسلة محكوم بقيد الطلب المفتوح الواحد أصلًا).
            $freeKey = ($requestKey !== null && ! DigitalPurchase::query()->where('user_id', $user->getKey())->where('request_key', $requestKey)->exists()) ? $requestKey : null;

            try {
                return $this->create($user, $fresh, $freeKey);
            } catch (UniqueConstraintViolationException) {
                // سباق: طلب متزامن فاز بـopen_key أو request_key أو المرجع؛ نعيد التقييم (يُرجع الفائز أو يعيد توليد المرجع).
                if ($requestKey !== null) {
                    $byKey = $this->byKey($user, $fresh, $requestKey);

                    if ($byKey !== null) {
                        return $this->reuse($byKey, $fresh);
                    }
                }
            }
        }

        throw new CreatorPremiumException('busy', 'تعذّر إنشاء الطلب الآن، أعد المحاولة بعد لحظات.');
    }

    /** طلب سابق بالمفتاح نفسه **لهذه السلسلة وهذا المستخدم** فقط (مفتاح مُعاد لسلسلة أخرى لا يُرجِع طلب سلسلة غيرها). */
    protected function byKey(User $user, CreatorSeries $series, string $requestKey): ?DigitalPurchase
    {
        return DigitalPurchase::query()
            ->where('user_id', $user->getKey())->where('request_key', $requestKey)
            ->where('product_type', DigitalPurchase::PRODUCT_CREATOR_SERIES)->where('product_id', $series->getKey())
            ->first();
    }

    /**
     * إعادة طلب قائم: «بانتظار الدفع» يُعاد التحقق من أن البيع ما زال متاحًا قبل متابعة الدفع (عرض أُوقف/أُغلق/تسييل أُوقف ⇒ لا جلسة دفع جديدة لشيء لن يُفتح).
     * لا يغيّر لقطة الطلب (السعر الأصلي يبقى). المحقَّق/غيره يُعاد كما هو لتتولاه البوابة الأعلى.
     *
     * @throws CreatorPremiumException
     */
    protected function reuse(DigitalPurchase $purchase, CreatorSeries $series): DigitalPurchase
    {
        if ($purchase->status === DigitalPurchase::STATUS_VERIFIED) {
            throw new CreatorPremiumException('already_owned', 'أنت تملك الوصول إلى هذه السلسلة بالفعل.');
        }

        if ($purchase->status !== DigitalPurchase::STATUS_AWAITING) {
            return $purchase;
        }

        $offer = CreatorSeriesSaleOffer::query()->where('live_series_id', $series->getKey())->first();

        if ($offer === null) {
            throw new CreatorPremiumException('not_for_sale', 'هذه السلسلة غير معروضة للبيع.');
        }

        $offer->setRelation('series', $series);

        if (($blocker = $this->offers->sellBlocker($offer)) !== null) {
            throw new CreatorPremiumException('sales_closed', $blocker);
        }

        return $purchase;
    }

    protected function create(User $user, CreatorSeries $series, ?string $requestKey): DigitalPurchase
    {
        return DB::transaction(function () use ($user, $series, $requestKey) {
            // الترتيب الثابت: حساب أرباح المبدع ← صف العرض.
            $account = CreatorEarningAccount::query()->where('creator_profile_id', $series->creator_profile_id)->lockForUpdate()->first();
            $offer = CreatorSeriesSaleOffer::query()->where('live_series_id', $series->getKey())->lockForUpdate()->first();

            if ($offer === null) {
                throw new CreatorPremiumException('not_for_sale', 'هذه السلسلة غير معروضة للبيع.');
            }

            $offer->setRelation('series', $series);

            if (($blocker = $this->offers->sellBlocker($offer, $account)) !== null) {
                throw new CreatorPremiumException('sales_closed', $blocker);
            }

            // الخيار A: عملة العرض = عملة حساب المبدع، وتُتحقق هنا مرة ثالثة (بعد التفعيل والتحديث) قبل أي لقطة.
            if (($problem = $this->policy->currencyProblem($account, (string) $offer->currency)) !== null) {
                throw new CreatorPremiumException('currency_mismatch', $problem);
            }

            $provider = $this->hostedProvider((string) $offer->currency);
            $title = (string) ($series->published_title ?? '');

            $purchase = new DigitalPurchase;
            $purchase->forceFill([
                'user_id' => $user->getKey(),
                'product_type' => DigitalPurchase::PRODUCT_CREATOR_SERIES,
                'product_id' => $series->getKey(),
                'product_public_id' => $series->public_id,
                'product_name' => mb_substr($title, 0, 160),
                'amount_minor' => (int) $offer->price_minor,
                'currency' => (string) $offer->currency,
                'provider' => $provider,
                'status' => DigitalPurchase::STATUS_AWAITING,
                'request_key' => $requestKey,
                'reference_code' => $this->newReference(),
                'expires_at' => now()->addHours(max(1, (int) config('commerce.checkout.purchase_expiry_hours', 24))),
            ])->save();

            $this->core->event($purchase, 'created', null, DigitalPurchase::STATUS_AWAITING, $user, null);

            return $purchase;
        });
    }

    /** مفتاح مزوّد مستضاف يقبل العملة (الافتراضي أو البديل)، وإلا خطأ: لا تحويل يدوي لسلاسل المبدعين. @throws CreatorPremiumException */
    protected function hostedProvider(string $currency): string
    {
        try {
            $provider = $this->providers->forNewPurchase($currency, false);
        } catch (CommerceException) {
            throw new CreatorPremiumException('provider_disabled', 'طريقة الدفع غير متاحة حاليًا لهذه السلسلة.');
        }

        if (! $provider->supportsRedirect() || ! $provider->isEnabled()) {
            throw new CreatorPremiumException('provider_disabled', 'طريقة الدفع غير متاحة حاليًا لهذه السلسلة.');
        }

        return $provider->key();
    }

    /** ينهي صلاحية طلب «بانتظار الدفع» متأخر عند اللمس. @return bool true إن انتهى الآن (فيُنشأ بديل). */
    protected function expireIfStale(DigitalPurchase $purchase): bool
    {
        if ($purchase->status !== DigitalPurchase::STATUS_AWAITING || $purchase->expires_at === null || $purchase->expires_at->gt(now())) {
            return false;
        }

        return DB::transaction(function () use ($purchase): bool {
            $locked = $this->core->lock($purchase);

            if ($locked->status !== DigitalPurchase::STATUS_AWAITING || $locked->expires_at === null || $locked->expires_at->gt(now()) || $locked->needsReview()) {
                return false;
            }

            $locked->forceFill(['status' => DigitalPurchase::STATUS_EXPIRED, 'expired_at' => now()])->save();
            $this->core->event($locked, 'expired', DigitalPurchase::STATUS_AWAITING, DigitalPurchase::STATUS_EXPIRED, null, null);

            return true;
        });
    }

    protected function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, $max)];
            }

            $reference = 'CSA-'.$code;

            if (! DigitalPurchase::query()->where('reference_code', $reference)->exists()) {
                return $reference;
            }
        }

        return 'CSA-'.strtoupper(bin2hex(random_bytes(6)));
    }

    protected function cleanKey(?string $key): ?string
    {
        return ($key !== null && preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $key) === 1) ? $key : null;
    }
}
