<?php

namespace App\Services\Collections;

use App\Models\User;
use App\Models\UserCollection;
use App\Services\Engagement\CollectionService;
use App\Services\Engagement\EngagementException;
use App\Services\Engagement\EngagementGuard;
use Illuminate\Support\Facades\DB;

/**
 * E35 - تغيير ظهور المجموعة (private | unlisted | public). **المالك وحده** (owned() محصور بالمالك: غير المالك = 404). لا اقتصاد: لا جواهر ولا XP ولا مهام ولا سلسلة
 * للنشر أو إلغائه، ولا يلمس هذا الصنف أي خدمة اقتصاد.
 *
 *  - الجعل «عامة» أو «غير مدرجة» يشترط: بريد موثَّق ∧ حساب غير مجمَّد (EngagementGuard، قراءة حيّة). الرجوع إلى «خاصة» مسموح دائمًا للمالك (تقليل التعرّض).
 *  - «عامة» تشترط ملفًا شخصيًا عامًا (قرار الخصوصية، انظر CollectionAccess) و≥ min_visible_items_public عملًا ظاهرًا. «غير مدرجة» بلا هذين الشرطين.
 *  - مجموعة مخفية إشرافيًا: لا يملك المالك رفع الإخفاء ولا إعادة نشرها؛ يستطيع جعلها خاصة وتعديلها وحذفها فقط.
 *  - first_published_at يُكتب مرة واحدة عند أول خروج من الخاصة ولا يُكتب فوقه.
 *  - الحفظات تبقى عند الرجوع إلى الخاصة (لا فقدان بيانات)؛ تظهر لمن حفظها بنسبة محايدة «غير متاحة» حتى تُنشر ثانية.
 *  - الصيغ الثلاث idempotent: تكرار الحالة نفسها لا يغيّر شيئًا (changed=false).
 */
class CollectionVisibilityService
{
    public function __construct(
        protected EngagementGuard $guard,
        protected CollectionService $collections,
        protected CollectionAccess $access,
    ) {}

    /** @return array{changed: bool, collection: UserCollection} */
    public function publish(User $user, string $publicId): array
    {
        return $this->change($user, $publicId, UserCollection::VISIBILITY_PUBLIC);
    }

    /** @return array{changed: bool, collection: UserCollection} */
    public function makeUnlisted(User $user, string $publicId): array
    {
        return $this->change($user, $publicId, UserCollection::VISIBILITY_UNLISTED);
    }

    /** @return array{changed: bool, collection: UserCollection} */
    public function makePrivate(User $user, string $publicId): array
    {
        return $this->change($user, $publicId, UserCollection::VISIBILITY_PRIVATE);
    }

    /** @return array{changed: bool, collection: UserCollection} */
    public function set(User $user, string $publicId, string $visibility): array
    {
        if (! in_array($visibility, UserCollection::VISIBILITIES, true)) {
            throw new EngagementException('قيمة الظهور غير صالحة.');
        }

        return $this->change($user, $publicId, $visibility);
    }

    /** @return array{changed: bool, collection: UserCollection} */
    protected function change(User $user, string $publicId, string $target): array
    {
        if ($target !== UserCollection::VISIBILITY_PRIVATE) {
            $this->guard->assertCanInteract($user);
        }

        $result = DB::transaction(function () use ($user, $publicId, $target) {
            $collection = $this->collections->owned($user, $publicId, true);

            if ($collection->visibility === $target) {
                return ['changed' => false, 'collection' => $collection];
            }

            if ($target !== UserCollection::VISIBILITY_PRIVATE && $collection->is_moderation_hidden) {
                throw new EngagementException('أخفى فريق الإشراف هذه المجموعة، ولا يمكن نشرها حاليًا.');
            }

            if ($target === UserCollection::VISIBILITY_PUBLIC) {
                $this->assertCanBePublic($user, $collection);
            }

            $attributes = ['visibility' => $target];

            if ($target !== UserCollection::VISIBILITY_PRIVATE && $collection->first_published_at === null) {
                $attributes['first_published_at'] = now();
            }

            $collection->forceFill($attributes)->save();

            return ['changed' => true, 'collection' => $collection];
        });

        if ($result['changed']) {
            // تحديث سريع للقوائم المخزَّنة (الظهور الفعلي مفحوص حيًّا بكل قراءة أصلًا؛ هذا فقط لتقليل تأخر ظهور/اختفاء مجموعة بقسم الاكتشاف وأرقام اللوحة).
            CollectionDiscovery::flush();
            CollectionAnalytics::flush();
        }

        return $result;
    }

    protected function assertCanBePublic(User $user, UserCollection $collection): void
    {
        $profileVisibility = User::query()->whereKey($user->id)->value('profile_visibility');

        if ($profileVisibility !== User::VISIBILITY_PUBLIC) {
            throw new EngagementException('لنشر مجموعة عامة اجعل ملفك الشخصي عامًا (يظهر اسمك أمينًا للمجموعة في البحث والاكتشاف)، أو اختر «غير مدرجة».');
        }

        $min = max(1, (int) config('collections.publish.min_visible_items_public'));

        if ($this->access->visibleCount($collection->id) < $min) {
            throw new EngagementException('أضف إلى المجموعة '.$min.' أحجية ظاهرة على الأقل قبل نشرها علنًا.');
        }
    }
}
