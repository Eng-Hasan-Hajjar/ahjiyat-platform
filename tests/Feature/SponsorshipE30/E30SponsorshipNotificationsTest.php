<?php

use App\Models\SponsorshipContribution;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sponsorship\ContributionService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E30 (R) - إشعارات الداعمين عبر E15: للداعم المرتبط بحساب فقط، بلا أي مبلغ/مرجع/ملاحظة إدارية، وفشلها لا يعيد أي حالة مالية للوراء.
*/

beforeEach(fn () => e30_seed());

function e30n_notes(\App\Models\User $user, ?string $type = null): \Illuminate\Support\Collection
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->when($type, fn ($q) => $q->where('type_key', $type))->orderBy('created_at')->get();
}

test('AM-R1: confirming support notifies the linked supporter once, without any amount, reference or private note', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id, 'is_public' => true]);
    $program = e30_program();

    $contribution = e30_pending($supporter, $program, '77.25', ['reference' => 'REF-PRIVATE-7781', 'notes' => 'ملاحظة إدارية خاصة جدًا']);
    expect(e30n_notes($user, 'support_confirmed'))->toHaveCount(0);   // المعلّقة لا تُشعِر

    app(ContributionService::class)->confirm(e30_admin(), $contribution);

    $notes = e30n_notes($user, 'support_confirmed');
    expect($notes)->toHaveCount(1);

    $raw = json_encode($notes->first(), JSON_UNESCAPED_UNICODE);
    foreach (['REF-PRIVATE-7781', 'ملاحظة إدارية خاصة جدًا', '77.25', '7725', 'USD'] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("leaked: {$secret}");
    }

    // تأكيد ثانٍ مرفوض ولا يكرّر الإشعار
    try {
        app(ContributionService::class)->confirm(e30_admin(), $contribution->fresh());
    } catch (\Throwable) {
    }

    expect(e30n_notes($user, 'support_confirmed'))->toHaveCount(1);
});

test('AM-R2: crossing a tier threshold sends supporter_tier_changed (up), a refund sends it (down), same tier sends nothing', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);
    $program = e30_program();

    e30_confirmed($supporter, $program, '5');      // null → داعم
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(1);

    e30_confirmed($supporter, $program, '1');      // نفس المستوى
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(1);

    $big = e30_confirmed($supporter, $program, '300');   // → ذهبي
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(2);
    $up = json_decode(e30n_notes($user, 'supporter_tier_changed')->last()->data, true);
    expect($up['title'])->toBeString()->and(json_encode($up, JSON_UNESCAPED_UNICODE))->not->toContain('300');

    e30_refund($big);                              // ذهبي → داعم
    expect(e30n_notes($user, 'supporter_tier_changed'))->toHaveCount(3);
    $titles = e30n_notes($user, 'supporter_tier_changed')->map(fn ($n) => json_decode($n->data, true)['title'])->unique();
    expect($titles->count())->toBeGreaterThan(1);   // نص الارتقاء يختلف عن نص الانخفاض
});

test('AM-R3: a supporter without an account, or a frozen account, is never notified and nothing fails', function () {
    $program = e30_program();
    $orphan = e30_supporter();                    // بلا حساب
    $confirmed = e30_confirmed($orphan, $program, '50');
    expect($confirmed->status)->toBe('confirmed');

    $frozen = e16User();
    $supporter = e30_supporter(['user_id' => $frozen->id]);
    $frozen->forceFill(['is_frozen' => true])->save();
    e30_confirmed($supporter, $program, '50');

    expect(DB::table('notifications')->where('notifiable_id', $frozen->id)->count())->toBe(0);
});

test('AM-R4: notification failure does NOT roll back the confirmation, the totals, the tier or the audit trail', function () {
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);
    $program = e30_program();
    $contribution = e30_pending($supporter, $program, '120');

    app()->bind(NotificationDispatcher::class, fn () => new class extends NotificationDispatcher
    {
        public function __construct() {}

        public function dispatch(\App\Models\User $user, \App\Services\Notifications\NotificationType $type, array $params, string $idempotencyKey, array $refs = [], array $actionParams = [], ?string $actionRoute = null): \App\Services\Notifications\DispatchResult
        {
            throw new \RuntimeException('simulated notification outage');
        }
    });
    app()->forgetInstance(\App\Services\Sponsorship\SponsorshipNotifier::class);
    app()->forgetInstance(ContributionService::class);

    $confirmed = app(ContributionService::class)->confirm(e30_admin(), $contribution);

    expect($confirmed->status)->toBe(SponsorshipContribution::STATUS_CONFIRMED)
        ->and(SponsorshipContribution::query()->whereKey($contribution->id)->value('status'))->toBe('confirmed')
        ->and(app(\App\Services\Sponsorship\SupportTotals::class)->net($supporter->id))->toBe(12000)
        ->and(DB::table('operational_audit_logs')->where('action', 'like', 'sponsorship_contribution%confirm%')->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

test('AM-R5: disabling sponsorship notifications silences them without touching the money flow', function () {
    config(['sponsorship.notifications.enabled' => false]);
    $user = e16User();
    $supporter = e30_supporter(['user_id' => $user->id]);

    $c = e30_confirmed($supporter, e30_program(), '60');

    expect($c->status)->toBe('confirmed')->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0);
});

test('AM-R6: activating a program notifies only linked supporters that have a contribution in it, once each', function () {
    $in = e16User();
    $out = e16User();
    $inSupporter = e30_supporter(['user_id' => $in->id]);
    e30_supporter(['user_id' => $out->id]);
    $program = e30_program([], false);
    e30_pending($inSupporter, $program, '10');

    app(\App\Services\Sponsorship\SponsorshipProgramService::class)->activate(e30_admin(), $program);

    expect(e30n_notes($in, 'sponsorship_program_started'))->toHaveCount(1)
        ->and(e30n_notes($out, 'sponsorship_program_started'))->toHaveCount(0);
});
