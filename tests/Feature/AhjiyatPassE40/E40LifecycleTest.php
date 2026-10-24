<?php

use App\Models\AhjiyatPass;
use App\Models\AhjiyatPassTier;
use App\Models\DigitalPurchase;
use App\Models\UserPassProgress;
use App\Services\AhjiyatPass\PassException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E40 / AX1 — دورة حياة المسار: مسودة/مجدول/فعّال/منتهٍ/مؤرشف، مسار فعّال واحد، نافذة صالحة، وقفل الحقول الحرجة بعد النشر.
*/

beforeEach(fn () => e40_seed());

// ───────────────────────────── 1–3: الظهور ─────────────────────────────

test('AX1-01: a draft pass is never public and never current', function () {
    $draft = e40_build(['publish' => false])['pass'];

    expect($draft->status)->toBe('draft')
        ->and(e40_life()->current())->toBeNull()
        ->and(e40_life()->findVisible($draft->public_id))->toBeNull();

    $this->actingAs(e16User())->get(route('pass.archive.show', $draft->public_id))->assertNotFound();
    $this->actingAs(e16User())->get(route('pass.index'))->assertOk()->assertDontSee($draft->name);
});

test('AX1-02: a scheduled pass does not become active before its start time', function () {
    $pass = e40_pass(['starts' => now()->addDays(3)]);

    expect($pass->status)->toBe('scheduled')
        ->and($pass->effectiveStatus())->toBe('scheduled')
        ->and(e40_life()->current())->toBeNull()
        ->and(e40_life()->findVisible($pass->public_id))->toBeNull()
        ->and(e40_life()->sync())->toBe(['ended' => 0, 'activated' => 0]);

    // لا نقاط ولا تقدّم قبل البدء ولو أُنجزت المهمة
    $quest = AhjiyatPass::find($pass->id)->missions()->first()->quest;
    $user = e40_player();
    e40_completion($user, $quest, now());
    expect(e40_points()->healFor($user, $pass))->toBe(0)
        ->and(e40_row($user, $pass))->toBeNull();
});

test('AX1-03: scheduled pass activates at start via sync and becomes visible to players', function () {
    $pass = e40_pass(['starts' => now()->addHours(2)]);
    Carbon::setTestNow(now()->addHours(3));
    e40_cache()->flush();

    expect(e40_life()->sync())->toBe(['ended' => 0, 'activated' => 1]);

    $fresh = $pass->fresh();
    expect($fresh->status)->toBe('active')->and($fresh->live_slot)->toBe(1)
        ->and(e40_life()->current()?->id)->toBe($pass->id);

    $this->actingAs(e16User())->get(route('pass.index'))->assertOk()->assertSee($pass->name);
});

test('AX1-04: a pass created already inside its window is active immediately on publish', function () {
    $pass = e40_pass();

    expect($pass->fresh()->status)->toBe('active')->and(e40_life()->current()?->id)->toBe($pass->id);
});

// ───────────────────────────── 4: النهاية توقف التقدّم ─────────────────────────────

test('AX1-05: once ended, new completions stop earning points but the claim window stays open', function () {
    $b = e40_build(['starts' => now()->subDays(29), 'days' => 30]);
    $user = e40_player($b['pass']);
    $quest = $b['quests'][0];

    expect(e40_grant($user, $b['pass'], $quest, 1, now()->subHours(3)))->toBe(1);

    Carbon::setTestNow(now()->addDays(2)); // بعد ends_at
    e40_cache()->flush();
    expect(e40_life()->sync()['ended'])->toBe(1);

    e40_completion($user, $quest, now()); // إنجاز بعد النهاية
    expect(e40_points()->healFor($user, $b['pass']->fresh()))->toBe(0)
        ->and(e40_points_of($user, $b['pass']))->toBe(50)
        ->and($b['pass']->fresh()->isClaimWindowOpen())->toBeTrue()
        ->and($b['pass']->fresh()->isActiveNow())->toBeFalse();
});

test('AX1-06: an early end stops points and premium sales immediately and starts the claim grace from now', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);

    e40_life()->end($b['admin'], $b['pass'], 'اختبار');
    $ended = $b['pass']->fresh();

    expect($ended->status)->toBe('ended')
        ->and($ended->ends_at->equalTo(now()))->toBeTrue()
        ->and($ended->claim_ends_at->equalTo(now()->addDays(5)))->toBeTrue()
        ->and($ended->live_slot)->toBeNull()
        ->and(e40_life()->current())->toBeNull()
        ->and(e40_reason(fn () => e40_premium()->request($user, $ended)))->toBe('sales_closed');
});

test('AX1-07: only an active pass can be ended early', function () {
    $draft = e40_build(['publish' => false]);
    $future = e40_pass(['starts' => now()->addDays(2)]);

    expect(e40_reason(fn () => e40_life()->end($draft['admin'], $draft['pass'])))->toBe('not_active')
        ->and(e40_reason(fn () => e40_life()->end(e40_admin(), $future)))->toBe('not_active');
});

// ───────────────────────────── 5: الأرشفة ─────────────────────────────

test('AX1-08: an ended pass can be archived only after the claim window and stays historical', function () {
    $b = e40_build(['starts' => now()->subDays(5), 'days' => 30, 'grace' => 3]);
    $user = e40_player($b['pass']);
    e40_grant($user, $b['pass'], $b['quests'][0], 2, now()->subHours(2));

    Carbon::setTestNow(now()->addDays(26)); // انتهى، داخل المهلة
    e40_cache()->flush();
    expect(e40_reason(fn () => e40_life()->archive($b['admin'], $b['pass'])))->toBe('claim_window_open');

    Carbon::setTestNow(now()->addDays(5));
    e40_cache()->flush();
    $archived = e40_life()->archive($b['admin'], $b['pass']->fresh());

    expect($archived->status)->toBe('archived')->and($archived->archived_at)->not->toBeNull()
        ->and(e40_row($user, $b['pass'])->pass_points)->toBe(100) // التاريخ محفوظ
        ->and(e40_life()->findVisible($b['pass']->public_id)?->id)->toBe($b['pass']->id);

    $this->actingAs($user)->get(route('pass.archive.show', $b['pass']->public_id))->assertOk()->assertSee($b['pass']->name);
    $this->actingAs($user)->get(route('pass.archive'))->assertOk()->assertSee($b['pass']->name);
});

test('AX1-09: an archived pass accepts no new points, claims or edits', function () {
    $b = e40_build(['starts' => now()->subDay(), 'days' => 30, 'grace' => 1]);
    $user = e40_player($b['pass']);
    e40_grant($user, $b['pass'], $b['quests'][0], 2, now()->subHours(2));
    Carbon::setTestNow(now()->addDays(34));
    e40_cache()->flush();
    e40_life()->archive($b['admin'], $b['pass']->fresh());
    $pass = $b['pass']->fresh();

    e40_completion($user, $b['quests'][0], now()->subDays(20));
    expect(e40_points()->healFor($user, $pass))->toBe(0)
        ->and(e40_reason(fn () => e40_claims()->claim($user, $pass, (int) $b['rewards'][2]['free']->id)))->not->toBeNull()
        ->and(e40_reason(fn () => e40_builder()->update($b['admin'], $pass, ['name' => 'محاولة'])))->toBe('archived');
});

test('AX1-10: a pass with open purchases cannot be archived', function () {
    $b = e40_build(['starts' => now()->subDay(), 'days' => 30, 'grace' => 1]);
    $user = e40_player($b['pass']);
    e40_premium()->request($user, $b['pass']);

    Carbon::setTestNow(now()->addDays(34));
    e40_cache()->flush();

    expect(e40_reason(fn () => e40_life()->archive($b['admin'], $b['pass']->fresh())))->toBe('open_purchases');
});

// ───────────────────────────── 6: مسار فعّال واحد ─────────────────────────────

test('AX1-11: an overlapping second pass cannot be published (one active pass at a time)', function () {
    e40_pass();
    $second = e40_build(['publish' => false, 'starts' => now()->addDays(5)]);

    $message = e40_error(fn () => e40_life()->schedule($second['admin'], $second['pass']));

    expect($message)->toContain('غير جاهز')->and($second['pass']->fresh()->status)->toBe('draft');
});

test('AX1-12: a non-overlapping next pass can be scheduled after the current one', function () {
    $first = e40_pass(['starts' => now()->subDays(2), 'days' => 10]);
    $next = e40_build(['starts' => $first->ends_at->copy()->addDay(), 'days' => 14]);

    expect($next['pass']->fresh()->status)->toBe('scheduled')
        ->and(e40_life()->current()?->id)->toBe($first->id);
});

test('AX1-13: the database itself allows a single stored-active pass (unique live_slot)', function () {
    $first = e40_pass();
    $other = e40_build(['publish' => false, 'starts' => now()->addDays(40)])['pass'];

    expect(fn () => DB::table('ahjiyat_passes')->where('id', $other->id)->update(['live_slot' => 1]))->toThrow(\Illuminate\Database\QueryException::class)
        ->and($first->fresh()->live_slot)->toBe(1);
});

// ───────────────────────────── 7: نافذة غير صالحة ─────────────────────────────

test('AX1-14: windows that end before they start are rejected', function () {
    $admin = e40_admin();
    $draft = e40_builder()->createDraft($admin, ['internal_key' => 'bad-window', 'name' => 'نافذة معكوسة', 'starts_at' => '2026-12-20 00:00:00', 'ends_at' => '2026-12-10 00:00:00']);
    e40_builder()->generateTiers($admin, $draft, 3, 100);

    expect(e40_error(fn () => e40_life()->schedule($admin, $draft)))->toContain('غير جاهز');
});

test('AX1-15: duration outside the configured bounds is rejected', function () {
    $short = e40_build(['publish' => false, 'days' => 3]);
    $long = e40_build(['publish' => false, 'days' => 400]);

    expect(e40_error(fn () => e40_life()->schedule($short['admin'], $short['pass'])))->toContain('غير جاهز')
        ->and(e40_error(fn () => e40_life()->schedule($long['admin'], $long['pass'])))->toContain('غير جاهز');
});

test('AX1-16: a pass whose end date already passed cannot be published', function () {
    $b = e40_build(['publish' => false, 'starts' => now()->subDays(40), 'days' => 30]);

    $e = null;
    try {
        e40_life()->schedule($b['admin'], $b['pass']);
    } catch (PassException $e) {
    }

    expect($e)->toBeInstanceOf(PassException::class)->and(implode(' ', $e->details))->toContain('مضى');
});

test('AX1-17: grace days beyond the maximum are rejected', function () {
    $admin = e40_admin();

    expect(e40_reason(fn () => e40_builder()->createDraft($admin, ['internal_key' => 'long-grace', 'name' => 'مهلة طويلة', 'starts_at' => '2026-12-20 00:00:00', 'claim_grace_days' => 60])))->toBe('grace');
});

test('AX1-18: a pass without tiers, missions or rewards is not publishable and nothing is locked', function () {
    $admin = e40_admin();
    $draft = e40_builder()->createDraft($admin, ['internal_key' => 'empty-pass', 'name' => 'فارغ', 'starts_at' => now()->format('Y-m-d H:i:s'), 'premium_enabled' => false]);

    $e = null;
    try {
        e40_life()->schedule($admin, $draft);
    } catch (PassException $e) {
    }

    expect($e?->reason)->toBe('not_ready')->and($e->details)->not->toBeEmpty()
        ->and($draft->fresh()->locked_at)->toBeNull()->and($draft->fresh()->status)->toBe('draft');
});

// ───────────────────────────── 8: قفل الحقول الحرجة ─────────────────────────────

test('AX1-19: critical fields of a published pass are immutable even on direct model writes', function () {
    $pass = e40_pass();

    foreach (['starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'claim_grace_days' => 9, 'premium_price_minor' => 1, 'currency' => 'EUR', 'internal_key' => 'hacked'] as $field => $value) {
        $fresh = AhjiyatPass::find($pass->id);
        $fresh->forceFill([$field => $value]);
        expect(fn () => $fresh->save())->toThrow(\LogicException::class);
    }
});

test('AX1-20: the builder refuses to change price, dates or tiers after publish but allows safe display fields', function () {
    $b = e40_build();
    $admin = $b['admin'];

    expect(e40_reason(fn () => e40_builder()->update($admin, $b['pass'], ['premium_price_minor' => 1])))->not->toBeNull()
        ->and(e40_reason(fn () => e40_builder()->update($admin, $b['pass'], ['ends_at' => now()->addYear()->format('Y-m-d H:i:s')])))->not->toBeNull()
        ->and(e40_reason(fn () => e40_builder()->replaceTiers($admin, $b['pass'], [['required_points' => 0], ['required_points' => 5]])))->toBe('locked')
        ->and(e40_reason(fn () => e40_builder()->setTierReward($admin, $b['tiers'][3], 'free', e40_item())))->toBe('locked');

    $updated = e40_builder()->update($admin, $b['pass'], ['name' => 'اسم جديد للمسار', 'subtitle' => 'عنوان فرعي']);
    expect($updated->name)->toBe('اسم جديد للمسار')->and($updated->fresh()->premium_price_minor)->toBe(500);
});

test('AX1-21: a published pass can never be deleted, only a never-published draft', function () {
    $b = e40_build();
    $draft = e40_build(['publish' => false, 'starts' => now()->addDays(60)]);

    expect(e40_reason(fn () => e40_life()->deleteDraft($b['admin'], $b['pass'])))->toBe('not_draft')
        ->and(fn () => AhjiyatPass::find($b['pass']->id)->delete())->toThrow(\LogicException::class);

    e40_life()->deleteDraft($draft['admin'], $draft['pass']);
    expect(AhjiyatPass::find($draft['pass']->id))->toBeNull()
        ->and(AhjiyatPassTier::query()->where('ahjiyat_pass_id', $draft['pass']->id)->count())->toBe(0);
});

test('AX1-22: unscheduling is allowed only before start and without activity', function () {
    $future = e40_build(['starts' => now()->addDays(5)]);
    $back = e40_life()->unschedule($future['admin'], $future['pass']);
    expect($back->status)->toBe('draft')->and($back->locked_at)->toBeNull();

    $active = e40_build(['starts' => now()->subDay(), 'key' => 'second-active']);
    // سبق وجود مسار فعّال آخر؟ لا: هذا هو الفعّال الوحيد بعد إلغاء جدولة الأول
    expect(e40_reason(fn () => e40_life()->unschedule($active['admin'], $active['pass'])))->toBe('not_unschedulable');
});

test('AX1-23: unscheduling is refused once a player row exists, and after the start time', function () {
    $b = e40_build(['starts' => now()->addDays(1)]);
    e40_progress()->ensure(e16User(), $b['pass']);

    expect(e40_reason(fn () => e40_life()->unschedule($b['admin'], $b['pass']->fresh())))->toBe('has_activity');

    Carbon::setTestNow(now()->addDays(2));
    e40_cache()->flush();
    expect(e40_reason(fn () => e40_life()->unschedule($b['admin'], $b['pass']->fresh())))->toBe('not_unschedulable');
});

test('AX1-24: the global kill switch hides the pass, its routes and point earning but keeps history', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);
    e40_grant($user, $b['pass'], $b['quests'][0], 1);
    $before = e40_points_of($user, $b['pass']);

    config(['ahjiyat_pass.enabled' => false]);
    e40_cache()->flush();

    expect(e40_life()->current())->toBeNull();
    $this->actingAs($user)->get(route('pass.index'))->assertNotFound();

    e40_completion($user, $b['quests'][0], now()->subHours(1));
    expect(e40_points()->healFor($user, $b['pass']))->toBe(0)
        ->and(e40_points_of($user, $b['pass']))->toBe($before);

    config(['ahjiyat_pass.enabled' => true]);
    expect(UserPassProgress::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('AX1-25: lifecycle transitions are audited', function () {
    $b = e40_build();
    e40_life()->end($b['admin'], $b['pass'], 'سبب');

    $actions = DB::table('operational_audit_logs')->pluck('action')->all();

    expect($actions)->toContain('ahjiyat_pass_created', 'ahjiyat_pass_scheduled', 'ahjiyat_pass_ended');
});

test('AX1-26: the stored status is repaired from dates by sync when the scheduler was down', function () {
    $pass = e40_pass(['starts' => now()->subDay(), 'days' => 30]);
    // الزمن يتقدّم بعد انتهاء النافذة بينما المخزَّن ما زال active (المجدول متوقف)
    Carbon::setTestNow(now()->addDays(45));
    e40_cache()->flush();

    expect($pass->fresh()->effectiveStatus())->toBe('ended')
        ->and(e40_life()->current())->toBeNull()
        ->and(e40_life()->sync()['ended'])->toBe(1)
        ->and($pass->fresh()->status)->toBe('ended')->and($pass->fresh()->live_slot)->toBeNull();
});

test('AX1-27: pass is independent of the season (no season link in schema)', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('ahjiyat_passes', 'season_id'))->toBeFalse();
    $columns = \Illuminate\Support\Facades\Schema::getColumnListing('user_pass_progress');
    expect(collect($columns)->filter(fn ($c) => str_contains($c, 'season')))->toBeEmpty();
});

test('AX1-28: premium purchases are not touched by lifecycle transitions', function () {
    $b = e40_build();
    $user = e40_player($b['pass']);
    $purchase = e40_buy_premium($user, $b['pass']);

    e40_life()->end($b['admin'], $b['pass']);

    expect($purchase->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)
        ->and(e40_row($user, $b['pass'])->premium_unlocked_at)->not->toBeNull();
});
