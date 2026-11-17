<?php

use App\Filament\Resources\RedemptionRequestResource\Pages\ListRedemptionRequests;
use App\Models\CurrencyTransaction;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\Economy\Integrity\RedemptionCommitments;
use App\Services\Economy\Integrity\WalletAuditCase;
use App\Services\GemWalletService;
use App\Services\PendingGemReleaseService;
use App\Services\RedemptionService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E46 - أدلة (characterization) على سلوك مسارات كتابة الأرصدة **كما هو الآن**. لا يُصلَح هنا أي شيء ماليًا.
|
| اختبارات «FINDING-*» تثبّت السلوك الحالي المعيب (وتفشل عمدًا إذا أُصلح المسار لاحقًا، فيُحدَّث الاختبار وقتها):
|   F1) RedemptionService بلا حارس حالة ولا قفل صف ⇒ reject/reject تردّ مرتين، reject→fulfill و fulfill→reject و fulfill/fulfill تمرّ.
|   F2) PendingGemReleaseService يجمع earn/release لكل مستخدم بلا فلتر عملة ⇒ عملة أخرى تؤثر على تحرير الجواهر الافتراضية.
|   F3) (ملاحظة تحتاج قرار منتج) التحرير المجدول لا يمسّ إلا العملة الافتراضية ⇒ معلَّق عملات الفعاليات لا يتحرر بأي مسار آلي.
| التفاصيل والأثر والإصلاح المقترح في docs/gem-integrity-e46-findings.md (بلا تطبيق إصلاح).
*/

beforeEach(function () {
    $this->redemptions = app(RedemptionService::class);
    $this->commitments = app(RedemptionCommitments::class);
    $this->admin = User::factory()->admin()->create();
    $this->min = (int) config('gems.min_redemption');
});

// ───────────── ما هو سليم اليوم (يُثبَّت كي لا يتراجع) ─────────────

test('E46-70: release/release is safe: a second scheduled release moves nothing and the audit stays matched', function () {
    $user = User::factory()->create();
    app(GemWalletService::class)->credit($user, 40, 'solved_puzzle:1');
    DB::table('currency_transactions')->update(['created_at' => now()->subDays((int) config('gems.pending_hold_days') + 2)]);

    $first = app(PendingGemReleaseService::class)->run();
    $second = app(PendingGemReleaseService::class)->run();
    e46_age_everything();

    expect($first['released_gems'])->toBe(40)->and($second['released_gems'])->toBe(0)
        ->and(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and(CurrencyTransaction::where('user_id', $user->id)->where('type', CurrencyTransaction::TYPE_RELEASE_AVAILABLE)->count())->toBe(1);
});

test('E46-71: through the admin UI a SEQUENTIAL second reject is blocked (action hidden) and refunds only once', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create();
    $staff->assignRole('administrator');
    $request = e46_request(e46_eligible_user());

    $this->actingAs($staff);
    $table = Livewire::test(ListRedemptionRequests::class);
    $table->callTableAction('reject', $request, ['note' => 'سبب']);
    $table->assertTableActionHidden('reject', $request->fresh())      // الزر لا يظهر لطلب مرفوض أصلًا
        ->assertTableActionHidden('fulfill', $request->fresh());

    $refunds = CurrencyTransaction::where('user_id', $request->user_id)->where('reason', 'like', 'redemption_rejected:%')->count();

    expect($request->fresh()->status)->toBe(RedemptionRequest::STATUS_REJECTED)->and($refunds)->toBe(1)
        ->and(e46_wallet(User::find($request->user_id), e46_gem())->available_balance)->toBe($this->min);
});

// ───────────── FINDING-F1: حالات الانتقال في RedemptionService ─────────────

test('E46-72 FINDING-F1a (current behaviour): reject/reject at the service level refunds TWICE, and the wallet audit cannot see it because wallet and ledger agree', function () {
    $request = e46_request(e46_eligible_user());
    $user = User::find($request->user_id);

    // نسختان من الطلب حُمِّلتا وهو قيد المراجعة (ما يحدث عند ضغطتين متزامنتين: كلاهما اجتاز فحص الحالة غير المقفول)
    $a = RedemptionRequest::find($request->id);
    $b = RedemptionRequest::find($request->id);
    $this->redemptions->reject($a, $this->admin, 'أول');
    $this->redemptions->reject($b, $this->admin, 'ثانٍ');
    e46_age_everything();

    $refunds = CurrencyTransaction::where('user_id', $user->id)->where('reason', 'like', 'redemption_rejected:%')->count();

    expect($refunds)->toBe(2)
        ->and(e46_wallet($user, e46_gem())->available_balance)->toBe(2 * $this->min)          // ضعف ما خُصم
        ->and(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)      // مطابقة المحفظة للدفتر لا تكشفه
        ->and($this->commitments->summary()['footprints']['duplicate_refunds'])->toBe(1);       // لكن بصمة الالتزامات تكشفه
});

test('E46-73 FINDING-F1b (current behaviour): reject → fulfil is accepted: the request ends "fulfilled" and lifetime_redeemed grows although the gems were refunded', function () {
    $request = e46_request(e46_eligible_user());
    $user = User::find($request->user_id);

    $this->redemptions->reject(RedemptionRequest::find($request->id), $this->admin, 'رفض');
    $this->redemptions->markFulfilled(RedemptionRequest::find($request->id), $this->admin);

    expect(RedemptionRequest::find($request->id)->status)->toBe(RedemptionRequest::STATUS_FULFILLED)
        ->and(e46_wallet($user, e46_gem())->available_balance)->toBe($this->min)                 // رُدّت
        ->and(e46_wallet($user, e46_gem())->lifetime_redeemed)->toBe($this->min)                 // ومع ذلك سُجّلت «مستبدلة»
        ->and($this->commitments->summary()['footprints']['refunded_but_not_rejected'])->toBe(1);
});

test('E46-74 FINDING-F1c (current behaviour): fulfil → reject is accepted: gems are refunded after the request was fulfilled and lifetime_redeemed is not reversed', function () {
    $request = e46_request(e46_eligible_user());
    $user = User::find($request->user_id);
    $this->redemptions->approve(RedemptionRequest::find($request->id), $this->admin);
    $this->redemptions->markFulfilled(RedemptionRequest::find($request->id), $this->admin);

    $this->redemptions->reject(RedemptionRequest::find($request->id), $this->admin, 'متأخر');

    expect(RedemptionRequest::find($request->id)->status)->toBe(RedemptionRequest::STATUS_REJECTED)
        ->and(e46_wallet($user, e46_gem())->available_balance)->toBe($this->min)
        ->and(e46_wallet($user, e46_gem())->lifetime_redeemed)->toBe($this->min);                // لم يُعكَس
    // الحالة النهائية «مرفوض + رد واحد» فلا تظهر في بصمات الالتزامات؛ أثرها الوحيد lifetime_redeemed وسجل التشغيل.
    expect($this->commitments->summary()['footprints'])->toBe(['duplicate_refunds' => 0, 'rejected_without_refund' => 0, 'refunded_but_not_rejected' => 0]);
});

test('E46-75 FINDING-F1d (current behaviour): fulfil/fulfil adds lifetime_redeemed twice (markFulfilled has no state guard and no transaction)', function () {
    $request = e46_request(e46_eligible_user());
    $user = User::find($request->user_id);
    $this->redemptions->approve(RedemptionRequest::find($request->id), $this->admin);

    $this->redemptions->markFulfilled(RedemptionRequest::find($request->id), $this->admin);
    $this->redemptions->markFulfilled(RedemptionRequest::find($request->id), $this->admin);

    expect(e46_wallet($user, e46_gem())->lifetime_redeemed)->toBe(2 * $this->min);
});

// ───────────── FINDING-F2: PendingGemReleaseService بلا فلتر عملة ─────────────

test('E46-76 FINDING-F2a (current behaviour): an old pending earning in ANOTHER currency lets a FRESH default-currency earning be released before its hold ends', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    $hold = (int) config('gems.pending_hold_days');

    app(GemWalletService::class)->credit($user, 10, 'solved_puzzle:fresh');                                     // جواهر افتراضية حديثة: يجب أن تبقى معلّقة
    app(CurrencyWalletService::class)->creditPending($user, $event, 30, 'campaign_reward');                      // عملة أخرى
    DB::table('currency_transactions')->where('currency_id', $event->id)->update(['created_at' => now()->subDays($hold + 3)]);

    app(PendingGemReleaseService::class)->run();

    $default = e46_wallet($user, e46_gem());

    expect($default->pending_balance)->toBe(0)->and($default->available_balance)->toBe(10)   // تحرّرت قبل انتهاء فترة تعليقها
        ->and(e46_wallet($user, $event)->pending_balance)->toBe(30);                            // وعملة الحدث نفسها لم تتحرر
});

test('E46-77 FINDING-F2b (current behaviour): a release in ANOTHER currency is subtracted from the default currency entitlement, so old default gems can stay pending', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    $hold = (int) config('gems.pending_hold_days');

    app(GemWalletService::class)->credit($user, 10, 'solved_puzzle:old');
    DB::table('currency_transactions')->update(['created_at' => now()->subDays($hold + 3)]);
    app(CurrencyWalletService::class)->creditPending($user, $event, 10, 'campaign_reward');
    app(CurrencyWalletService::class)->releasePending($user, $event, 10, 'manual_release');                      // release_available بعملة الحدث

    app(PendingGemReleaseService::class)->run();

    expect(e46_wallet($user, e46_gem())->pending_balance)->toBe(10)->and(e46_wallet($user, e46_gem())->available_balance)->toBe(0);
});

test('E46-78: in every case above the wallet audit of each currency stays internally consistent (these are rule defects, not balance corruption)', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    app(GemWalletService::class)->credit($user, 10, 'solved_puzzle:fresh');
    app(CurrencyWalletService::class)->creditPending($user, $event, 30, 'campaign_reward');
    DB::table('currency_transactions')->where('currency_id', $event->id)->update(['created_at' => now()->subDays(30)]);
    app(PendingGemReleaseService::class)->run();
    e46_age_everything();

    expect(e46_case($user, e46_gem())->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED)
        ->and(e46_case($user, $event)->verdict)->toBe(WalletAuditCase::VERDICT_MATCHED);
});

test('E46-79 FINDING-F3 (observation, current behaviour): the scheduled release only ever touches the default currency, so an old pending balance in an event currency never becomes available', function () {
    $user = User::factory()->create();
    $event = e46_event_currency();
    app(CurrencyWalletService::class)->creditPending($user, $event, 30, 'campaign_reward');
    DB::table('currency_transactions')->update(['created_at' => now()->subDays(365)]);

    $stats = app(PendingGemReleaseService::class)->run();

    expect($stats['released_gems'])->toBe(0)
        ->and(e46_wallet($user, $event)->pending_balance)->toBe(30)
        ->and(e46_wallet($user, $event)->available_balance)->toBe(0);
});
