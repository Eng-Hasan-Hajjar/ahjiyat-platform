<?php

namespace App\Services\Seasons;

use App\Models\AhjiyatPass;
use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\MissionPool;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use App\Models\SeasonPhase;
use App\Models\User;
use App\Services\OperationalAuditService;
use App\Services\SeasonLifecycleService;
use App\Services\SeasonReadinessService;
use App\Services\TrustSafety\ModerationException;
use App\Services\TrustSafety\PlainText;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E42 - منشئ الموسم: طبقة الإدارة الوحيدة التي تكتب مراحل الموسم ومحتواه وإعلاناته ونافذته وحالة نشره. ليست محرك مهام ولا مكافآت ولا اقتصاد:
 * تنسّق وتجدول وتربط كيانات موجودة فقط (حملة، منافسة، مجموعة مهام، مسار) دون نسخ بياناتها أو منح أي شيء.
 *
 * كل كتابة: صلاحية تُفحص **داخل الخدمة** (لا اعتماد على الواجهة) + معاملة + قفل صف الموسم (يسلسل التعديلات المتزامنة فلا يتجاوز طلبان حدّ المراحل
 * أو يخلقان تداخلًا) + سجل تدقيق + إبطال كاش التعريف (عبر خطافات النماذج). الأخطاء المتوقَّعة = SeasonException برسالة عربية آمنة.
 *
 * القواعد الثابتة:
 *  - المراحل فترات نصف مفتوحة [starts_at, ends_at): بلا تداخل، وضمن نافذة الموسم الفعلية (الطرف الفارغ يتبع الحملة الأساسية).
 *  - ما بدأ لا يُعاد كتابته: بداية موسم/مرحلة بدأت مقفلة، ولا تُحذف مرحلة بدأت، والموسم المؤرشف مجمَّد.
 *  - الإعلان لا يُشعر إلا بقرار إداري صريح (notify_audience) وبصلاحية النشر.
 */
class SeasonBuilderService
{
    private const SCHEDULE_LABELS = [
        'starts_at' => 'بداية الموسم',
        'ends_at' => 'نهاية الموسم',
        'announcement_at' => 'موعد الإعلان',
        'archive_at' => 'موعد الأرشفة',
    ];

    public function __construct(
        protected OperationalAuditService $audit,
        protected SeasonLifecycleService $lifecycle,
        protected SeasonReadinessService $readiness,
    ) {}

    // ───────────────────────────── النافذة والنشر ─────────────────────────────

    /**
     * يتحقق من حقول جدولة الموسم ويعيدها منظَّمة (Carbon|null) للمفاتيح الموجودة في $input فقط. يُستعمل من النماذج (إنشاء/تعديل) ومن updateSchedule.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, Carbon|null>
     *
     * @throws SeasonException
     */
    public function normalizeSchedule(?Season $season, ?Campaign $campaign, array $input): array
    {
        $out = [];

        foreach (self::SCHEDULE_LABELS as $key => $label) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $this->date($input[$key], $label, $season?->{$key});
            }
        }

        if ($out === []) {
            return [];
        }

        $campaign ??= $season?->campaign;
        $starts = array_key_exists('starts_at', $out) ? $out['starts_at'] : $season?->starts_at;
        $ends = array_key_exists('ends_at', $out) ? $out['ends_at'] : $season?->ends_at;
        $announce = array_key_exists('announcement_at', $out) ? $out['announcement_at'] : $season?->announcement_at;
        $archive = array_key_exists('archive_at', $out) ? $out['archive_at'] : $season?->archive_at;
        $effStart = $starts ?? $campaign?->starts_at;
        $effEnd = $ends ?? $campaign?->ends_at;

        if ($effStart !== null && $effEnd !== null && $effEnd->lessThanOrEqualTo($effStart)) {
            throw new SeasonException('schedule_invalid', 'نهاية الموسم يجب أن تكون بعد بدايته.');
        }

        if ($announce !== null && $effStart !== null && $announce->greaterThan($effStart)) {
            throw new SeasonException('schedule_invalid', 'موعد الإعلان يجب ألا يتأخر عن بداية الموسم.');
        }

        if ($archive !== null && $effEnd !== null && $archive->lessThan($effEnd)) {
            throw new SeasonException('schedule_invalid', 'الأرشفة لا تسبق نهاية الموسم.');
        }

        if ($archive !== null && $effStart !== null && $archive->lessThanOrEqualTo($effStart)) {
            throw new SeasonException('schedule_invalid', 'الأرشفة يجب أن تكون بعد بداية الموسم.');
        }

        if ($season !== null && $season->went_live_at !== null) {
            if (array_key_exists('starts_at', $out) && ! $this->sameInstant($out['starts_at'], $season->starts_at)) {
                throw new SeasonException('start_locked', 'الموسم بدأ فعلًا: لا يمكن تغيير تاريخ بدايته.');
            }

            if (array_key_exists('announcement_at', $out) && $out['announcement_at'] !== null && $out['announcement_at']->greaterThan(now())) {
                throw new SeasonException('schedule_invalid', 'الموسم ظاهر للجمهور بالفعل: لا يمكن تأجيل إعلانه.');
            }
        }

        if ($season !== null && $season->exists) {
            $outside = $season->phases()->get()->filter(
                fn (SeasonPhase $p) => ($effStart !== null && $p->starts_at->lt($effStart)) || ($effEnd !== null && $p->ends_at->gt($effEnd))
            );

            if ($outside->isNotEmpty()) {
                throw new SeasonException('window_excludes_phases', 'النافذة الجديدة تُخرج مراحل قائمة عن حدودها. عدّل المراحل أولًا.', $outside->map(fn (SeasonPhase $p) => $p->name)->all());
            }
        }

        return $out;
    }

    /**
     * يتحقق من الثيم ويعيده نظيفًا (قائمة بيضاء). للنماذج: يرفض أي مفتاح غير preset/hero_tagline.
     *
     * @param  array<string, mixed>|null  $theme
     * @return array<string, mixed>
     *
     * @throws SeasonException
     */
    public function normalizeTheme(?array $theme): array
    {
        return SeasonTheme::validatedSeason($theme);
    }

    /** @param array<string, mixed> $data @throws SeasonException */
    public function updateSchedule(User $actor, Season $season, array $data): Season
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $season, $data) {
            $locked = $this->lockedEditable($season);
            $changes = $this->normalizeSchedule($locked, $locked->campaign, $data);
            $changes = array_filter($changes, fn ($v, $k) => ! $this->sameInstant($v, $locked->{$k}), ARRAY_FILTER_USE_BOTH);

            if ($changes === []) {
                return $locked;
            }

            $this->saveSeason($locked, $changes);
            $this->audit->log('season_schedule_updated', $locked, ['fields' => array_keys($changes)], $actor);

            return $locked;
        });
    }

    /** @throws SeasonException */
    public function publish(User $actor, Season $season): Season
    {
        $this->authorize($actor, 'seasons.publish');

        return DB::transaction(function () use ($actor, $season) {
            $locked = $this->lockedEditable($season);

            if ($locked->is_published) {
                return $locked;
            }

            $report = $this->readiness->report($locked);

            if (! $report['ready']) {
                throw new SeasonException('not_ready', 'الموسم غير جاهز للنشر.', $report['blockers']);
            }

            $this->saveSeason($locked, ['is_published' => true]);
            $this->audit->log('season_published', $locked, ['warnings' => count($report['warnings'])], $actor);

            return $locked;
        });
    }

    public function unpublish(User $actor, Season $season): Season
    {
        $this->authorize($actor, 'seasons.publish');

        return DB::transaction(function () use ($actor, $season) {
            $locked = $this->lockedEditable($season);

            if ($locked->is_published) {
                $this->saveSeason($locked, ['is_published' => false]);
                $this->audit->log('season_unpublished', $locked, [], $actor);
            }

            return $locked;
        });
    }

    /**
     * أرشفة يدوية: لا تحذف شيئًا. تنهي الموسم الجاري الآن وتجمّد نافذته الفعلية (تواريخ الحملة تُنسخ لأطراف الموسم الفارغة) فلا يتغير تاريخه
     * إن عُدِّلت الحملة لاحقًا. الموسم القادم/المسودة لا يُؤرشف (لم يبدأ): أوقف نشره أو احذفه.
     *
     * @throws SeasonException
     */
    public function archive(User $actor, Season $season): Season
    {
        $this->authorize($actor, 'seasons.publish');

        return DB::transaction(function () use ($actor, $season) {
            $locked = Season::query()->lockForUpdate()->with('campaign')->find($season->getKey());

            if ($locked === null) {
                throw new SeasonException('not_found', 'الموسم غير موجود.');
            }

            $state = $this->lifecycle->state($locked);

            if ($state === SeasonLifecycleService::STATE_ARCHIVED) {
                return $locked;
            }

            if (! in_array($state, [SeasonLifecycleService::STATE_LIVE, SeasonLifecycleService::STATE_ENDED], true)) {
                throw new SeasonException('not_started', 'لا يُؤرشف إلا موسم بدأ فعلًا (مباشر أو منتهٍ).');
            }

            $now = now();
            $start = $this->lifecycle->windowStart($locked);
            $end = $this->lifecycle->windowEnd($locked);
            $archiveAt = $now;

            if ($end === null || $end->gt($now)) {
                $end = $now;
            }

            if ($start !== null && $end->lessThanOrEqualTo($start)) {
                $end = $start->copy()->addSecond();
                $archiveAt = $end;
            }

            $attrs = ['ends_at' => $end, 'archive_at' => $archiveAt];

            if ($locked->starts_at === null && $start !== null) {
                $attrs['starts_at'] = $start;
            }

            $this->saveSeason($locked, $attrs);
            $this->audit->log('season_archived', $locked, ['was' => $state], $actor);

            return $locked;
        });
    }

    /**
     * يُستدعى من seasons:process لموسم بلغ archive_at بالزمن: يجمّد نافذته الفعلية كي لا يتبدّل تاريخ موسم مؤرشف بتعديل الحملة. لا يغيّر ظهوره.
     * يعيد true إن كتب شيئًا فعلًا (idempotent).
     */
    public function freezeWindow(Season $season): bool
    {
        return DB::transaction(function () use ($season) {
            $locked = Season::query()->lockForUpdate()->with('campaign')->find($season->getKey());

            if ($locked === null || $locked->archive_at === null || $locked->archive_at->gt(now())) {
                return false;
            }

            $attrs = [];
            $start = $locked->starts_at ?? $locked->campaign?->starts_at;
            $end = $locked->ends_at ?? $locked->campaign?->ends_at;
            $end = ($end === null || $end->gt($locked->archive_at)) ? $locked->archive_at : $end;

            if ($locked->starts_at === null && $start !== null) {
                $attrs['starts_at'] = $start;
            }

            if ($locked->ends_at === null || $locked->ends_at->gt($locked->archive_at)) {
                $attrs['ends_at'] = $end;
            }

            if ($attrs === [] || ($start !== null && $end->lessThanOrEqualTo($start))) {
                return false;
            }

            try {
                $this->saveSeason($locked, $attrs);
            } catch (\InvalidArgumentException $e) {
                report($e);

                return false;
            }

            $this->audit->log('season_window_frozen', $locked, ['fields' => array_keys($attrs)], null);

            return true;
        });
    }

    // ───────────────────────────── المراحل ─────────────────────────────

    /** @param array<string, mixed> $data @throws SeasonException */
    public function addPhase(User $actor, Season $season, array $data): SeasonPhase
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $season, $data) {
            $locked = $this->lockedEditable($season);

            if ($locked->phases()->count() >= (int) config('seasons.limits.phases_per_season', 12)) {
                throw new SeasonException('phase_limit', 'بلغ الموسم الحد الأقصى لعدد المراحل.');
            }

            $attrs = $this->phaseAttributes($locked, null, $data);

            try {
                $phase = (new SeasonPhase)->forceFill($attrs + ['season_id' => $locked->getKey()]);
                $phase->save();
            } catch (UniqueConstraintViolationException) {
                throw new SeasonException('phase_invalid', 'معرّف المرحلة الداخلي مستخدم في هذا الموسم.');
            }

            $this->audit->log('season_phase_added', $locked, ['phase' => $phase->public_id, 'name' => $phase->name], $actor);

            return $phase;
        });
    }

    /** @param array<string, mixed> $data @throws SeasonException */
    public function updatePhase(User $actor, SeasonPhase $phase, array $data): SeasonPhase
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $phase, $data) {
            $locked = $this->lockedEditable($phase->season_id);
            $current = SeasonPhase::query()->where('season_id', $locked->getKey())->find($phase->getKey());

            if ($current === null) {
                throw new SeasonException('not_found', 'المرحلة غير موجودة.');
            }

            $attrs = $this->phaseAttributes($locked, $current, $data);
            $current->forceFill($attrs);

            if ($current->isDirty()) {
                $fields = array_keys($current->getDirty());
                $current->save();
                $this->audit->log('season_phase_updated', $locked, ['phase' => $current->public_id, 'fields' => $fields], $actor);
            }

            return $current;
        });
    }

    /** @throws SeasonException */
    public function deletePhase(User $actor, SeasonPhase $phase): void
    {
        $this->authorize($actor, 'seasons.update');

        DB::transaction(function () use ($actor, $phase) {
            $locked = $this->lockedEditable($phase->season_id);
            $current = SeasonPhase::query()->where('season_id', $locked->getKey())->find($phase->getKey());

            if ($current === null) {
                return;
            }

            if ($current->went_live_at !== null) {
                throw new SeasonException('phase_started', 'مرحلة بدأت فعلًا لا تُحذف.');
            }

            $current->delete();
            $this->audit->log('season_phase_deleted', $locked, ['phase' => $current->public_id, 'name' => $current->name], $actor);
        });
    }

    // ───────────────────────────── ربط المحتوى ─────────────────────────────

    /**
     * يربط كيانًا موجودًا بالموسم للعرض والتنسيق فقط (لا نسخ ولا تجميع تقدّم ولا مكافآت).
     *
     * @param  array<string, mixed>  $options  display_label, sort_order, is_featured, season_phase_id
     *
     * @throws SeasonException
     */
    public function addContent(User $actor, Season $season, string $type, int $targetId, array $options = []): SeasonContentItem
    {
        $this->authorize($actor, 'seasons.update');

        if (! isset(SeasonContentItem::COLUMNS[$type])) {
            throw new SeasonException('content_invalid', 'نوع المحتوى غير معروف.');
        }

        return DB::transaction(function () use ($actor, $season, $type, $targetId, $options) {
            $locked = $this->lockedEditable($season);
            $column = SeasonContentItem::COLUMNS[$type];

            $limitKey = match ($type) {
                SeasonContentItem::TYPE_CAMPAIGN => 'extra_campaigns',
                SeasonContentItem::TYPE_COMPETITIVE_EVENT => 'competitions',
                default => 'mission_pools',
            };

            if ($locked->contentItems()->where('content_type', $type)->count() >= (int) config("seasons.limits.{$limitKey}", 6)) {
                throw new SeasonException('content_limit', 'بلغ الموسم الحد الأقصى لهذا النوع من المحتوى.');
            }

            if ($locked->contentItems()->where($column, $targetId)->exists()) {
                throw new SeasonException('already_linked', 'هذا العنصر مرتبط بالموسم بالفعل.');
            }

            $this->assertLinkable($locked, $type, $targetId);

            $item = (new SeasonContentItem)->forceFill([
                'season_id' => $locked->getKey(),
                'content_type' => $type,
                $column => $targetId,
                ...$this->contentOptions($locked, $options, null),
            ]);

            try {
                $item->save();
            } catch (UniqueConstraintViolationException) {
                throw new SeasonException('already_linked', 'هذا العنصر مرتبط بالموسم بالفعل.');
            }

            $this->audit->log('season_content_linked', $locked, ['type' => $type, 'target_id' => $targetId], $actor);

            return $item;
        });
    }

    /** @param array<string, mixed> $options @throws SeasonException */
    public function updateContent(User $actor, SeasonContentItem $item, array $options): SeasonContentItem
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $item, $options) {
            $locked = $this->lockedEditable($item->season_id);
            $current = SeasonContentItem::query()->where('season_id', $locked->getKey())->find($item->getKey());

            if ($current === null) {
                throw new SeasonException('not_found', 'الرابط غير موجود.');
            }

            $current->forceFill($this->contentOptions($locked, $options, $current));

            if ($current->isDirty()) {
                $current->save();
                $this->audit->log('season_content_updated', $locked, ['type' => $current->content_type, 'target_id' => $current->targetId()], $actor);
            }

            return $current;
        });
    }

    public function removeContent(User $actor, SeasonContentItem $item): void
    {
        $this->authorize($actor, 'seasons.update');

        DB::transaction(function () use ($actor, $item) {
            $locked = $this->lockedEditable($item->season_id);
            $current = SeasonContentItem::query()->where('season_id', $locked->getKey())->find($item->getKey());

            if ($current === null) {
                return;
            }

            $current->delete();
            $this->audit->log('season_content_unlinked', $locked, ['type' => $current->content_type, 'target_id' => $current->targetId()], $actor);
        });
    }

    /** يربط مسارًا واحدًا اختياريًا بالموسم (أو يفكّه بـnull). القراءة فقط عند العرض: الموسم لا يملك المسار ولا ملكيته ولا تقدّمه. */
    public function linkPass(User $actor, Season $season, ?int $passId): Season
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $season, $passId) {
            $locked = $this->lockedEditable($season);

            if ($passId !== null && ! AhjiyatPass::query()->whereKey($passId)->exists()) {
                throw new SeasonException('pass_invalid', 'المسار المحدد غير موجود.');
            }

            if ($locked->ahjiyat_pass_id !== $passId) {
                $this->saveSeason($locked, ['ahjiyat_pass_id' => $passId]);
                $this->audit->log('season_pass_linked', $locked, ['pass_id' => $passId], $actor);
            }

            return $locked;
        });
    }

    // ───────────────────────────── الإعلانات ─────────────────────────────

    /**
     * @param  array<string, mixed>  $data  title, body, published_at | publish_now, expires_at, season_phase_id, is_featured, sort_order, notify_audience
     *
     * @throws SeasonException
     */
    public function createAnnouncement(User $actor, Season $season, array $data): SeasonAnnouncement
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $season, $data) {
            $locked = $this->lockedEditable($season);

            if ($locked->announcements()->count() >= (int) config('seasons.limits.announcements_per_season', 60)) {
                throw new SeasonException('announcement_limit', 'بلغ الموسم الحد الأقصى لعدد الإعلانات.');
            }

            $attrs = $this->announcementAttributes($actor, $locked, null, $data);
            $row = (new SeasonAnnouncement)->forceFill($attrs + ['season_id' => $locked->getKey(), 'created_by' => $actor->getKey()]);
            $row->save();

            $this->audit->log('season_announcement_created', $locked, ['announcement' => $row->public_id, 'notify' => $row->notify_audience], $actor);

            return $row;
        });
    }

    /** @param array<string, mixed> $data @throws SeasonException */
    public function updateAnnouncement(User $actor, SeasonAnnouncement $announcement, array $data): SeasonAnnouncement
    {
        $this->authorize($actor, 'seasons.update');

        return DB::transaction(function () use ($actor, $announcement, $data) {
            $locked = $this->lockedEditable($announcement->season_id);
            $current = SeasonAnnouncement::query()->where('season_id', $locked->getKey())->lockForUpdate()->find($announcement->getKey());

            if ($current === null) {
                throw new SeasonException('not_found', 'الإعلان غير موجود.');
            }

            $current->forceFill($this->announcementAttributes($actor, $locked, $current, $data));

            if ($current->isDirty()) {
                $fields = array_keys($current->getDirty());
                $current->save();
                $this->audit->log('season_announcement_updated', $locked, ['announcement' => $current->public_id, 'fields' => $fields], $actor);
            }

            return $current;
        });
    }

    public function deleteAnnouncement(User $actor, SeasonAnnouncement $announcement): void
    {
        $this->authorize($actor, 'seasons.update');

        DB::transaction(function () use ($actor, $announcement) {
            $locked = $this->lockedEditable($announcement->season_id);
            $current = SeasonAnnouncement::query()->where('season_id', $locked->getKey())->find($announcement->getKey());

            if ($current === null) {
                return;
            }

            $current->delete();
            $this->audit->log('season_announcement_deleted', $locked, ['announcement' => $current->public_id], $actor);
        });
    }

    // ───────────────────────────── داخلي ─────────────────────────────

    protected function authorize(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new SeasonException('forbidden', 'لا تملك صلاحية تنفيذ هذا الإجراء.');
        }
    }

    /** قفل صف الموسم (يسلسل التعديلات المتزامنة). الموسم المؤرشف مجمَّد. */
    protected function lockedEditable(Season|int $season): Season
    {
        $id = $season instanceof Season ? $season->getKey() : $season;
        $locked = Season::query()->lockForUpdate()->with('campaign')->find($id);

        if ($locked === null) {
            throw new SeasonException('not_found', 'الموسم غير موجود.');
        }

        if ($this->lifecycle->state($locked) === SeasonLifecycleService::STATE_ARCHIVED) {
            throw new SeasonException('season_archived', 'الموسم مؤرشف ومجمَّد: لا تعديل عليه.');
        }

        return $locked;
    }

    /** @param array<string, mixed> $attrs */
    protected function saveSeason(Season $season, array $attrs): void
    {
        try {
            $season->fill($attrs)->save();
        } catch (\InvalidArgumentException $e) {
            throw new SeasonException('schedule_invalid', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function phaseAttributes(Season $season, ?SeasonPhase $existing, array $data): array
    {
        $max = (int) config('seasons.limits.phase_name_max', 120);

        try {
            $name = array_key_exists('name', $data) || $existing === null
                ? PlainText::clean($data['name'] ?? null, 1, $max, 'اسم المرحلة')
                : $existing->name;
            $description = array_key_exists('description', $data)
                ? PlainText::optional($data['description'], (int) config('seasons.limits.phase_description_max', 600), 'وصف المرحلة')
                : $existing?->description;
        } catch (ModerationException $e) {
            throw new SeasonException('phase_invalid', $e->getMessage());
        }

        $starts = array_key_exists('starts_at', $data) ? $this->date($data['starts_at'], 'بداية المرحلة', $existing?->starts_at) : $existing?->starts_at;
        $ends = array_key_exists('ends_at', $data) ? $this->date($data['ends_at'], 'نهاية المرحلة', $existing?->ends_at) : $existing?->ends_at;

        if ($starts === null || $ends === null) {
            throw new SeasonException('phase_invalid', 'حدّد بداية المرحلة ونهايتها.');
        }

        if ($ends->lessThanOrEqualTo($starts)) {
            throw new SeasonException('phase_invalid', 'نهاية المرحلة يجب أن تكون بعد بدايتها.');
        }

        if ($existing?->went_live_at !== null && ! $this->sameInstant($starts, $existing->starts_at)) {
            throw new SeasonException('phase_started', 'مرحلة بدأت فعلًا: لا يمكن تغيير تاريخ بدايتها.');
        }

        $winStart = $this->lifecycle->windowStart($season);
        $winEnd = $this->lifecycle->windowEnd($season);

        if (($winStart !== null && $starts->lt($winStart)) || ($winEnd !== null && $ends->gt($winEnd))) {
            throw new SeasonException('phase_outside_window', 'المرحلة يجب أن تقع ضمن نافذة الموسم.');
        }

        $others = SeasonPhase::query()->where('season_id', $season->getKey())
            ->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))->get();

        foreach ($others as $other) {
            if (SeasonPhaseResolver::overlaps($starts, $ends, $other->starts_at, $other->ends_at)) {
                throw new SeasonException('phase_overlap', 'تتداخل هذه المرحلة مع المرحلة «'.$other->name.'». الفترات نصف مفتوحة: يمكن أن تبدأ مرحلة لحظة انتهاء سابقتها.');
            }
        }

        $key = $existing?->internal_key;

        if ($existing === null) {
            $key = $this->phaseKey($data['internal_key'] ?? null, $season);
        } elseif (isset($data['internal_key']) && $data['internal_key'] !== $existing->internal_key) {
            throw new SeasonException('phase_invalid', 'المعرّف الداخلي للمرحلة ثابت.');
        }

        $theme = array_key_exists('theme', $data) ? SeasonTheme::validatedPhase((array) $data['theme']) : $existing?->theme_config;

        return [
            'internal_key' => $key,
            'name' => $name,
            'description' => $description,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'sort_order' => $this->smallInt($data['sort_order'] ?? $existing?->sort_order ?? 0, 'ترتيب المرحلة'),
            'is_featured' => (bool) ($data['is_featured'] ?? $existing?->is_featured ?? false),
            'theme_config' => $theme,
        ];
    }

    protected function phaseKey(mixed $value, Season $season): string
    {
        $key = Str::lower(trim((string) $value));

        if ($key === '') {
            do {
                $key = 'phase-'.Str::lower(Str::random(8));
            } while (SeasonPhase::query()->where('season_id', $season->getKey())->where('internal_key', $key)->exists());

            return $key;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $key)) {
            throw new SeasonException('phase_invalid', 'المعرّف الداخلي حروف إنجليزية صغيرة وأرقام وشرطات فقط.');
        }

        return $key;
    }

    protected function assertLinkable(Season $season, string $type, int $targetId): void
    {
        if ($type === SeasonContentItem::TYPE_CAMPAIGN) {
            if (! Campaign::query()->whereKey($targetId)->exists()) {
                throw new SeasonException('content_invalid', 'الحملة غير موجودة.');
            }

            if ($targetId === (int) $season->campaign_id) {
                throw new SeasonException('content_invalid', 'هذه هي الحملة الأساسية للموسم بالفعل.');
            }

            if (Season::query()->where('campaign_id', $targetId)->exists()) {
                throw new SeasonException('content_invalid', 'هذه الحملة هي الحملة الأساسية لموسم آخر.');
            }

            if (SeasonContentItem::query()->where('campaign_id', $targetId)->exists()) {
                throw new SeasonException('content_invalid', 'هذه الحملة مرتبطة بموسم آخر.');
            }

            return;
        }

        if ($type === SeasonContentItem::TYPE_COMPETITIVE_EVENT) {
            $event = CompetitiveEvent::query()->find($targetId);

            if ($event === null) {
                throw new SeasonException('content_invalid', 'المنافسة غير موجودة.');
            }

            if ($event->status === CompetitiveEvent::STATUS_CANCELLED) {
                throw new SeasonException('content_invalid', 'لا تُربط منافسة ملغاة بموسم.');
            }

            return;
        }

        if (! MissionPool::query()->whereKey($targetId)->exists()) {
            throw new SeasonException('content_invalid', 'مجموعة المهام غير موجودة.');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function contentOptions(Season $season, array $options, ?SeasonContentItem $existing): array
    {
        try {
            $label = array_key_exists('display_label', $options)
                ? PlainText::optional($options['display_label'], (int) config('seasons.limits.label_max', 120), 'عنوان العرض')
                : $existing?->display_label;
        } catch (ModerationException $e) {
            throw new SeasonException('content_invalid', $e->getMessage());
        }

        $phaseId = array_key_exists('season_phase_id', $options) ? ($options['season_phase_id'] ?: null) : $existing?->season_phase_id;

        if ($phaseId !== null && ! SeasonPhase::query()->where('season_id', $season->getKey())->whereKey($phaseId)->exists()) {
            throw new SeasonException('content_invalid', 'المرحلة المحددة لا تتبع هذا الموسم.');
        }

        return [
            'display_label' => $label,
            'sort_order' => $this->smallInt($options['sort_order'] ?? $existing?->sort_order ?? 0, 'الترتيب'),
            'is_featured' => (bool) ($options['is_featured'] ?? $existing?->is_featured ?? false),
            'season_phase_id' => $phaseId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function announcementAttributes(User $actor, Season $season, ?SeasonAnnouncement $existing, array $data): array
    {
        try {
            $title = array_key_exists('title', $data) || $existing === null
                ? PlainText::clean($data['title'] ?? null, 1, (int) config('seasons.limits.announcement_title_max', 160), 'عنوان الإعلان')
                : $existing->title;
            $body = array_key_exists('body', $data) || $existing === null
                ? PlainText::clean($data['body'] ?? null, 1, (int) config('seasons.limits.announcement_body_max', 2000), 'نص الإعلان')
                : $existing->body;
        } catch (ModerationException $e) {
            throw new SeasonException('announcement_invalid', $e->getMessage());
        }

        $publishedAt = $existing?->published_at;

        if (! empty($data['publish_now'])) {
            $publishedAt = $existing?->published_at ?? now();
        } elseif (array_key_exists('published_at', $data)) {
            $publishedAt = $this->date($data['published_at'], 'موعد النشر', $existing?->published_at);
        }

        $expiresAt = array_key_exists('expires_at', $data) ? $this->date($data['expires_at'], 'موعد الانتهاء', $existing?->expires_at) : $existing?->expires_at;

        if ($publishedAt !== null && $expiresAt !== null && $expiresAt->lessThanOrEqualTo($publishedAt)) {
            throw new SeasonException('announcement_invalid', 'انتهاء الإعلان يجب أن يكون بعد نشره.');
        }

        $audience = array_key_exists('notify_audience', $data) ? ($data['notify_audience'] ?: null) : $existing?->notify_audience;

        if ($audience !== null && ! in_array($audience, (array) config('seasons.notifications.audiences', []), true)) {
            throw new SeasonException('announcement_invalid', 'جمهور الإشعار غير مسموح.');
        }

        $audienceChanged = $audience !== $existing?->notify_audience;

        if ($audienceChanged && $audience !== null) {
            $this->authorize($actor, 'seasons.publish'); // الإشعار قرار نشر لا تحرير
        }

        if ($audience !== null && $publishedAt === null) {
            throw new SeasonException('announcement_invalid', 'حدّد موعد نشر الإعلان قبل طلب إشعار به.');
        }

        if ($existing?->notified_at !== null && ($audienceChanged || ! $this->sameInstant($publishedAt, $existing->published_at))) {
            throw new SeasonException('notify_locked', 'أُرسل إشعار هذا الإعلان بالفعل: لا يمكن تغيير جمهوره أو موعد نشره.');
        }

        $phaseId = array_key_exists('season_phase_id', $data) ? ($data['season_phase_id'] ?: null) : $existing?->season_phase_id;

        if ($phaseId !== null && ! SeasonPhase::query()->where('season_id', $season->getKey())->whereKey($phaseId)->exists()) {
            throw new SeasonException('announcement_invalid', 'المرحلة المحددة لا تتبع هذا الموسم.');
        }

        return [
            'title' => $title,
            'body' => $body,
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'season_phase_id' => $phaseId,
            'is_featured' => (bool) ($data['is_featured'] ?? $existing?->is_featured ?? false),
            'sort_order' => $this->smallInt($data['sort_order'] ?? $existing?->sort_order ?? 0, 'الترتيب'),
            'notify_audience' => $audience,
        ];
    }

    /**
     * يحوّل مدخل تاريخ إلى Carbon. حقول الإدارة بدقة الدقيقة (بلا ثوانٍ): إن طابق المدخل القيمة الحالية حتى الدقيقة تُرجَع القيمة الحالية بثوانيها كما هي
     * فلا يُعدّ حفظ نموذج دون تغيير «تعديلًا» ولا يصطدم بأقفال ما بدأ.
     */
    protected function date(mixed $value, string $label, ?CarbonInterface $existing = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new SeasonException('schedule_invalid', "تاريخ غير صالح: {$label}.");
        }

        if ($existing !== null && $parsed->copy()->startOfMinute()->equalTo(Carbon::instance($existing)->startOfMinute())) {
            return Carbon::instance($existing);
        }

        return $parsed;
    }

    protected function sameInstant(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->getTimestamp() === $b->getTimestamp();
    }

    protected function smallInt(mixed $value, string $label): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 65535) {
            throw new SeasonException('phase_invalid', "{$label} رقم بين 0 و65535.");
        }

        return (int) $value;
    }
}
