<?php

namespace Database\Seeders;

use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorSeriesRevision;
use App\Models\User;
use App\Services\CreatorSeries\CreatorSeriesReviewService;
use App\Services\CreatorSeries\CreatorSeriesService;
use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

/**
 * E30 (ب) - بيانات Demo لسلاسل المبدعين.   php artisan db:seed --class=DemoCreatorSeriesSeeder
 *
 * - اختيارية وترفض الإنتاج. **تعتمد على DemoCreatorSeeder** (تستدعيه إن لم يوجد مبدعها) ولا تُستدعى من DemoQaSeeder.
 * - **بالخدمات الرسمية فقط** (مسودة، إرسال، اعتماد): سلسلتان منشورتان وواحدة قيد المراجعة وواحدة مسودة. لا مكافآت ولا نقاط ولا أي أثر اقتصادي بالبناء.
 * - **Idempotent**: السلسلة بعنوانها لكل مبدع. مستقلة تمامًا عن DemoSponsorshipSeeder.
 */
class DemoCreatorSeriesSeeder extends Seeder
{
    use RefusesProduction;

    public const CREATOR_EMAIL = 'demo-creator-nour@creators.test';

    public const REVIEWER_EMAIL = 'demo-reviewer@creators.test';

    public function run(): void
    {
        $this->refuseInProduction('DemoCreatorSeriesSeeder');

        $creator = User::query()->where('email', self::CREATOR_EMAIL)->first();

        if ($creator === null || ! CreatorProfile::query()->where('user_id', $creator->id)->where('status', CreatorProfile::STATUS_APPROVED)->exists()) {
            $this->call(DemoCreatorSeeder::class);
            $creator = User::query()->where('email', self::CREATOR_EMAIL)->firstOrFail();
        }

        $reviewer = User::query()->where('email', self::REVIEWER_EMAIL)->firstOrFail();
        $profile = CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail();

        $works = CreatorPuzzle::query()->visible()->where('creator_profile_id', $profile->id)->orderBy('id')->pluck('public_id', 'published_title');

        $pick = fn (array $titles): array => array_values(array_filter(array_map(fn ($t) => $works[$t] ?? null, $titles)));

        $series = app(CreatorSeriesService::class);
        $review = app(CreatorSeriesReviewService::class);

        $plan = [
            ['ألغاز القلم والكواكب', 'مجموعة قصيرة من ألغاز نور: كلمات وفلك.', ['ما الذي يكتب ولا يقرأ؟', 'أي هذه الكواكب أكبر؟'], 'published'],
            ['رحلة الفصول والألغاز', 'ثلاثة ألغاز خفيفة بترتيب مقصود.', ['ما الذي يكتب ولا يقرأ؟', 'أي هذه الكواكب أكبر؟', 'رتّب فصول السنة'], 'published'],
            ['سلسلة قيد المراجعة', 'سلسلة أُرسلت وتنتظر قرار الإشراف.', ['أي هذه الكواكب أكبر؟', 'رتّب فصول السنة'], 'pending'],
            ['مسودة سلسلة', 'مسودة لم تُرسل بعد.', ['ما الذي يكتب ولا يقرأ؟', 'رتّب فصول السنة'], 'draft'],
        ];

        foreach ($plan as [$title, $description, $titles, $state]) {
            $items = $pick($titles);

            if (count($items) < 2) {
                $this->command?->warn("DemoCreatorSeriesSeeder: لا أعمال منشورة كافية لسلسلة «{$title}».");

                continue;
            }

            $revision = CreatorSeriesRevision::query()->where('title', $title)->whereHas('series', fn ($q) => $q->where('creator_profile_id', $profile->id))->first()
                ?? $series->createDraft($creator, ['title' => $title, 'description' => $description, 'items' => $items]);

            if ($state !== 'draft' && $revision->status === CreatorSeriesRevision::STATUS_DRAFT) {
                $revision = $series->submit($creator, $revision->series()->value('public_id'));
            }

            if ($state === 'published' && $revision->status === CreatorSeriesRevision::STATUS_PENDING_REVIEW) {
                $review->approve($revision, $reviewer, 'معتمدة للعرض التجريبي.');
            }
        }

        $this->command?->info('DemoCreatorSeriesSeeder: سلاسل ('.\App\Models\CreatorSeries::query()->count().').');
    }
}
