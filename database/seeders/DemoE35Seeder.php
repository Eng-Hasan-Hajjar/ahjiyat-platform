<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\EditorialShelf;
use App\Models\User;
use App\Models\UserCollection;
use App\Services\Collections\CollectionModerationService;
use App\Services\Collections\CollectionReportService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Collections\CollectionVisibilityService;
use App\Services\Editorial\EditorialContentType;
use App\Services\Editorial\EditorialShelfService;
use App\Services\Engagement\CollectionService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * E35 - بيانات Demo للمجموعات العامة والأرفف التحريرية.   php artisan db:seed --class=DemoE35Seeder
 *
 * - اختيارية وترفض الإنتاج؛ **بيانات وهمية بالكامل** (نطاق collections.test) وكلمة المرور المعروفة للبيئات المحلية/الاختبارية فقط.
 * - تعتمد على أعمال مجتمع منشورة (تستدعي DemoCreatorSeeder إن لم توجد) ولا تُستدعى من DemoQaSeeder.
 * - **بالخدمات الرسمية فقط** (مجموعات، ظهور، حفظ، بلاغ، إخفاء إشرافي، أرفف): صلاحية + تدقيق + عدّادات صحيحة. لا جواهر ولا XP ولا مهام ولا أي أثر اقتصادي.
 * - تولّد: مجموعتان عامتان، غير مدرجة، خاصة، مخفية إشرافيًا، عدة حفظات، بلاغ مفتوح، رفّان فعّالان (اكتشاف + رئيسية)، ورف مجدول مستقبلًا.
 * - Idempotent: المجموعات بالعنوان لكل مالك، الأرفف بالمفتاح الداخلي، والخدمات تتجاهل المكرَّر ⇒ إعادة التشغيل لا تضاعف شيئًا.
 */
class DemoE35Seeder extends Seeder
{
    use RefusesProduction;

    public const PASSWORD = 'password';

    public const ADMIN_EMAIL = 'demo-collections-admin@collections.test';

    public const CURATOR_A = 'demo-curator-a@collections.test';

    public const CURATOR_B = 'demo-curator-b@collections.test';

    public const CURATOR_C = 'demo-curator-c@collections.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoE35Seeder');
        $this->call(RolesAndPermissionsSeeder::class);

        if (! CreatorProfile::query()->where('status', CreatorProfile::STATUS_APPROVED)->exists()) {
            $this->call(DemoCreatorSeeder::class);
        }

        $works = CreatorPuzzle::query()->visible()->orderBy('id')->limit(12)->get();

        if ($works->count() < 3) {
            $this->command?->warn('DemoE35Seeder: لا أعمال مجتمع منشورة كافية (3 على الأقل).');

            return;
        }

        $admin = $this->user(self::ADMIN_EMAIL, 'مدير المجموعات التجريبي', true);
        $admin->syncRoles(['administrator']);

        $a = $this->user(self::CURATOR_A, 'أمين المساء (تجريبي)', true);
        $b = $this->user(self::CURATOR_B, 'أمينة التحديات (تجريبية)', true);
        $c = $this->user(self::CURATOR_C, 'أمين مخالف (تجريبي)', true);

        $svc = app(CollectionService::class);
        $visibility = app(CollectionVisibilityService::class);

        $public1 = $this->collection($svc, $a, 'ألغاز المساء الهادئة', 'اختيارات للتسلية بعد يوم طويل.', $works->take(4));
        $public2 = $this->collection($svc, $b, 'تحديات للأصدقاء', 'أحجيات مناسبة للعب الجماعي.', $works->slice(2, 4));
        $unlisted = $this->collection($svc, $a, 'للمشاركة بالرابط فقط', 'يراها من لديه الرابط.', $works->take(2));
        $private = $this->collection($svc, $a, 'مجموعتي الخاصة', 'خاصة بي وحدي.', $works->slice(1, 2));
        $hidden = $this->collection($svc, $c, 'مجموعة أخفاها الإشراف', 'مثال لمجموعة أخفاها المشرف.', $works->take(3));

        $visibility->set($a, $public1->public_id, UserCollection::VISIBILITY_PUBLIC);
        $visibility->set($b, $public2->public_id, UserCollection::VISIBILITY_PUBLIC);
        $visibility->set($a, $unlisted->public_id, UserCollection::VISIBILITY_UNLISTED);
        $visibility->set($c, $hidden->public_id, UserCollection::VISIBILITY_PUBLIC);

        // الحفظات: لاعبون تجريبيون يحفظون المجموعات العامة وغير المدرجة (هويتهم خاصة؛ العدّاد المجمَّع وحده علني).
        $saves = app(CollectionSaveService::class);
        $savers = collect(['1' => 'حافظ أ', '2' => 'حافظ ب', '3' => 'حافظ ج'])->map(fn (string $name, string $key) => $this->user("demo-saver-{$key}@collections.test", $name, false))->values();

        foreach ($savers as $i => $saver) {
            foreach ([$public1, $public2, $unlisted] as $j => $target) {
                if (($i + $j) % 3 === 2) {
                    continue; // تفاوت بسيط حتمي بالأرقام
                }

                $this->try(fn () => $saves->save($saver, $target->public_id));
            }
        }

        // بلاغ مفتوح واحد على مجموعة عامة (سبب من القائمة المغلقة).
        $this->try(fn () => app(CollectionReportService::class)->report($savers[2], $public2->public_id, 'misleading'));

        // إخفاء إشرافي (بعد الحفظات، عبر خدمة الإشراف: صلاحية + تدقيق + إشعار المالك).
        $hiddenFresh = UserCollection::query()->whereKey($hidden->id)->first();

        if ($hiddenFresh !== null && ! $hiddenFresh->is_moderation_hidden) {
            $this->try(fn () => app(CollectionModerationService::class)->hide($hiddenFresh, $admin, 'مثال تجريبي: عنوان لا يطابق المحتوى.'));
        }

        $this->shelves($admin, $works, $public1, $public2);

        $this->command?->info('DemoE35Seeder: مجموعتان عامتان، غير مدرجة، خاصة، مخفية، حفظات، بلاغ مفتوح، رفّان فعّالان وآخر مجدول. كلمة المرور: '.self::PASSWORD);
    }

    /** @param \Illuminate\Support\Collection<int, CreatorPuzzle> $items */
    protected function collection(CollectionService $svc, User $owner, string $title, string $description, $items): UserCollection
    {
        $collection = UserCollection::query()->where('user_id', $owner->id)->where('title', $title)->first() ?? $svc->create($owner, $title, $description);

        foreach ($items as $work) {
            $this->try(fn () => $svc->add($owner, $collection->public_id, $work->fresh()));
        }

        return $collection->fresh();
    }

    protected function shelves(User $admin, $works, UserCollection $public1, UserCollection $public2): void
    {
        $svc = app(EditorialShelfService::class);

        $this->shelf($svc, $admin, 'demo-e35-discover-picks', 'اختيارات المحررين', 'مختارات يدوية من فريق المنصة.', EditorialShelf::PLACEMENT_DISCOVER, null, null, 1, [
            [EditorialContentType::CommunityPuzzle->value, $works[0]->id],
            [EditorialContentType::CommunityPuzzle->value, $works[1]->id],
            [EditorialContentType::PublicCollection->value, $public1->id],
        ]);

        $this->shelf($svc, $admin, 'demo-e35-home-collections', 'مجموعات ننصح بها', null, EditorialShelf::PLACEMENT_HOME, null, null, 1, [
            [EditorialContentType::PublicCollection->value, $public2->id],
            [EditorialContentType::CommunityPuzzle->value, $works[2]->id],
        ]);

        // رف مجدول لبداية مستقبلية: فعّال لكنه لا يظهر قبل starts_at (يُقيَّم وقت القراءة).
        $this->shelf($svc, $admin, 'demo-e35-scheduled', 'قريبًا: تحدي الأسبوع', 'يظهر تلقائيًا في موعده.', EditorialShelf::PLACEMENT_DISCOVER, now()->addDays(5), now()->addDays(12), 2, [
            [EditorialContentType::CommunityPuzzle->value, $works[3]->id],
        ]);
    }

    /** @param list<array{0: string, 1: int}> $items */
    protected function shelf(EditorialShelfService $svc, User $admin, string $key, string $title, ?string $subtitle, string $placement, $startsAt, $endsAt, int $sort, array $items): void
    {
        $shelf = EditorialShelf::query()->where('internal_key', $key)->first();

        if ($shelf !== null && $shelf->status === EditorialShelf::STATUS_ARCHIVED) {
            return;
        }

        $shelf ??= $svc->create($admin, [
            'internal_key' => $key, 'title' => $title, 'subtitle' => $subtitle, 'placement' => $placement,
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'sort_order' => $sort, 'max_items' => 8,
        ]);

        $this->try(function () use ($svc, $admin, $shelf, $items) {
            $svc->setItems($admin, $shelf, $items);
            $svc->activate($admin, $shelf->fresh());
        });
    }

    protected function try(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
            // مكرَّر أو غير مؤهَّل: يُتجاهل (Demo فقط).
        }
    }

    protected function user(string $email, string $name, bool $publicProfile = false): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create(['email' => $email, 'name' => $name, 'password' => Hash::make(self::PASSWORD)]);
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()] + ($publicProfile ? ['profile_visibility' => User::VISIBILITY_PUBLIC] : []))->save();

        return $user->fresh();
    }
}
