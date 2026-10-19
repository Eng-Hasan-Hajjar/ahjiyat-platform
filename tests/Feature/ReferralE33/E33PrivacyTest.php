<?php

use App\Filament\Resources\UserReferralResource\Pages\ListUserReferrals;
use App\Models\OperationalAuditLog;
use App\Models\ReferralCode;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralReviewService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF9-58: the personal dashboard shows aggregate numbers only, never names, emails or addresses of invited people', function () {
    $referrer = e33_user(['name' => 'المحيل الظاهر']);
    $invited = [];

    foreach (['سري-واحد', 'سري-اثنان', 'سري-ثلاثة'] as $i => $secret) {
        [$referred] = e33_attr($referrer, null, ['name' => "مدعو {$secret}", 'email' => "invited-{$i}@private.example"]);
        $invited[] = $referred;
    }

    e33_act($invited[0]);

    $html = $this->actingAs($referrer)->get(route('referrals.index'))->assertOk()->getContent();

    foreach ($invited as $i => $u) {
        expect($html)->not->toContain($u->name)->not->toContain("invited-{$i}@private.example")->not->toContain((string) $u->public_id);
    }

    expect($html)->not->toContain('203.0.113.')->not->toContain('device_hash');
    expect(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($referrer))
        ->toMatchArray(['total' => 3, 'qualified' => 1, 'pending' => 2]);

    // لاعب آخر لا يرى شيئًا من هذا (المعرّف مأخوذ من الجلسة لا من الرابط)
    $stranger = e33_user();
    $this->actingAs($stranger)->get(route('referrals.index', ['user' => $referrer->id, 'user_id' => $referrer->id, 'referrer' => $referrer->id]))->assertOk();
    expect(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($stranger)['total'])->toBe(0);
});

test('AF9-59: an invited person never learns who invited them and the stored intent carries no personal data', function () {
    $referrer = e33_user(['name' => 'اسم المحيل السري', 'email' => 'referrer-secret@private.example']);
    $code = e33_code($referrer);

    $land = $this->get(route('referrals.landing', $code))->assertRedirect(route('register'));
    expect($land->getContent())->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example');

    $intent = session('referral_intent');
    expect(array_keys($intent))->toEqualCanonicalizing(['code', 'c', 's', 'at'])->and(json_encode($intent))->not->toContain((string) $referrer->id.',');

    $page = $this->get(route('register'))->assertOk()->getContent();
    expect($page)->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example');

    e33_register('guest-new@example.test');
    $referred = \App\Models\User::query()->where('email', 'guest-new@example.test')->firstOrFail();
    DB::table('users')->where('id', $referred->id)->update(['email_verified_at' => now()]);
    $html = $this->actingAs($referred->fresh())->get(route('referrals.index'))->assertOk()->getContent();

    expect($html)->not->toContain('اسم المحيل السري')->not->toContain('referrer-secret@private.example')
        ->and(app(\App\Services\Referrals\ReferralAnalytics::class)->forReferrer($referred)['total'])->toBe(0);
});

test('AF9-60: the admin review screens and analytics expose names and closed signal labels but never email, IP or device fingerprints', function () {
    $referrer = e33_user(['name' => 'محيل للمراجعة', 'email' => 'ref-admin-view@private.example']);
    [$referred] = e33_attr($referrer, null, ['name' => 'مُحال للمراجعة', 'email' => 'referred-admin-view@private.example']);
    e33_share_network($referrer, $referred);
    $referral = e33_act($referred);
    expect($referral->status)->toBe(UserReferral::STATUS_PENDING_REVIEW)->and($referral->risk_signals)->toContain('shared_ip', 'shared_device');

    $forbidden = ['ref-admin-view@private.example', 'referred-admin-view@private.example', '203.0.113.10', str_repeat('a', 64)];
    $admin = e33_admin();
    $this->actingAs($admin);

    $list = $this->get('/admin/user-referrals')->assertOk()->getContent();
    expect($list)->toContain('محيل للمراجعة')->toContain('تشارك شبكة')->toContain('تشارك جهاز');

    $component = Livewire::test(ListUserReferrals::class)->assertSuccessful()->assertSee('مُحال للمراجعة');

    foreach ($forbidden as $secret) {
        expect($list)->not->toContain($secret);
        $component->assertDontSee($secret);
    }

    $analytics = $this->get('/admin/referral-analytics')->assertOk()->getContent();

    foreach ($forbidden as $secret) {
        expect($analytics)->not->toContain($secret);
    }
});

test('AF9-61: stored signals, audit entries and notifications contain closed keys and counts, not identities', function () {
    e33_reward_campaign(default: true);
    $referrer = e33_user(['name' => 'محيل الإشعار', 'email' => 'notify-ref@private.example']);
    [$referred, $referral] = e33_attr($referrer, null, ['name' => 'مُحال الإشعار', 'email' => 'notify-referred@private.example']);
    $referral = e33_act($referred);
    expect($referral->status)->toBeIn(UserReferral::QUALIFIED_STATUSES);

    $notes = DatabaseNotification::query()->whereIn('type_key', ['referral_qualified', 'referral_reward_received'])->get();
    expect($notes)->not->toBeEmpty();

    foreach ($notes as $n) {
        $blob = json_encode($n->data, JSON_UNESCAPED_UNICODE);
        expect($blob)->not->toContain('مُحال الإشعار')->not->toContain('محيل الإشعار')->not->toContain('notify-referred@private.example')->not->toContain('notify-ref@private.example');
    }

    // إشارات مخاطر: مفاتيح مغلقة فقط (لا IP ولا بصمة ولا بريد)
    [$suspect] = e33_attr($referrer, null, ['email' => 'suspect-sig@private.example']);
    e33_share_network($referrer, $suspect);
    $flagged = e33_act($suspect);
    $known = array_keys(\App\Filament\Resources\UserReferralResource::SIGNAL_LABELS);
    expect($flagged->risk_signals)->not->toBeEmpty()->and(array_diff($flagged->risk_signals, $known))->toBe([])
        ->and(json_encode($flagged->risk_signals))->not->toContain('203.0.113')->not->toContain('@');

    // قرار المراجعة يُدقَّق بمعرّفات وأسباب مغلقة لا بيانات شخصية
    app(ReferralReviewService::class)->disqualify(e33_admin(), $flagged->fresh(), 'fake_accounts', 'ملاحظة داخلية');
    $audit = OperationalAuditLog::query()->where('action', 'referral_review_disqualified')->latest('id')->firstOrFail();
    $meta = json_encode($audit->metadata ?? $audit->meta ?? [], JSON_UNESCAPED_UNICODE);
    expect($meta)->not->toContain('suspect-sig@private.example')->not->toContain('203.0.113')->not->toContain(str_repeat('a', 64));
});

test('AF9-62: there is no public referral ranking, every referral route except the landing needs a signed-in member, and analytics are staff only', function () {
    $referral = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'referrals.'));

    expect($referral->map->getName()->sort()->values()->all())->toBe(['referrals.index', 'referrals.landing', 'referrals.rotate', 'referrals.share']);

    foreach ($referral as $r) {
        $mw = $r->gatherMiddleware();

        if ($r->getName() !== 'referrals.landing') {
            expect($mw)->toContain('auth')->toContain('verified');
        } else {
            expect($mw)->not->toContain('auth');
        }
    }

    foreach (Route::getRoutes()->getRoutes() as $r) {
        if (! str_starts_with($r->uri(), 'admin')) {
            expect(strtolower($r->uri()))->not->toMatch('#referral.*(leader|ranking|top)|(leader|ranking|top).*referral#');
        }
    }

    foreach (['/referrals/leaderboard', '/leaderboard/referrals', '/referrals/top', '/referrers'] as $url) {
        $this->get($url)->assertNotFound(); // لا مسار علني ولا خاص لقائمة صدارة إحالات
    }

    $this->get(route('referrals.index'))->assertRedirect(route('login'));
    $unverified = e33_user(['email_verified_at' => null]);
    $this->actingAs($unverified)->get(route('referrals.index'))->assertRedirect(route('verification.notice'));

    // لوحة Filament تستعمل AuthenticateSession: تبديل المستخدم داخل جلسة واحدة يُسقطها، فنبدأ جلسة نظيفة لكل دور
    foreach ([[e33_user(), 403], [e33_moderator(), 200], [e33_admin(), 200]] as [$who, $status]) {
        $this->flushSession();
        $this->actingAs($who)->get('/admin/referral-analytics')->assertStatus($status);
    }
});

test('AF9-63: deleting accounts keeps the books consistent and never leaves a dangling or orphaned referral', function () {
    $referrer = e33_user();
    [$referredA, $refA] = e33_attr($referrer);
    [$referredB, $refB] = e33_attr($referrer);
    $codeRow = ReferralCode::query()->where('active_for_user_id', $referrer->id)->firstOrFail();

    // حذف المُحال: يسقط صفّه فقط
    DB::table('users')->where('id', $referredA->id)->delete();
    expect(UserReferral::query()->whereKey($refA->id)->exists())->toBeFalse()->and(UserReferral::query()->whereKey($refB->id)->exists())->toBeTrue();

    // حذف المحيل: تبقى إحالة المُحال بلا محيل (للسجل والتحليلات) ويسقط رمزه
    DB::table('users')->where('id', $referrer->id)->delete();
    $refB = $refB->fresh();
    expect($refB)->not->toBeNull()->and($refB->referrer_user_id)->toBeNull()->and(ReferralCode::query()->whereKey($codeRow->id)->exists())->toBeFalse();

    // إحالة بلا محيل لا تؤهَّل ولا تكافئ ولا تُرسل إشعارًا ولا تنفجر
    $before = DB::table('currency_transactions')->count();
    expect(e33_act($referredB)?->status)->not->toBe(UserReferral::STATUS_REWARDED)
        ->and(DB::table('currency_transactions')->count())->toBe($before);

    $this->artisan('referrals:reconcile')->assertExitCode(0);
});
