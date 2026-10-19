<?php

use App\Models\Puzzle;
use App\Models\User;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralQualificationService;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF4-19: registration alone is not a qualified referral - nothing qualifies, rewards or notifies without an official action', function () {
    e33_rewards_on();
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());

    app(ReferralQualificationService::class)->evaluate($ref->id);
    $ref->refresh();

    expect($ref->status)->toBe(UserReferral::STATUS_PENDING)
        ->and($ref->qualified_at)->toBeNull()
        ->and($ref->action_at)->toBeNull()
        ->and(e33_ledger())->toHaveCount(0)
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

test('AF4-20: a verified referred account with an official action qualifies and the referrer is told once', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);

    $after = e33_act($referred);

    expect($after->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and($after->qualified_at)->not->toBeNull()
        ->and($after->action_at)->not->toBeNull()
        ->and($after->reward_status)->toBe(UserReferral::REWARD_SKIPPED)
        ->and($after->reward_skip_reason)->toBe('no_campaign')
        ->and(DatabaseNotification::query()->where('notifiable_id', $referrer->id)->where('data->type', 'referral_qualified')->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $referred->id)->count())->toBe(0);
});

test('AF4-21: an unverified or frozen referred account never qualifies', function () {
    $referrer = e33_user();

    [$unverified, $r1] = e33_attr($referrer, null, ['email_verified_at' => null]);
    expect(e33_act($unverified)->status)->toBe(UserReferral::STATUS_PENDING);

    [$frozen, $r2] = e33_attr($referrer);
    $frozen->forceFill(['is_frozen' => true])->save();
    expect(e33_act($frozen)->status)->toBe(UserReferral::STATUS_PENDING);

    // يوثّق بريده لاحقًا ثم يتجمّد فيُرفع التجميد ⇒ يتأهل عند الحدث التالي
    $unverified->forceFill(['email_verified_at' => now()])->save();
    expect(e33_act($unverified)->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-22: the minimum account age is respected and the periodic command finishes the job later', function () {
    config(['referrals.qualification.min_account_age_hours' => 24]);
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, null, [], 0);
    DB::table('users')->where('id', $referred->id)->update(['created_at' => now()->subHours(2)]);

    $after = e33_act($referred);
    expect($after->status)->toBe(UserReferral::STATUS_PENDING)->and($after->action_at)->not->toBeNull();

    $this->artisan('referrals:process')->assertSuccessful();
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING);

    Carbon::setTestNow(now()->addHours(30));
    $this->artisan('referrals:process')->assertSuccessful();
    Carbon::setTestNow();

    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-23: the real gameplay path qualifies - an official solve triggers it, a wrong answer does not, and multi-solve rules count distinct official puzzles', function () {
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer);

    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);
    app(\App\Services\PuzzleAttemptService::class)->attempt($referred, $puzzle, 'خطأ');
    expect($ref->fresh()->status)->toBe(UserReferral::STATUS_PENDING);

    $result = e33_solve($referred, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($result['correct'])->toBeTrue()->and($ref->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);

    // قاعدة ثلاث حلول: التكرار على نفس الأحجية لا يحتسب
    $rule = e33_campaign(['qualification_rule' => 'three_official_solves']);
    [$second, $ref2] = e33_attr($referrer, $rule);
    $p1 = Puzzle::factory()->create(['answer_raw' => 'صح']);
    e33_solve($second, $p1);
    e33_solve($second, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($ref2->fresh()->status)->toBe(UserReferral::STATUS_PENDING);
    e33_solve($second, Puzzle::factory()->create(['answer_raw' => 'صح']));
    expect($ref2->fresh()->status)->toBe(UserReferral::STATUS_QUALIFIED);
});

test('AF4-24: qualification is idempotent - repeating the event or evaluating twice changes nothing and notifies once', function () {
    e33_rewards_on();
    $referrer = e33_user();
    [$referred, $ref] = e33_attr($referrer, e33_reward_campaign());

    $first = e33_act($referred);
    $stamp = $first->qualified_at->getTimestamp();

    for ($i = 0; $i < 4; $i++) {
        e33_act($referred);
        app(ReferralQualificationService::class)->evaluate($ref->id);
    }
    $again = $ref->fresh();

    expect($again->qualified_at->getTimestamp())->toBe($stamp)
        ->and(e33_ledger())->toHaveCount(2)
        ->and(DatabaseNotification::query()->where('data->type', 'referral_qualified')->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('data->type', 'referral_reward_received')->count())->toBe(2);

    // نسخة قديمة من الصف (سباق) لا تؤهّل ثانية
    $stale = UserReferral::query()->find($ref->id);
    app(ReferralQualificationService::class)->evaluate($stale->id);
    expect(e33_ledger())->toHaveCount(2);
});

test('AF4-25: the referrer cannot change after qualification and a deleted referrer keeps the history without a referrer', function () {
    $a = e33_user();
    $b = e33_user();
    [$referred, $ref] = e33_attr($a);
    e33_act($referred);

    $other = app(\App\Services\Referrals\ReferralAttributionService::class)->attribute($referred, e33_code($b));
    $ref->refresh();

    expect($other->id)->toBe($ref->id)
        ->and($ref->referrer_user_id)->toBe($a->id)
        ->and($ref->status)->toBe(UserReferral::STATUS_QUALIFIED)
        ->and(array_values(array_intersect(array_keys($ref->getAttributes()), ['referrer_user_id'])))->toBe(['referrer_user_id']);

    // لا مسار في الخدمات يكتب referrer_user_id بعد الإنشاء (فحص ساكن)
    $code = '';
    foreach (glob(app_path('Services/Referrals/*.php')) as $file) {
        $code .= file_get_contents($file);
    }
    expect(preg_match_all("/'referrer_user_id'\s*=>/", $code))->toBe(1); // الإنشاء فقط (ReferralAttributionService)

    // حذف المحيل ⇒ SET NULL والإحالة باقية (لا مكافآت جديدة لمحيل مجهول)
    $a->delete();
    expect($ref->fresh())->not->toBeNull()->and($ref->fresh()->referrer_user_id)->toBeNull();
});
