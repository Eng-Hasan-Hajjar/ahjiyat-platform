<?php

use App\Models\OperationalAuditLog;
use App\Models\UserCollection;
use App\Models\UserCollectionReport;
use App\Models\UserCollectionSave;
use App\Services\Collections\CollectionModerationService;
use App\Services\Collections\CollectionSaveService;
use App\Services\Engagement\EngagementException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e35_seed());

/* ===================== AM5. MODERATION ===================== */

test('E35-031: a verified user reports a public collection with a closed-list reason', function () {
    $c = e35_public(null, 2, 'مجموعة يُبلَّغ عنها');
    $reporter = e16User();

    $this->actingAs($reporter)->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'])->assertRedirect(route('collections.public.show', $c->public_id))->assertSessionHas('success');
    $r = UserCollectionReport::query()->firstOrFail();
    expect($r->reason)->toBe('spam')->and($r->status)->toBe('open')->and((int) $r->reporter_id)->toBe($reporter->id)->and((int) $r->open_marker)->toBe(1);

    // سبب خارج القائمة المغلقة مرفوض
    $this->actingAs(e16User())->post(route('collections.public.report', $c->public_id), ['reason' => 'free text <b>x</b>'])->assertSessionHasErrors('reason');
    expect(UserCollectionReport::query()->count())->toBe(1);
});

test('E35-032: an owner cannot report their own collection', function () {
    $owner = e16User();
    $c = e35_public($owner, 2);

    $this->actingAs($owner)->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'])->assertRedirect()->assertSessionHas('error');
    expect(UserCollectionReport::query()->count())->toBe(0);
});

test('E35-033: a duplicate open report by the same user is prevented', function () {
    $c = e35_public(null, 2);
    $reporter = e16User();

    $this->actingAs($reporter)->post(route('collections.public.report', $c->public_id), ['reason' => 'spam']);
    $this->actingAs($reporter)->post(route('collections.public.report', $c->public_id), ['reason' => 'misleading'])->assertSessionHas('error');
    expect(UserCollectionReport::query()->count())->toBe(1);
});

test('E35-034: users without collections.moderate are denied (hide, restore, dismiss, queue)', function () {
    $c = e35_public(null, 2);
    $plain = e16User();
    $manager = e35_manager();   // يعرض المجموعات لكن لا يشرف

    foreach ([$plain, $manager] as $who) {
        expect(fn () => app(CollectionModerationService::class)->hide($c->fresh(), $who, 'سبب كافٍ للاختبار'))->toThrow(HttpException::class);
    }

    $this->actingAs($plain)->get('/admin/user-collection-reports')->assertForbidden();
    $this->actingAs($plain)->get('/admin/user-collections')->assertForbidden();
    expect($c->fresh()->is_moderation_hidden)->toBeFalse();
});

test('E35-035: a moderator hides a collection (reason required; open reports are closed as actioned)', function () {
    $c = e35_public(null, 2);
    $mod = e35_moderator();
    $this->actingAs(e16User())->post(route('collections.public.report', $c->public_id), ['reason' => 'inappropriate']);

    expect(fn () => app(CollectionModerationService::class)->hide($c->fresh(), $mod, 'ق'))->toThrow(EngagementException::class);

    app(CollectionModerationService::class)->hide($c->fresh(), $mod, 'عنوان مضلِّل لا يطابق المحتوى');
    $fresh = $c->fresh();
    expect($fresh->is_moderation_hidden)->toBeTrue()->and($fresh->moderation_reason)->toBe('عنوان مضلِّل لا يطابق المحتوى')->and((int) $fresh->moderation_hidden_by)->toBe($mod->id);
    expect(UserCollectionReport::query()->firstOrFail()->status)->toBe('actioned');
    // لا إعادة كتابة لنص المالك
    expect($fresh->title)->toBe($c->title);
});

test('E35-036: a hidden collection is inaccessible publicly (guest, other user and the owner on the public URL) while the owner sees a banner on the owner route', function () {
    $owner = e16User();
    $c = e35_public($owner, 2, 'ستُخفى');
    e35_hide_collection($c);

    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
    $this->actingAs(e16User())->get(route('collections.public.show', $c->public_id))->assertNotFound();
    $this->actingAs($owner)->get(route('collections.public.show', $c->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('collections.public.save', $c->public_id))->assertNotFound();
    $this->actingAs(e16User())->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'])->assertNotFound();
    $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->assertSee('أخفى فريق الإشراف')->assertSee('مخالفة تجريبية للاختبار.');
});

test('E35-037: a hidden collection disappears from Search immediately', function () {
    $c = e35_public(null, 2, 'ستختفي من البحث');
    $this->get(route('search.index', ['q' => 'ستختفي', 'type' => 'collections']))->assertSee('ستختفي من البحث');

    e35_hide_collection($c);
    $this->get(route('search.index', ['q' => 'ستختفي', 'type' => 'collections']))->assertDontSee('ستختفي من البحث');
});

test('E35-038: a hidden collection disappears from Discover immediately even while the pool snapshot is cached', function () {
    $c = e35_public(null, 3, 'ستختفي من الاكتشاف');
    Cache::flush();
    $this->get(route('discover.index'))->assertSee('ستختفي من الاكتشاف');   // يدفئ لقطة الكاش

    // تعطيل التفريغ الصريح لإثبات أن الفحص الحيّ هو الحارس لا الكاش: نعيد وضع اللقطة القديمة بعد الإخفاء
    $pool = \App\Services\Collections\CollectionDiscovery::class;
    $snapshot = app($pool)->pool();
    e35_hide_collection($c);
    Cache::put($pool::CACHE_KEY, $snapshot, 300);

    $this->get(route('discover.index'))->assertOk()->assertDontSee('ستختفي من الاكتشاف');
});

test('E35-039: the owner cannot bypass a moderator hide by changing visibility (only private is allowed)', function () {
    $owner = e16User();
    $c = e35_public($owner, 2);
    e35_hide_collection($c);

    // «غير مدرجة» ترفضها الخدمة؛ إعادة «عامة» (الحالة نفسها) لا تغيّر شيئًا ولا ترفع الإخفاء.
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'unlisted'])->assertRedirect()->assertSessionHas('error');
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'public', 'acknowledge' => '1'])->assertRedirect();
    expect($c->fresh()->is_moderation_hidden)->toBeTrue()->and($c->fresh()->visibility)->toBe('public');

    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
    // الرجوع إلى «خاصة» مسموح، لكنه لا يرفع الإخفاء
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'private'])->assertRedirect();
    expect($c->fresh()->visibility)->toBe('private')->and($c->fresh()->is_moderation_hidden)->toBeTrue();
});

test('E35-040: a moderator restores a hidden collection (back to the owner\'s visibility)', function () {
    $c = e35_public(null, 2, 'ستعود');
    e35_hide_collection($c);
    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();

    app(CollectionModerationService::class)->restore($c->fresh(), e35_moderator());
    $fresh = $c->fresh();
    expect($fresh->is_moderation_hidden)->toBeFalse()->and($fresh->moderation_reason)->toBeNull()->and($fresh->visibility)->toBe('public');
    $this->get(route('collections.public.show', $c->public_id))->assertOk()->assertSee('ستعود');
    // استعادة غير مخفية خطأ
    expect(fn () => app(CollectionModerationService::class)->restore($fresh, e35_moderator()))->toThrow(EngagementException::class);
});

test('E35-041: audit events are recorded with the actor (hidden, restored, report dismissed, report actioned)', function () {
    $c = e35_public(null, 2);
    $mod = e35_moderator();
    $svc = app(CollectionModerationService::class);

    $a = e16User();
    $b = e16User();
    $this->actingAs($a)->post(route('collections.public.report', $c->public_id), ['reason' => 'spam']);
    $report1 = UserCollectionReport::query()->where('reporter_id', $a->id)->firstOrFail();
    $svc->dismiss($report1, $mod, 'لا مخالفة');

    $this->actingAs($b)->post(route('collections.public.report', $c->public_id), ['reason' => 'misleading']);
    $report2 = UserCollectionReport::query()->where('reporter_id', $b->id)->firstOrFail();
    $svc->action($report2, $mod, 'عولج');

    $svc->hide($c->fresh(), $mod, 'سبب الإخفاء للتدقيق');
    $svc->restore($c->fresh(), $mod);

    foreach (['collection_report_dismissed', 'collection_report_actioned', 'collection_hidden', 'collection_restored'] as $action) {
        $row = OperationalAuditLog::query()->where('action', $action)->first();
        expect($row)->not->toBeNull($action)->and((int) $row->actor_user_id)->toBe($mod->id);
    }
});

/* ===================== AM6. SAVES ===================== */

test('E35-042: a user saves a public collection (and an unlisted one by its link)', function () {
    $c = e35_public(null, 2);
    $u = e35_collection(e16User(), e35_works(1), 'unlisted', 'غير مدرجة للحفظ');
    $saver = e16User();

    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id))->assertRedirect(route('collections.public.show', $c->public_id))->assertSessionHas('success');
    $this->actingAs($saver)->post(route('collections.public.save', $u->public_id))->assertRedirect();
    expect(UserCollectionSave::query()->where('user_id', $saver->id)->count())->toBe(2);
});

test('E35-043: an owner cannot save their own collection', function () {
    $owner = e16User();
    $c = e35_public($owner, 2);

    $this->actingAs($owner)->post(route('collections.public.save', $c->public_id))->assertRedirect()->assertSessionHas('error');
    expect(UserCollectionSave::query()->count())->toBe(0)->and((int) $c->fresh()->saves_count)->toBe(0);
});

test('E35-044: saving twice is safe (idempotent, counter not doubled)', function () {
    $c = e35_public(null, 2);
    $saver = e16User();

    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));
    expect(UserCollectionSave::query()->count())->toBe(1)->and((int) $c->fresh()->saves_count)->toBe(1);
});

test('E35-045: unsave works (and is safe when nothing is saved)', function () {
    $c = e35_public(null, 2);
    $saver = e16User();
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));

    $this->actingAs($saver)->delete(route('collections.public.unsave', $c->public_id))->assertRedirect();
    expect(UserCollectionSave::query()->count())->toBe(0)->and((int) $c->fresh()->saves_count)->toBe(0);
    $this->actingAs($saver)->delete(route('collections.public.unsave', $c->public_id))->assertRedirect();
    expect((int) $c->fresh()->saves_count)->toBe(0);
});

test('E35-046: saves_count equals the distinct saver rows and the reconciler repairs drift', function () {
    $c = e35_public(null, 2);
    foreach (range(1, 3) as $i) {
        app(CollectionSaveService::class)->save(e16User(), $c->public_id);
    }
    expect((int) $c->fresh()->saves_count)->toBe(3);

    \Illuminate\Support\Facades\DB::table('user_collections')->where('id', $c->id)->update(['saves_count' => 9]);
    $this->artisan('community:reconcile-engagement', ['--dry-run' => true])->assertFailed();
    $this->artisan('community:reconcile-engagement')->assertSuccessful();
    expect((int) $c->fresh()->saves_count)->toBe(3);
});

test('E35-047: saver identities are private (no page lists them, not even to the owner)', function () {
    $owner = e16User();
    $c = e35_public($owner, 2, 'هويات الحافظين');
    $saver = e16User(['name' => 'حافظ سري الاسم']);
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));

    foreach ([$this->actingAs($owner)->get(route('collections.show', $c->public_id)), $this->actingAs($owner)->get(route('collections.public.show', $c->public_id)), $this->get(route('collections.public.show', $c->public_id))] as $r) {
        expect($r->assertOk()->getContent())->not->toContain('حافظ سري الاسم')->and($r->getContent())->toContain('1 حفظ');
    }
});

test('E35-048: the saved page is owner-only (guests redirect, each user sees only their saves)', function () {
    $c = e35_public(null, 2, 'محفوظة لأحدهم');
    $a = e16User();
    $b = e16User();
    $this->actingAs($a)->post(route('collections.public.save', $c->public_id));

    auth()->logout();
    $this->get(route('collections.saved'))->assertRedirect(route('login'));
    $this->actingAs($a)->get(route('collections.saved'))->assertOk()->assertSee('محفوظة لأحدهم');
    $this->actingAs($b)->get(route('collections.saved'))->assertOk()->assertDontSee('محفوظة لأحدهم');
});

test('E35-049: a hidden / privatized collection cannot be opened from the saved list (neutral placeholder, removable)', function () {
    $saver = e16User();
    $hidden = e35_public(null, 2, 'ستُخفى بعد الحفظ');
    $priv = e35_public(null, 2, 'ستصبح خاصة بعد الحفظ');
    foreach ([$hidden, $priv] as $c) {
        $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));
    }

    e35_hide_collection($hidden);
    app(\App\Services\Collections\CollectionVisibilityService::class)->makePrivate($priv->owner ?? \App\Models\User::query()->find($priv->user_id), $priv->public_id);

    $html = $this->actingAs($saver)->get(route('collections.saved'))->assertOk()->getContent();
    expect($html)->not->toContain('ستُخفى بعد الحفظ')->and($html)->not->toContain('ستصبح خاصة بعد الحفظ')
        ->and($html)->not->toContain('href="'.route('collections.public.show', $hidden->public_id).'"')
        ->and($html)->not->toContain('href="'.route('collections.public.show', $priv->public_id).'"')
        ->and($html)->toContain('غير متاح');
    $this->actingAs($saver)->delete(route('collections.public.unsave', $hidden->public_id), ['from' => 'saved'])->assertRedirect(route('collections.saved'));
    expect(UserCollectionSave::query()->where('user_id', $saver->id)->count())->toBe(1);
});

test('E35-050: saving a collection grants zero rewards and touches no economy state', function () {
    $c = e35_public(null, 2);
    $saver = e16User();
    $this->actingAs($saver)->get(route('discover.index'));   // خط أساس: صفوف التقدّم الكسولة التي ينشئها عرض أي صفحة
    $before = e28_world($saver);
    $snap = e16Snapshot();

    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));
    $this->actingAs($saver)->get(route('collections.saved'));

    expect(e28_world($saver))->toBe($before)->and(e16Snapshot())->toBe($snap);
});
