<?php

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use App\Services\Commerce\CommerceProviderState;
use App\Services\Commerce\CommerceWebhookException;
use App\Services\Commerce\WebhookSignature;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E41 (BE4 + BE5) - توقيع webhook (يسبق كل أثر) وسجل أحداث المزوّد (فريد، ثابت، بلا حمولة خام)
|--------------------------------------------------------------------------
*/

beforeEach(fn () => e41_seed());

function e41_signed_paid(string $session, ?string $nonce = null, array $override = []): array
{
    return e41_sandbox()->signedEvent($session, 'payment.paid', $override, $nonce);
}

test('BE4-23: a valid signature is accepted over HTTP and verifies the order exactly once', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    e41_post_webhook($this, 'sandbox', $event['payload'], $event['headers'])->assertOk()->assertJson(['ok' => true, 'result' => 'applied']);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_VERIFIED)->and(e41_ledger())->toHaveCount(1);
});

test('BE4-24: an invalid signature is rejected (401) and changes nothing', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);
    $bad = ['X-Commerce-Signature' => WebhookSignature::header('wrong-secret', now()->getTimestamp(), $event['payload'])];

    e41_post_webhook($this, 'sandbox', $event['payload'], $bad)->assertStatus(401)->assertJson(['ok' => false, 'error' => 'invalid_signature']);
    e41_post_webhook($this, 'sandbox', $event['payload'], ['X-Commerce-Signature' => 'garbage'])->assertStatus(401);

    // الحمولة عُدّلت بعد التوقيع (مثلاً رفع المبلغ) ⇒ يفشل التوقيع.
    $tampered = str_replace('"amount_minor":500', '"amount_minor":1', $event['payload']);
    e41_post_webhook($this, 'sandbox', $tampered, $event['headers'])->assertStatus(401);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e41_ledger())->toHaveCount(0);
});

test('BE4-25: a missing signature is rejected (401)', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    e41_post_webhook($this, 'sandbox', $event['payload'], [])->assertStatus(401)->assertJson(['error' => 'missing_signature']);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE4-26: a stale signed event is rejected (replay window) even with a valid signature', function () {
    $h = e41_hosted();
    $old = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], null, now()->subHour()->getTimestamp());

    e41_post_webhook($this, 'sandbox', $old['payload'], $old['headers'])->assertStatus(400)->assertJson(['error' => 'stale_timestamp']);
    $future = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'f', now()->addHour()->getTimestamp());
    e41_post_webhook($this, 'sandbox', $future['payload'], $future['headers'])->assertStatus(400);

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)->and(e41_ledger())->toHaveCount(0);
});

test('BE4-27: the payload size limit is enforced before parsing (413) — by declared length and by actual size', function () {
    $h = e41_hosted();
    config(['commerce.webhooks.max_body_bytes' => 2048]);
    $huge = str_repeat('x', 5000);
    $signed = ['X-Commerce-Signature' => WebhookSignature::header((string) config('commerce.providers.sandbox.webhook_secret'), now()->getTimestamp(), $huge)];

    e41_post_webhook($this, 'sandbox', $huge, $signed)->assertStatus(413);
    expect(e41_reason(fn () => e41_webhooks()->handle('sandbox', $huge, $signed)))->toBe('payload_too_large')
        ->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE4-28: an unknown provider (or one that does not accept webhooks) gets a bare 404', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    e41_post_webhook($this, 'nope', $event['payload'], $event['headers'])->assertNotFound()->assertExactJson(['ok' => false]);
    e41_post_webhook($this, 'manual', $event['payload'], $event['headers'])->assertNotFound();

    config(['commerce.webhooks.enabled' => false]);
    e41_post_webhook($this, 'sandbox', $event['payload'], $event['headers'])->assertNotFound();

    expect($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING);
});

test('BE4-29: no financial read or write happens before the signature is verified', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);
    $queries = [];
    $eventsBefore = DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->count();
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    e41_post_webhook($this, 'sandbox', $event['payload'], ['X-Commerce-Signature' => 't='.now()->getTimestamp().',v1='.str_repeat('a', 64)])->assertStatus(401);

    $financial = array_filter($queries, fn ($sql) => str_contains($sql, 'digital_purchase') || str_contains($sql, 'commerce_provider_events') || str_contains($sql, 'user_pass_progress') || str_contains($sql, 'wallets'));

    expect($financial)->toBe([])->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->count())->toBe($eventsBefore)
        ->and(\App\Models\User::query()->where('id', $h['user']->id)->exists())->toBeTrue();
});

test('BE4-x: a malformed signed body is a 400 with no side effect, and signature failures are counted (not stored) with rate-limited audit', function () {
    $h = e41_hosted();
    $body = 'not json at all';
    $headers = ['X-Commerce-Signature' => WebhookSignature::header((string) config('commerce.providers.sandbox.webhook_secret'), now()->getTimestamp(), $body)];

    e41_post_webhook($this, 'sandbox', $body, $headers)->assertStatus(400)->assertJson(['error' => 'malformed_payload']);

    for ($i = 0; $i < 5; $i++) {
        e41_post_webhook($this, 'sandbox', '{"x":'.$i.'}', ['X-Commerce-Signature' => 't='.now()->getTimestamp().',v1='.str_repeat('b', 64)])->assertStatus(401);
    }

    expect(e41_webhooks()->recentFailures('sandbox', 2))->toBeGreaterThanOrEqual(6)->and(e41_ledger())->toHaveCount(0)
        ->and(DB::table('operational_audit_logs')->where('action', 'commerce_webhook_rejected')->count())->toBeLessThanOrEqual(2);
});

test('BE5-30: a provider event is unique per (provider, event id) — a second row is refused by the database', function () {
    $h = e41_hosted();
    e41_pay($h['session']);
    $row = (array) DB::table('commerce_provider_events')->first();
    unset($row['id']);

    expect(fn () => DB::table('commerce_provider_events')->insert($row))->toThrow(\Illuminate\Database\QueryException::class)
        ->and(DB::table('commerce_provider_events')->count())->toBe(1);

    // نفس معرّف الحدث لمزوّد آخر مسموح (الفرادة لكل مزوّد).
    expect(fn () => DB::table('commerce_provider_events')->insert(array_merge($row, ['provider' => 'other-provider'])))->not->toThrow(\Exception::class);
});

test('BE5-31: a duplicate webhook is idempotent — one unlock, one ledger row, a duplicate answer, no second notification', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    $first = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    $second = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);
    $third = e41_post_webhook($this, 'sandbox', $event['payload'], $event['headers']);

    expect($first['result'])->toBe('applied')->and($second['result'])->toBe('duplicate');
    $third->assertOk()->assertJson(['result' => 'duplicate']);
    expect(e41_ledger())->toHaveCount(1)->and(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(1)
        ->and(DB::table('digital_purchase_events')->where('digital_purchase_id', $h['purchase']->id)->where('event', 'verified')->count())->toBe(1);
});

test('BE5-32: a concurrent duplicate webhook is idempotent — the loser of the ledger race leaves no trace (simulated insert race)', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);

    // نحاكي خسارة السباق: يُدرج «عامل آخر» صف السجل بين فحصنا وإدراجنا. الفحص القافل يرى الصف ⇒ duplicate بلا أي أثر مالي منّا.
    $parsed = e41_sandbox()->verifyWebhook($event['payload'], $event['headers']);
    CommerceProviderEvent::unguarded(fn () => DB::table('commerce_provider_events')->insert([
        'provider' => 'sandbox', 'provider_event_id' => $parsed->eventId, 'event_type' => 'payment.paid', 'normalized_state' => 'paid', 'provider_reference' => $h['session'],
        'digital_purchase_id' => $h['purchase']->id, 'amount_minor' => 500, 'currency' => 'USD', 'processing_result' => 'applied', 'received_at' => now(), 'processed_at' => now(),
    ]));

    $result = e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    expect($result['result'])->toBe('duplicate')->and(e41_ledger())->toHaveCount(1)->and($h['purchase']->fresh()->status)->toBe(DigitalPurchase::STATUS_AWAITING)
        ->and(e40_notes($h['user'], 'pass_premium_unlocked'))->toHaveCount(0);
});

test('BE5-33: the raw payload is never persisted — only its SHA-256 fingerprint, and no JSON body or header lands in the ledger, audit or logs', function () {
    $h = e41_hosted();
    $event = e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);
    e41_webhooks()->handle('sandbox', $event['payload'], $event['headers']);

    $row = (array) DB::table('commerce_provider_events')->first();
    $columns = array_keys($row);

    expect($row['payload_sha256'])->toBe(hash('sha256', $event['payload']))->and(strlen($row['payload_sha256']))->toBe(64)
        ->and(collect($columns)->filter(fn ($c) => str_contains($c, 'payload') && $c !== 'payload_sha256')->all())->toBe([])
        ->and(json_encode($row))->not->toContain('"session_id"')->and(json_encode($row))->not->toContain((string) config('commerce.providers.sandbox.webhook_secret'))
        ->and(DB::table('operational_audit_logs')->get()->contains(fn ($a) => str_contains(json_encode($a), $event['headers']['X-Commerce-Signature'])))->toBeFalse();
});

test('BE5-34: the fingerprint is optional and safe — an event without one (sync) is stored with NULL and a mismatching body hashes differently', function () {
    $h = e41_hosted();
    $a = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'a');
    $b = e41_sandbox()->signedEvent($h['session'], 'payment.paid', [], 'b');

    expect(hash('sha256', $a['payload']))->not->toBe(hash('sha256', $b['payload']));

    e41_sandbox()->simulate($h['session'], CommerceProviderState::Paid);
    app(\App\Services\Commerce\CommerceSyncService::class)->sync($h['purchase']->fresh());

    $sync = DB::table('commerce_provider_events')->where('provider_event_id', 'like', 'sync:%')->first();

    expect($sync)->not->toBeNull()->and($sync->payload_sha256)->toBeNull();
});

test('BE5-35: the event ledger is immutable — update and delete are refused by the model, and the table has no updated_at', function () {
    $h = e41_hosted();
    e41_pay($h['session']);
    $event = CommerceProviderEvent::query()->firstOrFail();

    expect(fn () => $event->update(['processing_result' => 'rejected']))->toThrow(\Exception::class)
        ->and(fn () => $event->forceFill(['processing_result' => 'rejected'])->save())->toThrow(\LogicException::class)
        ->and(fn () => $event->delete())->toThrow(\LogicException::class)
        ->and(fn () => $event->forceFill(['amount_minor' => 1])->save())->toThrow(\LogicException::class)
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('commerce_provider_events', 'updated_at'))->toBeFalse()
        ->and(fn () => CommerceProviderEvent::query()->create(['provider' => 'x']))->toThrow(\Exception::class)
        ->and(CommerceProviderEvent::query()->firstOrFail()->processing_result)->toBe('applied');
});

test('BE5-x: an unknown purchase is stored with a NULL purchase id (ignored/needs-review), and the ledger lookup by event is indexed', function () {
    $stray = e41_sandbox()->signedEvent('sbx_'.str_repeat('a', 24), 'payment.paid', ['reference' => 'NOSUCH', 'amount_minor' => 500, 'currency' => 'USD']);
    $paid = e41_webhooks()->handle('sandbox', $stray['payload'], $stray['headers']);
    $failed = e41_sandbox()->signedEvent('sbx_'.str_repeat('b', 24), 'payment.failed', ['reference' => 'NOSUCH2']);
    $ignored = e41_webhooks()->handle('sandbox', $failed['payload'], $failed['headers']);

    expect($paid)->toMatchArray(['result' => 'needs_review', 'detail' => 'unknown_purchase'])->and($ignored['result'])->toBe('ignored')
        ->and(DB::table('commerce_provider_events')->whereNull('digital_purchase_id')->count())->toBe(2);

    $plan = collect(DB::select("EXPLAIN QUERY PLAN select * from commerce_provider_events where provider = 'sandbox' and provider_event_id = 'x'"))->pluck('detail')->implode(' ');

    expect($plan)->toContain('commerce_provider_events_event_unique');
});
