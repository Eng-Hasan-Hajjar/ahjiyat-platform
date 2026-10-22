<?php

namespace App\Console\Commands;

use App\Models\CommunityReputationSnapshot;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Reputation\CommunityReputationService;
use App\Services\Reputation\ReputationConfigException;
use App\Services\Reputation\ReputationRankings;
use App\Services\Reputation\ReputationSettings;
use Illuminate\Console\Command;

class RecalculateReputation extends Command
{
    protected $signature = 'reputation:recalculate
        {--dry-run : احسب وأبلغ دون كتابة أي لقطة ولا اعتراف}
        {--creator= : slug مبدع واحد}
        {--user= : public_id لاعب واحد}
        {--all : كل المستخدمين ذوي لقطة + كل من لا لقطة لهم ولديهم مصدر سمعة (بدفعات chunkById)}
        {--stale : الدفعة المجدولة المحدودة (الأقدم حسابًا + الجدد) — الافتراضي عند عدم تحديد هدف}
        {--limit= : سقف دفعة --stale (الافتراضي refresh.batch)}
        {--chunk= : حجم دفعة القراءة (الافتراضي refresh.chunk)}';

    protected $description = 'E37: يعيد حساب لقطات السمعة المجتمعية (مشتقّة من تقييمات/مفضّلات/متابعات/حفظ/إعجابات/أعمال ظاهرة) ويقيِّم إنجازات الاعتراف. آمن للتكرار ولا يمسّ أي بيانات أخرى. لا يحتاج الموقع ولا الطابور.';

    public function handle(CommunityReputationService $service, ReputationSettings $settings, ReputationRankings $rankings): int
    {
        if (! $settings->enabled()) {
            $this->info('السمعة المجتمعية معطّلة (reputation.enabled=false): لم يُحسب شيء.');

            return self::SUCCESS;
        }

        try {
            $settings->assertValid();

            $dry = (bool) $this->option('dry-run');
            $chunk = max(10, min(1000, (int) ($this->option('chunk') ?: $settings->int('refresh.chunk', 100))));

            if ($this->option('creator') !== null) {
                $profile = CreatorProfile::query()->where('slug', (string) $this->option('creator'))->first();

                if ($profile === null) {
                    $this->error('لا مبدع بهذا الـslug.');

                    return self::FAILURE;
                }

                return $this->single($service, (int) $profile->user_id, $dry, $rankings);
            }

            if ($this->option('user') !== null) {
                $user = User::query()->where('public_id', (string) $this->option('user'))->first();

                if ($user === null) {
                    $this->error('لا لاعب بهذا المعرّف العام.');

                    return self::FAILURE;
                }

                return $this->single($service, (int) $user->id, $dry, $rankings);
            }

            if ($this->option('all')) {
                return $this->all($service, $dry, $chunk, $rankings);
            }

            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
            $result = $dry ? $this->dryStale($service, $limit ?? $settings->int('refresh.batch', 200)) : $service->refreshStale($limit);

            $this->line(sprintf('دفعة مجدولة: قديمة=%d جديدة=%d معالَجة=%d مُنشأة=%d متغيّرة=%d', $result['stale'], $result['discovered'], $result['processed'], $result['created'], $result['changed']));

            if (! $dry && $result['processed'] > 0) {
                $rankings->flush();
            }

            return self::SUCCESS;
        } catch (ReputationConfigException $e) {
            $this->error('إعداد السمعة غير صالح: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function single(CommunityReputationService $service, int $userId, bool $dry, ReputationRankings $rankings): int
    {
        $result = $service->refreshUsers([$userId], $dry);
        $row = $result['rows'][$userId] ?? null;

        if ($row === null) {
            $this->error('المستخدم غير موجود.');

            return self::FAILURE;
        }

        $this->line(sprintf('%s — مبدع=%d (جودة=%d تفاعل=%d استمرارية=%d وصول=%d) صاعد=%d منسّق=%d مفيد=%d | مؤهَّل: مبدع=%s صاعد=%s منسّق=%s مفيد=%s',
            $dry ? 'dry-run' : 'كُتبت', $row['creator_score'], $row['creator_rating_score'], $row['creator_engagement_score'], $row['creator_consistency_score'], $row['creator_reach_score'],
            $row['rising_score'], $row['curator_score'], $row['helpfulness_score'],
            $row['creator_eligible'] ? 'نعم' : 'لا', $row['rising_eligible'] ? 'نعم' : 'لا', $row['curator_eligible'] ? 'نعم' : 'لا', $row['helpful_eligible'] ? 'نعم' : 'لا'));

        if (! $dry) {
            $rankings->flush();
        }

        return self::SUCCESS;
    }

    protected function all(CommunityReputationService $service, bool $dry, int $chunk, ReputationRankings $rankings): int
    {
        $totals = ['processed' => 0, 'created' => 0, 'changed' => 0, 'unchanged' => 0];

        CommunityReputationSnapshot::query()->select('id', 'user_id')->chunkById($chunk, function ($rows) use ($service, $dry, &$totals) {
            $r = $service->refreshUsers($rows->pluck('user_id')->map(fn ($id) => (int) $id)->all(), $dry);

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }
        });

        $seen = [];

        // المستخدمون بلا لقطة: كل لقطة جديدة تخرج من مجموعة الاكتشاف فيتقدّم الحلقة. في dry-run لا كتابة، فنقف عند أول دفعة ونبلّغ بعددها.
        for ($i = 0; $i < 10000; $i++) {
            $ids = $service->discoverMissing($chunk);
            $fresh = array_values(array_diff($ids, $seen));

            if ($fresh === []) {
                break;
            }

            $seen = array_merge($seen, $fresh);
            $r = $service->refreshUsers($fresh, $dry);

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }

            if ($dry) {
                $this->line('dry-run: يوجد مستخدمون بلا لقطة (أول دفعة فقط محسوبة، '.count($fresh).').');

                break;
            }
        }

        $this->line(sprintf('الكل%s: معالَجة=%d مُنشأة=%d متغيّرة=%d دون تغيير=%d', $dry ? ' (dry-run)' : '', $totals['processed'], $totals['created'], $totals['changed'], $totals['unchanged']));

        if (! $dry) {
            $rankings->flush();
        }

        return self::SUCCESS;
    }

    /** @return array{stale:int, discovered:int, processed:int, created:int, changed:int} */
    protected function dryStale(CommunityReputationService $service, int $limit): array
    {
        $cutoff = now()->subMinutes(max(1, config('reputation.refresh.max_age_minutes', 180)))->toDateTimeString();
        $stale = CommunityReputationSnapshot::query()->where(fn ($q) => $q->where('calculated_at', '<', $cutoff)->orWhereNull('calculated_at'))
            ->orderBy('calculated_at')->limit($limit)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $new = $service->discoverMissing(max(0, $limit - count($stale)));
        $r = $service->refreshUsers(array_merge($stale, $new), true);

        return ['stale' => count($stale), 'discovered' => count($new)] + array_intersect_key($r, array_flip(['processed', 'created', 'changed']));
    }
}
