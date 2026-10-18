<?php

use App\Filament\Pages\PaymentSettingsPage;
use App\Filament\Resources\SponsorshipPaymentResource;
use App\Filament\Resources\SponsorshipPaymentResource\Pages\ListSponsorshipPayments;
use App\Models\SponsorshipContribution;
use App\Models\SponsorshipPayment;
use App\Services\Database\FinancialHistoryGuard;
use App\Services\Notifications\NotificationType;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentRetentionService;
use App\Services\PlatformSettingsService;
use Database\Seeders\DemoE32Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E32 - لوحة الإدارة (طابور المراجعة المالي + إعدادات الدفع) بالصلاحيات، حراسة النموذج، الاحتفاظ بالإثباتات، البذرة، وسجل الإشعارات.
*/

beforeEach(fn () => e32_seed());

function e32_queue(?\App\Models\User $who = null)
{
    return Livewire::actingAs($who ?? e32_finance())->test(ListSponsorshipPayments::class);
}

// ملاحظة: كل اختبار هنا يستعمل مستخدم لوحة واحدًا في طلبات HTTP؛ تبديل مستخدم Filament داخل الاختبار نفسه بعد أول طلب يترك حارس الجلسة بحال غير قابلة للمقارنة (سلوك أداة الاختبار لا التطبيق).
test('E32-A1: the review queue is closed to guests and ordinary users', function () {
    $url = SponsorshipPaymentResource::getUrl('index');

    $this->get($url)->assertRedirect();
    expect($this->actingAs(e16User())->get($url)->getStatusCode())->toBeIn([403, 404]);
});

test('E32-A1b: a content manager (sponsorships.view) can open the review queue', function () {
    $this->actingAs(e32_manager())->get(SponsorshipPaymentResource::getUrl('index'))->assertOk();
});

test('E32-A1c: finance can open the review queue', function () {
    $this->actingAs(e32_finance())->get(SponsorshipPaymentResource::getUrl('index'))->assertOk();
});

test('E32-A2: a content manager sees the queue but every financial action is hidden and uncallable', function () {
    $payment = e32_submitted();
    $component = e32_queue(e32_manager())->assertCanSeeTableRecords([$payment]);

    foreach (['verify', 'reject', 'clarify', 'cancel', 'refund', 'proof'] as $action) {
        $component->assertTableActionHidden($action, $payment);
    }

    expect($payment->fresh()->status)->toBe('submitted');
});

test('E32-A3: finance verifies from the queue; a foreign currency requires the accounting equivalent in the form', function () {
    $program = e32_program();
    $usd = e32_submitted(null, $program, '20');
    e32_queue()->callTableAction('verify', $usd)->assertHasNoTableActionErrors();
    expect($usd->fresh()->status)->toBe('verified')->and(e32_funding($program))->toBe(2000);

    $user = e16User();
    $eur = e32_payments()->markTransferred($user, e32_request($user, $program, '10', false, 'EUR'), 'يورو');
    $component = e32_queue();
    $component->callTableAction('verify', $eur, data: [])->assertHasTableActionErrors(['accounting_amount' => 'required']);
    expect($eur->fresh()->status)->toBe('submitted');

    $component->callTableAction('verify', $eur, data: ['accounting_amount' => '11'])->assertHasNoTableActionErrors();
    expect($eur->fresh()->status)->toBe('verified')->and($eur->fresh()->accounting_amount_minor)->toBe(1100);
});

test('E32-A4: reject, clarify, cancel and refund actions work from the queue with required reasons', function () {
    $component = e32_queue();

    $rejected = e32_submitted();
    $component->callTableAction('reject', $rejected, data: [])->assertHasTableActionErrors(['code' => 'required']);
    $component->callTableAction('reject', $rejected, data: ['code' => 'not_received', 'message' => 'لم يصل', 'note' => 'ملاحظة داخلية'])->assertHasNoTableActionErrors();
    expect($rejected->fresh()->status)->toBe('rejected')->and($rejected->fresh()->review_note)->toBe('ملاحظة داخلية');

    $clarify = e32_submitted();
    $component->callTableAction('clarify', $clarify, data: ['reason' => 'ar'])->assertHasTableActionErrors(['reason']);
    $component->callTableAction('clarify', $clarify, data: ['reason' => 'أرسل إيصالًا أوضح'])->assertHasNoTableActionErrors();
    expect($clarify->fresh()->status)->toBe('awaiting_payment');

    $cancel = e32_submitted();
    $component->callTableAction('cancel', $cancel, data: ['reason' => 'طلب مكرر'])->assertHasNoTableActionErrors();
    expect($cancel->fresh()->status)->toBe('cancelled');

    $refund = e32_verified();
    $component->callTableAction('refund', $refund, data: ['reason' => 'استرداد من اللوحة'])->assertHasNoTableActionErrors();
    expect($refund->fresh()->status)->toBe('refunded')->and(e32_contribution($refund)->status)->toBe('refunded');
});

test('E32-A5: invalid actions show a friendly error instead of a 500 and change nothing (e.g. refunding an unverified payment is hidden)', function () {
    $payment = e32_submitted();

    e32_queue()->assertTableActionHidden('refund', $payment)->assertTableActionVisible('verify', $payment)->assertTableActionVisible('reject', $payment);
    e32_payments()->reject(e32_finance(), $payment, 'other');
    e32_queue()->assertTableActionHidden('verify', $payment->fresh())->assertTableActionHidden('reject', $payment->fresh());
});

test('E32-A6: the queue flags a reused proof (same hash on another request) and filters by status, anonymity and proof', function () {
    $a = e32_submitted();
    $b = e32_submitted(null, null, '10', true);
    $c = e32_submitted(null, null, '10', false, false);
    DB::table('sponsorship_payments')->where('id', $b->id)->update(['proof_sha256' => $a->fresh()->proof_sha256]);

    $component = e32_queue();
    $records = $component->instance()->getTableRecords();
    expect((int) $records->firstWhere('id', $a->id)->dup_proof)->toBe(1)->and((int) $records->firstWhere('id', $b->id)->dup_proof)->toBe(1)->and((int) $records->firstWhere('id', $c->id)->dup_proof)->toBe(0);

    $component->filterTable('is_anonymous', true)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
    e32_queue()->filterTable('has_proof', false)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
    e32_payments()->reject(e32_finance(), $a, 'duplicate');
    e32_queue()->filterTable('status', 'rejected')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c]);
});

test('E32-A7: the queue offers no create, edit or delete - financial history is append-only', function () {
    $payment = e32_verified();

    expect(SponsorshipPaymentResource::canCreate())->toBeFalse()->and(SponsorshipPaymentResource::canEdit($payment))->toBeFalse()->and(SponsorshipPaymentResource::canDelete($payment))->toBeFalse()
        ->and(array_keys(SponsorshipPaymentResource::getPages()))->toBe(['index']);
    expect(fn () => $payment->delete())->toThrow(PaymentException::class)->and(SponsorshipPayment::query()->count())->toBe(1);
    expect(\Illuminate\Support\Facades\Gate::forUser(e32_finance())->allows('delete', $payment))->toBeFalse();
});

test('E32-A8: the payment model protects immutable fields, transitions and the contribution link', function () {
    $payment = e32_submitted();

    foreach (['amount_minor' => 1, 'currency' => 'EUR', 'user_id' => 99999, 'reference' => 'SUP-HACKED22', 'provider' => 'x', 'is_anonymous' => true, 'sponsorship_program_id' => 99999] as $field => $value) {
        $model = SponsorshipPayment::query()->findOrFail($payment->id);
        expect(fn () => $model->forceFill([$field => $value])->save())->toThrow(PaymentException::class, 'محمية');
    }

    $rejected = e32_payments()->reject(e32_finance(), e32_submitted(), 'other');
    expect(fn () => SponsorshipPayment::query()->findOrFail($rejected->id)->forceFill(['status' => 'verified'])->save())->toThrow(PaymentException::class)
        ->and(fn () => SponsorshipPayment::query()->findOrFail($payment->id)->forceFill(['status' => 'refunded'])->save())->toThrow(PaymentException::class);
    expect(fn () => SponsorshipPayment::create(['amount_minor' => 5]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

test('E32-A9a: the payment settings page is closed to guests and to content managers', function () {
    $url = '/admin/payment-settings';

    $this->get($url)->assertRedirect();
    expect($this->actingAs(e32_manager())->get($url)->getStatusCode())->toBeIn([403, 404]);
});

test('E32-A9b: finance opens the settings page, persists the instructions and the audit lists changed keys without their values', function () {
    $this->actingAs(e32_finance())->get('/admin/payment-settings')->assertOk();

    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_enabled' => true, 'manual_title' => 'حوالة بنكية', 'manual_beneficiary' => 'جمعية أحجيات', 'manual_account' => 'TR33 0006 1005 1978 6457 8413 26', 'allowed_currencies' => ['USD', 'EUR']])
        ->call('save')->assertHasNoFormErrors();

    $settings = app(PlatformSettingsService::class);
    expect($settings->get('payments', 'manual_beneficiary'))->toBe('جمعية أحجيات')->and($settings->get('payments', 'allowed_currencies'))->toBe('USD,EUR');

    $audit = DB::table('operational_audit_logs')->where('action', 'payment_settings_updated')->first();
    expect($audit)->not->toBeNull()->and((string) $audit->metadata)->not->toContain('TR33')->and((string) $audit->metadata)->not->toContain('جمعية أحجيات');

    expect(\App\Services\Payments\PaymentRules::currencies(app(\App\Services\Payments\PaymentProviderRegistry::class)->get('manual')))->toBe(['USD', 'EUR']);
});

test('E32-A10: the settings page refuses credentials and card numbers (nothing is saved)', function () {
    $settings = app(PlatformSettingsService::class);
    $before = $settings->getGroup('payments');

    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_title' => 'تحويل', 'manual_account' => 'الرقم السري للحساب 1234 وكلمة المرور abc'])
        ->call('save');
    Livewire::actingAs(e32_finance())->test(PaymentSettingsPage::class)
        ->fillForm(['manual_title' => 'تحويل', 'manual_extra_note' => 'ادفع على البطاقة 4111 1111 1111 1111'])
        ->call('save');

    expect($settings->getGroup('payments'))->toBe($before)->and(e32_audit('payment_settings_updated'))->toBe(0);
});

test('E32-A11: proof retention prunes only final, old proofs - never an open request or one inside the dispute window - and keeps the record', function () {
    config(['payments.retention.proof_days_after_final' => 30, 'payments.retention.dispute_window_days' => 60]);
    $open = e32_submitted();
    $verified = e32_verified();
    $rejected = e32_payments()->reject(e32_finance(), e32_submitted(), 'not_received');
    $freshVerified = e32_verified();
    $verifiedPath = $verified->fresh()->proof_path;

    // يتقدّم الزمن 45 يومًا: المحقَّق (>30) مرشَّح، المرفوض (نافذة نزاع 60) لا، والمفتوح لا.
    $this->travel(45)->days();
    $retention = app(PaymentRetentionService::class);
    expect($retention->count())->toBe(2);   // verified + freshVerified (كلاهما >30 يومًا) ؛ rejected داخل نافذة النزاع ؛ open لا يُقلَّم
    $this->artisan('payments:prune-proofs', ['--dry-run' => true])->expectsOutputToContain('candidates=2')->assertExitCode(0);
    expect(Storage::disk('local')->exists($verifiedPath))->toBeTrue();

    $this->artisan('payments:prune-proofs')->expectsOutputToContain('pruned=2')->assertExitCode(0);
    expect(Storage::disk('local')->exists($verifiedPath))->toBeFalse()->and($verified->fresh()->proof_path)->toBeNull()->and($verified->fresh()->proof_purged_at)->not->toBeNull()
        ->and($verified->fresh()->proof_sha256)->not->toBeNull()->and($verified->fresh()->status)->toBe('verified')->and(SponsorshipPayment::query()->count())->toBe(4)
        ->and($open->fresh()->proof_path)->not->toBeNull()->and($rejected->fresh()->proof_path)->not->toBeNull()->and(e32_audit('payment_proof_pruned'))->toBe(2);

    $this->artisan('payments:prune-proofs')->expectsOutputToContain('pruned=0')->assertExitCode(0);
    $this->travel(30)->days();
    expect($retention->count())->toBe(1);   // المرفوض انتهت نافذة نزاعه الآن؛ المفتوح ما زال محميًا
    expect($freshVerified->fresh()->proof_purged_at)->not->toBeNull();
});

test('E32-A12: the history-table guard and the transfer sums know the payments table', function () {
    expect(FinancialHistoryGuard::HISTORY_TABLES)->toHaveKey('sponsorship_payments')
        ->and(config('db_transfer.financial_sums'))->toHaveKey('sponsorship_payments')
        ->and(config('db_transfer.financial_sums.sponsorship_payments'))->toBe(['amount_minor', 'accounting_amount_minor']);

    $user = e16User();
    e32_request($user);
    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

test('E32-A13: the four new notification types are registered with existing routes and no money text', function () {
    foreach (['support_request_received', 'support_action_required', 'support_rejected', 'support_refunded'] as $value) {
        $type = NotificationType::from($value);
        expect($type->allowedRoutes())->toContain('support.payments.show');
        foreach ($type->allowedRoutes() as $route) {
            expect(Route::has($route))->toBeTrue("missing route {$route}");
        }
        expect($type->title([]))->not->toBeEmpty()->and($type->body([]))->not->toMatch('/[0-9$€₺]/u');
    }

    $user = e16User();
    $payment = e32_request($user);
    $notes = e32_notes($user, 'support_request_received');
    expect($notes)->toHaveCount(1);
});

test('E32-A14: the demo seeder is idempotent, builds every state, refuses production and leaves a clean reconciliation', function () {
    $this->seed(DemoE32Seeder::class);
    $first = SponsorshipPayment::query()->count();
    $statuses = SponsorshipPayment::query()->pluck('status')->unique()->sort()->values()->all();
    $contributions = SponsorshipContribution::query()->count();

    $this->seed(DemoE32Seeder::class);

    expect(SponsorshipPayment::query()->count())->toBe($first)->toBe(6)->and($statuses)->toBe(['awaiting_payment', 'refunded', 'rejected', 'submitted', 'verified'])
        ->and(SponsorshipContribution::query()->count())->toBe($contributions)->toBe(3)
        ->and(SponsorshipPayment::query()->where('is_anonymous', true)->where('status', 'verified')->count())->toBe(1);
    $this->artisan('sponsorship:reconcile-payments')->assertExitCode(0);

    $this->app['env'] = 'production';
    expect(fn () => (new DemoE32Seeder)->run())->toThrow(RuntimeException::class);
});

test('E32-A15: the finance dashboard widget and navigation badge count only what needs attention, with no sensitive data', function () {
    e32_submitted();
    e32_submitted();
    e32_request();
    e32_verified();
    app(\App\Services\Payments\PaymentAnalytics::class)->forget();

    expect(SponsorshipPaymentResource::getNavigationBadge())->toBe('2');
    $summary = app(\App\Services\Payments\PaymentAnalytics::class)->summary(true);
    expect($summary['awaiting_verification'])->toBe(2)->and($summary['pending'])->toBe(1)->and($summary['verified_today'])->toBe(1)->and($summary['confirmed_count'])->toBe(1)
        ->and(array_keys($summary))->toBe(['pending', 'awaiting_verification', 'verified_today', 'failed', 'refunded', 'confirmed_total_minor', 'confirmed_count', 'average_minor', 'manual_share', 'currency']);

    e32_queue()->assertSeeHtml('wire:');
});
