<?php

use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\OperationalAuditLog;
use App\Models\Season;
use App\Models\SeasonAnnouncement;
use App\Models\SeasonContentItem;
use Carbon\Carbon;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/*
| E42 / ربط المحتوى (حملات إضافية، منافسات، مجموعات مهام، مسار) والإعلانات. الربط عرض وتنسيق فقط: لا نسخ، ولا مكافآت، ولا سلطة على التقدّم.
*/

beforeEach(fn () => e42_seed());
afterEach(fn () => Carbon::setTestNow());

// ───────────────────────────── ربط المحتوى ─────────────────────────────

test('E42-CI-01: an extra campaign, a competition and a mission pool can be linked, ordered, labelled and unlinked', function () {
    $season = e42_live();
    $admin = e42_admin();
    $extra = e42_campaign();
    $event = e17Event();
    $pool = e34_pool(e34_quests(2));
    $b = e42_builder();

    $c = $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id, ['display_label' => 'مغامرة جانبية', 'sort_order' => 2]);
    $e = $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id, ['is_featured' => true]);
    $p = $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, $pool->id);

    expect($season->contentItems()->count())->toBe(3)
        ->and($c->display_label)->toBe('مغامرة جانبية')
        ->and($c->targetId())->toBe($extra->id)
        ->and($e->is_featured)->toBeTrue()
        ->and($p->mission_pool_id)->toBe($pool->id)
        ->and(OperationalAuditLog::query()->where('action', 'season_content_linked')->count())->toBe(3);

    $b->updateContent($admin, $c, ['display_label' => 'اسم جديد', 'sort_order' => 0]);
    expect($c->fresh()->display_label)->toBe('اسم جديد');

    $b->removeContent($admin, $e);
    $b->removeContent($admin, $e); // مكرَّر آمن
    expect($season->contentItems()->count())->toBe(2)
        ->and(CompetitiveEvent::query()->whereKey($event->id)->exists())->toBeTrue(); // الكيان نفسه لا يُمسّ
});

test('E42-CI-02: linking never copies or mutates the linked entity (display-only)', function () {
    $season = e42_live();
    $event = e17Event();
    $extra = e42_campaign(['starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-31 00:00:00']);
    $eventBefore = $event->fresh()->toArray();
    $campaignBefore = $extra->fresh()->toArray();

    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, $event->id);
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);

    expect($event->fresh()->toArray())->toBe($eventBefore)
        ->and($extra->fresh()->toArray())->toBe($campaignBefore);
});

test('E42-CI-03: link rules - no duplicates, no primary campaign, no other season\'s campaign, no cancelled competition, unknown ids refused', function () {
    $season = e42_live();
    $other = e42_live();
    $admin = e42_admin();
    $extra = e42_campaign();
    $b = e42_builder();

    $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);

    expect(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id)))->toBe('already_linked')
        ->and(e42_reason(fn () => $b->addContent($admin, $other, SeasonContentItem::TYPE_CAMPAIGN, $extra->id)))->toBe('content_invalid')   // مرتبطة بموسم آخر
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $season->campaign_id)))->toBe('content_invalid') // أساسية
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, $other->campaign_id)))->toBe('content_invalid') // أساسية لموسم آخر
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, 999999)))->toBe('content_invalid')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, 999999)))->toBe('content_invalid')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, 999999)))->toBe('content_invalid')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, 'banana', 1)))->toBe('content_invalid')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, e17Event([], CompetitiveEvent::STATUS_CANCELLED)->id)))->toBe('content_invalid');
});

test('E42-CI-04: per-type limits are enforced', function () {
    config(['seasons.limits.extra_campaigns' => 2, 'seasons.limits.competitions' => 1, 'seasons.limits.mission_pools' => 1]);
    $season = e42_live();
    $admin = e42_admin();
    $b = e42_builder();

    $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, e17Event()->id);
    $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, e34_pool(e34_quests(1))->id);

    expect(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id)))->toBe('content_limit')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_COMPETITIVE_EVENT, e17Event()->id)))->toBe('content_limit')
        ->and(e42_reason(fn () => $b->addContent($admin, $season, SeasonContentItem::TYPE_MISSION_POOL, e34_pool(e34_quests(1))->id)))->toBe('content_limit');
});

test('E42-CI-05: a link may point at a phase of the same season only', function () {
    $season = e42_live();
    $other = e42_live();
    $mine = e42_phase($season, 'مرحلتي', '2026-12-01 00:00:00', '2026-12-05 00:00:00');
    $theirs = e42_phase($other, 'مرحلتهم', '2026-12-01 00:00:00', '2026-12-05 00:00:00');
    $admin = e42_admin();

    expect(e42_builder()->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id, ['season_phase_id' => $mine->id])->season_phase_id)->toBe($mine->id)
        ->and(e42_reason(fn () => e42_builder()->addContent($admin, $season, SeasonContentItem::TYPE_CAMPAIGN, e42_campaign()->id, ['season_phase_id' => $theirs->id])))->toBe('content_invalid');
});

test('E42-CI-06: link identity is immutable and the row refuses a second target', function () {
    $season = e42_live();
    $extra = e42_campaign();
    $link = e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);

    expect(fn () => $link->forceFill(['campaign_id' => e42_campaign()->id])->save())->toThrow(\LogicException::class)
        ->and(fn () => (new SeasonContentItem)->forceFill(['season_id' => $season->id, 'content_type' => 'campaign', 'campaign_id' => e42_campaign()->id, 'mission_pool_id' => e34_pool(e34_quests(1))->id])->save())->toThrow(\InvalidArgumentException::class)
        ->and(fn () => SeasonContentItem::query()->create(['season_id' => $season->id]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('E42-CI-07: the optional pass link validates existence, is nullable, and does not change the pass', function () {
    $season = e42_live();
    $pass = e40_pass();
    $before = $pass->fresh()->toArray();
    $admin = e42_admin();

    expect(e42_reason(fn () => e42_builder()->linkPass($admin, $season, 999999)))->toBe('pass_invalid');

    e42_builder()->linkPass($admin, $season, $pass->id);
    expect($season->fresh()->ahjiyat_pass_id)->toBe($pass->id)
        ->and($pass->fresh()->toArray())->toBe($before);

    e42_builder()->linkPass($admin, $season, null);
    expect($season->fresh()->ahjiyat_pass_id)->toBeNull();
});

// ───────────────────────────── الإعلانات ─────────────────────────────

test('E42-AN-01: announcement drafts, immediate publish and scheduled publish', function () {
    $season = e42_live();
    $admin = e42_admin();
    $b = e42_builder();

    $draft = $b->createAnnouncement($admin, $season, ['title' => 'مسودة', 'body' => 'نص']);
    $now = $b->createAnnouncement($admin, $season, ['title' => 'الآن', 'body' => 'نص', 'publish_now' => true]);
    $later = $b->createAnnouncement($admin, $season, ['title' => 'لاحقًا', 'body' => 'نص', 'published_at' => '2026-12-15 09:00:00']);

    expect($draft->published_at)->toBeNull()
        ->and($now->published_at->toDateTimeString())->toBe('2026-12-10 12:00:00')
        ->and($later->published_at->toDateTimeString())->toBe('2026-12-15 09:00:00')
        ->and($draft->public_id)->toHaveLength(26)
        ->and($draft->created_by)->toBe($admin->id);
});

test('E42-AN-02: announcement text is plain text with bounds; expiry must follow publishing; phase must belong to the season', function () {
    $season = e42_live();
    $other = e42_live();
    $theirs = e42_phase($other, 'غريبة', '2026-12-01 00:00:00', '2026-12-05 00:00:00');
    $admin = e42_admin();
    $b = e42_builder();

    expect(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => '<b>x</b>', 'body' => 'نص'])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 'عنوان', 'body' => '<script>alert(1)</script>'])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => '', 'body' => 'نص'])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => str_repeat('ا', 161), 'body' => 'نص'])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 'ع', 'body' => str_repeat('ا', 2001)])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 'ع', 'body' => 'ن', 'published_at' => '2026-12-12', 'expires_at' => '2026-12-11'])))->toBe('announcement_invalid')
        ->and(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 'ع', 'body' => 'ن', 'season_phase_id' => $theirs->id])))->toBe('announcement_invalid');
});

test('E42-AN-03: requesting a notification is a publish decision - needs seasons.publish, a publish time and a whitelisted audience', function () {
    $season = e42_live();
    $editor = e42_staff(['seasons.view', 'seasons.update']);
    $publisher = e42_staff(['seasons.view', 'seasons.update', 'seasons.publish']);
    $b = e42_builder();
    $data = ['title' => 'خبر', 'body' => 'نص', 'published_at' => '2026-12-11 10:00:00'];

    expect(e42_reason(fn () => $b->createAnnouncement($editor, $season, $data + ['notify_audience' => 'all'])))->toBe('forbidden')
        ->and(e42_reason(fn () => $b->createAnnouncement($publisher, $season, ['title' => 'خبر', 'body' => 'نص', 'notify_audience' => 'all'])))->toBe('announcement_invalid') // بلا موعد نشر
        ->and(e42_reason(fn () => $b->createAnnouncement($publisher, $season, $data + ['notify_audience' => 'everyone'])))->toBe('announcement_invalid')
        ->and($b->createAnnouncement($editor, $season, $data)->notify_audience)->toBeNull() // المحرّر ينشر بلا إشعار
        ->and($b->createAnnouncement($publisher, $season, $data + ['notify_audience' => 'participants'])->notify_audience)->toBe('participants');
});

test('E42-AN-04: once notified, the audience and publish time are locked', function () {
    $season = e42_live();
    $admin = e42_admin();
    $row = e42_builder()->createAnnouncement($admin, $season, ['title' => 'خبر', 'body' => 'نص', 'published_at' => '2026-12-11 10:00:00', 'notify_audience' => 'participants']);
    SeasonAnnouncement::query()->whereKey($row->id)->update(['notified_at' => now()]);
    $row = $row->fresh();

    expect(e42_reason(fn () => e42_builder()->updateAnnouncement($admin, $row, ['notify_audience' => 'all'])))->toBe('notify_locked')
        ->and(e42_reason(fn () => e42_builder()->updateAnnouncement($admin, $row, ['published_at' => '2026-12-12 10:00:00'])))->toBe('notify_locked')
        ->and(e42_builder()->updateAnnouncement($admin, $row, ['title' => 'عنوان معدّل'])->title)->toBe('عنوان معدّل');
});

test('E42-AN-05: announcement limit, update no-op and delete are audited', function () {
    config(['seasons.limits.announcements_per_season' => 2]);
    $season = e42_live();
    $admin = e42_admin();
    $b = e42_builder();

    $a = $b->createAnnouncement($admin, $season, ['title' => 'أ', 'body' => 'ن']);
    $b->createAnnouncement($admin, $season, ['title' => 'ب', 'body' => 'ن']);

    expect(e42_reason(fn () => $b->createAnnouncement($admin, $season, ['title' => 'ج', 'body' => 'ن'])))->toBe('announcement_limit');

    $count = OperationalAuditLog::query()->count();
    $b->updateAnnouncement($admin, $a, ['title' => 'أ']);
    expect(OperationalAuditLog::query()->count())->toBe($count);

    $b->deleteAnnouncement($admin, $a);
    $b->deleteAnnouncement($admin, $a);
    expect(SeasonAnnouncement::query()->count())->toBe(1)
        ->and(OperationalAuditLog::query()->where('action', 'season_announcement_deleted')->count())->toBe(1);
});

test('E42-AN-06: deleting a phase keeps its announcements (nullOnDelete) and deleting the season cascades its own rows only', function () {
    $season = e42_season(['window' => ['2026-12-20 00:00:00', '2026-12-30 00:00:00']]);
    $phase = e42_phase($season, 'مرحلة', '2026-12-21 00:00:00', '2026-12-22 00:00:00');
    $note = e42_builder()->createAnnouncement(e42_admin(), $season, ['title' => 'خبر', 'body' => 'نص', 'season_phase_id' => $phase->id]);
    $extra = e42_campaign();
    e42_builder()->addContent(e42_admin(), $season, SeasonContentItem::TYPE_CAMPAIGN, $extra->id);

    e42_builder()->deletePhase(e42_admin(), $phase);
    expect($note->fresh()->season_phase_id)->toBeNull();

    $season->fresh()->delete(); // لم يبدأ بعد ⇒ يُحذف
    expect(SeasonAnnouncement::query()->count())->toBe(0)
        ->and(SeasonContentItem::query()->count())->toBe(0)
        ->and(Campaign::query()->whereKey($extra->id)->exists())->toBeTrue(); // الحملة المرتبطة تبقى
});
