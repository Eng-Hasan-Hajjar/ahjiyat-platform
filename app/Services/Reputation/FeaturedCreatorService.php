<?php

namespace App\Services\Reputation;

use App\Models\CommunityFeaturedCreator;
use App\Models\CreatorProfile;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationType;
use App\Services\OperationalAuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * E37 - «مبدع مختار»: إبراز **تحريري** بقرار بشري مُدقَّق، لا خوارزمية ولا مال.
 *
 *  - الصلاحية reputation.feature (مدير/مدير محتوى) بالصلاحيات لا بأسماء الأدوار. المشرف وحده لا يملكها (لا يغيّر الاعتراف).
 *  - يشترط: مبدع معتمَد ∧ حساب غير مجمَّد ∧ عمل ظاهر واحد على الأقل (لا إبراز مبدع بلا ما يُعرض).
 *  - سقف المختارين الفعّالين معًا (featured.max_active) فلا تتحول القائمة إلى «الجميع مختارون».
 *  - لا يغيّر أي درجة/ترتيب/جائزة، ولا يشتريه دعم ولا رعاية ولا دفع (لا مدخل للمال هنا). الملاحظة داخلية للفريق ولا تُعرض علنًا.
 *  - كل إجراء: معاملة بقفل صف المبدع + تدقيق (creator_featured / creator_feature_updated / creator_unfeatured). الإشعار مرة واحدة لكل اختيار جديد (مفتاح دلالي) ومعزول الفشل.
 *  - الظهور العلني يُعاد فحصه حيًّا (FeaturedCreatorsReader): تعليق المبدع/تجميده/إخفاء أعماله يزيله فورًا دون إلغاء الاختيار، ويعود بالاستعادة.
 */
class FeaturedCreatorService
{
    public function __construct(
        protected ReputationSettings $settings,
        protected OperationalAuditService $audit,
        protected NotificationDispatcher $dispatcher,
    ) {}

    public function feature(CreatorProfile $profile, User $actor, ?string $note = null, ?CarbonInterface $from = null, ?CarbonInterface $until = null): CommunityFeaturedCreator
    {
        $this->authorize($actor);
        $note = $this->cleanNote($note);

        if ($from !== null && $until !== null && $until->lessThanOrEqualTo($from)) {
            throw new ReputationException('نهاية الإبراز يجب أن تكون بعد بدايته.');
        }

        $notify = false;

        $row = DB::transaction(function () use ($profile, $actor, $note, $from, $until, &$notify) {
            $locked = CreatorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $this->assertEligible($locked);

            $existing = CommunityFeaturedCreator::query()->where('creator_profile_id', $locked->id)->lockForUpdate()->first();
            $wasActive = $existing !== null && $this->isActive($existing);

            if (! $wasActive && CommunityFeaturedCreator::query()->activeNow()->count() >= max(1, $this->settings->int('featured.max_active', 12))) {
                throw new ReputationException('بلغ عدد المبدعين المختارين الحدّ الأقصى. أنهِ إبراز أحدهم أولًا.');
            }

            $values = [
                'featured_from' => $from ?? ($wasActive ? $existing->featured_from : now()),
                'featured_until' => $until,
                'featured_by' => $actor->id,
                'note' => $note,
            ];

            if ($existing === null) {
                $row = (new CommunityFeaturedCreator)->forceFill(['creator_profile_id' => $locked->id] + $values);
                $row->save();
                $this->audit->log('creator_featured', $row, ['creator' => $locked->slug, 'until' => $until?->toDateTimeString(), 'note' => $note], $actor);
                $notify = true;
            } else {
                $existing->forceFill($values)->save();
                $row = $existing;
                $this->audit->log($wasActive ? 'creator_feature_updated' : 'creator_featured', $row, ['creator' => $locked->slug, 'until' => $until?->toDateTimeString(), 'note' => $note], $actor);
                $notify = ! $wasActive;
            }

            return $row;
        });

        if ($notify) {
            $this->notify($profile, $row);
        }

        return $row;
    }

    public function unfeature(CreatorProfile $profile, User $actor): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($profile, $actor) {
            $row = CommunityFeaturedCreator::query()->where('creator_profile_id', $profile->id)->lockForUpdate()->first();

            if ($row === null || ! $this->isActive($row)) {
                throw new ReputationException('هذا المبدع غير مختار حاليًا.');
            }

            $row->forceFill(['featured_until' => now(), 'featured_by' => $actor->id])->save();
            $this->audit->log('creator_unfeatured', $row, ['creator' => $profile->slug], $actor);
        });
    }

    public function isActive(CommunityFeaturedCreator $row): bool
    {
        return ($row->featured_from === null || $row->featured_from->lessThanOrEqualTo(now()))
            && ($row->featured_until === null || $row->featured_until->greaterThan(now()));
    }

    protected function authorize(User $actor): void
    {
        abort_unless($actor->can('reputation.feature'), 403);
    }

    protected function assertEligible(CreatorProfile $profile): void
    {
        if (! $profile->isApproved()) {
            throw new ReputationException('لا يُختار إلا مبدع معتمَد.');
        }

        $user = User::query()->find($profile->user_id);

        if ($user === null || $user->is_frozen) {
            throw new ReputationException('لا يُختار مبدع حسابه مجمَّد.');
        }

        $hasVisible = DB::table('creator_puzzles')->where('creator_profile_id', $profile->id)->where('is_visible', true)->exists();

        if (! $hasVisible) {
            throw new ReputationException('لا يُختار مبدع بلا أعمال ظاهرة منشورة.');
        }
    }

    protected function cleanNote(?string $note): ?string
    {
        $note = trim(strip_tags((string) $note));

        if ($note === '') {
            return null;
        }

        $max = max(1, $this->settings->int('featured.note_max', 160));

        if (mb_strlen($note) > $max) {
            throw new ReputationException("الملاحظة لا تتجاوز {$max} حرفًا.");
        }

        return $note;
    }

    /** إشعار «مبدع مختار» لمرة واحدة لكل اختيار جديد (مفتاح دلالي)، لا يمسّ الإبراز عند فشله. */
    protected function notify(CreatorProfile $profile, CommunityFeaturedCreator $row): void
    {
        try {
            $user = User::query()->find($profile->user_id);

            if ($user === null) {
                return;
            }

            $this->dispatcher->dispatch(
                $user, NotificationType::FeaturedCreatorSelected, [],
                'featured_creator:'.$profile->id.':'.($row->featured_from?->timestamp ?? 0),
                [], ['creator' => (string) $profile->slug], 'creators.show',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
