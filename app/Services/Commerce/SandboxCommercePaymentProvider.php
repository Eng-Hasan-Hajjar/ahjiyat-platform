<?php

namespace App\Services\Commerce;

use App\Models\DigitalPurchase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * E41 - مزوّد **تجريبي** يحاكي دفعًا مستضافًا وأحداثًا موقَّعة (مدفوع/فاشل/ملغى/مسترد) واستعلام حالة، بحتمية كاملة، **للتطوير والاختبار وCI فقط**.
 * ليس بوابة دفع حقيقية ولا يُوصف كذلك أبدًا (التسمية بالواجهة: «دفع تجريبي (محاكاة)»).
 *
 * سلامة الإنتاج: CommerceEnvironment::sandboxAllowed() = false في production مهما كان الضبط ⇒ isEnabled/isConfigured/acceptsWebhooks كلها false،
 * وأي عملية (checkout/refund/verify) ترفع CommerceException، والسجل (CommercePaymentProviderRegistry) يعامله كغير موجود، ومسارات المحاكاة لا تُسجَّل أصلًا.
 *
 * بلا شبكة: لا Http هنا إطلاقًا. حالة «المزوّد» المحاكى في الكاش (جلسات/استردادات/عدّادات استدعاء للاختبار) وهي لا تكون سلطة على شيء: السلطة DigitalPurchase في قاعدة البيانات.
 * الجلسة حتمية (مشتقة من مفتاح idempotency) فتتكرر آمنة بين العمليات. التوقيع: HMAC-SHA256 على «الطابع.الجسم» بسر محاكاة ثابت غير سري (config: sandbox-not-a-secret).
 */
class SandboxCommercePaymentProvider implements CommercePaymentProvider
{
    public const KEY = 'sandbox';

    public const SIGNATURE_HEADER = 'X-Commerce-Signature';

    private const CACHE = 'commerce:sandbox:';

    private const TTL_SECONDS = 604_800;

    /** @var array<string, CommerceProviderState> */
    private const TYPE_MAP = [
        'payment.pending' => CommerceProviderState::Pending,
        'payment.paid' => CommerceProviderState::Paid,
        'payment.failed' => CommerceProviderState::Failed,
        'payment.cancelled' => CommerceProviderState::Cancelled,
        'payment.refunded' => CommerceProviderState::Refunded,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) config('commerce.providers.sandbox.label', 'دفع تجريبي (محاكاة)');
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function isEnabled(): bool
    {
        if (! CommerceEnvironment::sandboxAllowed() || ! (bool) config('commerce.enabled', true)) {
            return false;
        }

        $configured = config('commerce.providers.sandbox.enabled');

        return $configured === null || $configured === '' ? true : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return CommerceEnvironment::sandboxAllowed() && $this->secret() !== '';
    }

    public function acceptsWebhooks(): bool
    {
        return CommerceEnvironment::sandboxAllowed() && (bool) config('commerce.providers.sandbox.accept_webhooks', true) && $this->secret() !== '';
    }

    public function currencies(): ?array
    {
        $codes = array_values(array_filter(array_map(static fn ($c): string => strtoupper(trim((string) $c)), (array) config('commerce.providers.sandbox.currencies', []))));

        return $codes === [] ? null : $codes;
    }

    public function supportsRedirect(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsStatusQuery(): bool
    {
        return true;
    }

    public function instructionsFor(DigitalPurchase $purchase): ?array
    {
        return null;
    }

    public function proofRequired(): bool
    {
        return false;
    }

    // ───────────────────────── checkout ─────────────────────────

    public function createCheckout(CommerceCheckoutRequest $request): CommerceCheckoutInit
    {
        $this->guard();

        if (Cache::get(self::CACHE.'outage') === true) {
            throw new CommerceException('provider_unavailable', 'خدمة الدفع غير متاحة مؤقتًا. أعد المحاولة بعد لحظات.', true);
        }

        $this->bump('checkout_calls');
        $sessionId = 'sbx_'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $record = Cache::get(self::CACHE.'session:'.$sessionId);

        if (! is_array($record)) {
            $record = [
                'purchase' => $request->purchase->public_id,
                'reference' => $request->purchase->reference_code,
                'amount_minor' => (int) $request->purchase->amount_minor,
                'currency' => (string) $request->purchase->currency,
                'status' => CommerceProviderState::Pending->value,
                'expires_at' => $request->expiresAt->getTimestamp(),
            ];

            if (Cache::add(self::CACHE.'session:'.$sessionId, $record, self::TTL_SECONDS)) {
                $this->bump('sessions_created');
            } else {
                $record = Cache::get(self::CACHE.'session:'.$sessionId, $record);
            }
        }

        return new CommerceCheckoutInit($sessionId, route('commerce.sandbox.pay', ['session' => $sessionId]), CarbonImmutable::createFromTimestamp((int) $record['expires_at']));
    }

    // ───────────────────────── webhook ─────────────────────────

    public function verifyWebhook(string $payload, array $headers): CommerceWebhookEvent
    {
        $this->guard();

        $signature = null;

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === strtolower(self::SIGNATURE_HEADER)) {
                $signature = is_array($value) ? ($value[0] ?? null) : $value;
                break;
            }
        }

        WebhookSignature::verify(is_string($signature) ? $signature : null, $payload, $this->secret(), (int) config('commerce.webhooks.replay_tolerance_seconds', 300), now());

        try {
            $body = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CommerceWebhookException('malformed_payload', 'حمولة الحدث غير صالحة.', 400);
        }

        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $eventId = is_array($body) ? ($body['id'] ?? null) : null;
        $type = is_array($body) ? ($body['type'] ?? null) : null;

        if (! is_array($data) || ! is_string($eventId) || preg_match('/^[A-Za-z0-9_\-:.]{1,128}$/', $eventId) !== 1 || ! is_string($type) || strlen($type) > 64) {
            throw new CommerceWebhookException('malformed_payload', 'حمولة الحدث غير صالحة.', 400);
        }

        $amount = $data['amount_minor'] ?? null;
        $currency = $data['currency'] ?? null;

        if (($amount !== null && (! is_int($amount) || $amount < 0)) || ($currency !== null && (! is_string($currency) || preg_match('/^[A-Za-z]{3}$/', $currency) !== 1))) {
            throw new CommerceWebhookException('malformed_payload', 'حمولة الحدث غير صالحة.', 400);
        }

        $created = $body['created'] ?? null;

        return new CommerceWebhookEvent(
            provider: self::KEY,
            eventId: $eventId,
            eventType: $type,
            state: self::TYPE_MAP[$type] ?? CommerceProviderState::Unknown,
            sessionId: $this->refString($data['session_id'] ?? null),
            paymentReference: $this->refString($data['payment_id'] ?? null),
            refundReference: $this->refString($data['refund_id'] ?? null),
            reference: $this->refString($data['reference'] ?? null),
            amountMinor: $amount,
            currency: $currency === null ? null : strtoupper($currency),
            occurredAt: is_int($created) ? CarbonImmutable::createFromTimestamp($created) : null,
            payloadSha256: hash('sha256', $payload),
        );
    }

    // ───────────────────────── refund / status ─────────────────────────

    public function refund(DigitalPurchase $purchase, string $idempotencyKey): CommerceRefundResult
    {
        $this->guard();
        $this->bump('refund_calls');

        $known = Cache::get(self::CACHE.'refund:'.$idempotencyKey);

        if (is_array($known)) {
            return $known['outcome'] === CommerceRefundResult::PENDING ? CommerceRefundResult::pending($known['reference']) : CommerceRefundResult::succeeded((string) $known['reference']);
        }

        $mode = (string) (Cache::get(self::CACHE.'refund_mode') ?? config('commerce.providers.sandbox.refund_mode', 'succeeded'));

        if ($mode === 'failed') {
            return CommerceRefundResult::failed('sandbox_refund_failed');
        }

        $reference = 'sbxre_'.substr(hash('sha256', $idempotencyKey), 0, 24);

        if (Cache::add(self::CACHE.'refund:'.$idempotencyKey, ['outcome' => $mode === 'pending' ? CommerceRefundResult::PENDING : CommerceRefundResult::SUCCEEDED, 'reference' => $reference], self::TTL_SECONDS)) {
            $this->bump('refunds_effective');
        }

        if ($mode === 'pending') {
            return CommerceRefundResult::pending($reference);
        }

        if ($purchase->provider_session_id !== null) {
            $this->setSessionStatus((string) $purchase->provider_session_id, CommerceProviderState::Refunded);
        }

        return CommerceRefundResult::succeeded($reference);
    }

    public function fetchStatus(DigitalPurchase $purchase): ?CommerceProviderStatus
    {
        $this->guard();

        if ($purchase->provider_session_id === null) {
            return null;
        }

        if (Cache::get(self::CACHE.'outage') === true) {
            throw new CommerceException('provider_unavailable', 'خدمة الدفع غير متاحة مؤقتًا.', true);
        }

        $this->bump('status_calls');
        $record = Cache::get(self::CACHE.'session:'.$purchase->provider_session_id);

        if (! is_array($record)) {
            return new CommerceProviderStatus(CommerceProviderState::Pending, (string) $purchase->provider_session_id);
        }

        $state = CommerceProviderState::tryFrom((string) $record['status']) ?? CommerceProviderState::Unknown;
        $paid = $state === CommerceProviderState::Paid || $state === CommerceProviderState::Refunded;

        return new CommerceProviderStatus(
            state: $state,
            sessionId: (string) $purchase->provider_session_id,
            paymentReference: $paid ? $this->paymentId((string) $purchase->provider_session_id) : null,
            refundReference: null,
            amountMinor: (int) ($record['paid_amount_minor'] ?? $record['amount_minor']),
            currency: (string) ($record['paid_currency'] ?? $record['currency']),
            paidAt: $paid ? CarbonImmutable::createFromTimestamp((int) ($record['paid_at'] ?? now()->getTimestamp())) : null,
        );
    }

    // ───────────────────────── محاكاة (للاختبار والصفحة التجريبية فقط) ─────────────────────────

    /**
     * ينشئ حدثًا موقَّعًا كما سيرسله مزوّد حقيقي. `$override` يغيّر حقول data (مبلغ/عملة/مرجع…) لاختبار عدم التطابق. المعرّف حتمي من النوع+الجلسة+nonce.
     *
     * @param  array<string, mixed>  $override
     * @return array{payload: string, headers: array<string, string>, event_id: string}
     */
    public function signedEvent(string $sessionId, string $type, array $override = [], ?string $nonce = null, ?int $timestamp = null): array
    {
        $this->guard();

        $record = Cache::get(self::CACHE.'session:'.$sessionId);
        $record = is_array($record) ? $record : ['reference' => null, 'amount_minor' => null, 'currency' => null];
        $timestamp ??= now()->getTimestamp();

        $data = array_merge([
            'session_id' => $sessionId,
            'payment_id' => in_array($type, ['payment.paid', 'payment.refunded'], true) ? $this->paymentId($sessionId) : null,
            'refund_id' => $type === 'payment.refunded' ? 'sbxre_'.substr(hash('sha256', 'ext:'.$sessionId), 0, 24) : null,
            'reference' => $record['reference'],
            'amount_minor' => $record['amount_minor'],
            'currency' => $record['currency'],
        ], $override);

        $eventId = (string) ($override['event_id'] ?? 'evt_'.substr(hash('sha256', $type.'|'.$sessionId.'|'.($nonce ?? '')), 0, 24));
        unset($data['event_id']);

        $payload = (string) json_encode(['id' => $eventId, 'type' => $type, 'created' => $timestamp, 'data' => array_filter($data, static fn ($v): bool => $v !== null)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'payload' => $payload,
            'headers' => [self::SIGNATURE_HEADER => WebhookSignature::header($this->secret(), $timestamp, $payload)],
            'event_id' => $eventId,
        ];
    }

    /** يغيّر حالة «المزوّد» المحاكى للجلسة (صعودًا فقط) ويعيد الحدث الموقَّع المقابل. */
    public function simulate(string $sessionId, CommerceProviderState $state, array $override = [], ?string $nonce = null): array
    {
        $this->guard();
        $this->setSessionStatus($sessionId, $state, $override);

        return $this->signedEvent($sessionId, 'payment.'.$state->value, $override, $nonce);
    }

    public static function simulateOutage(bool $down): void
    {
        $down ? Cache::put(self::CACHE.'outage', true, 600) : Cache::forget(self::CACHE.'outage');
    }

    public static function forceRefundMode(?string $mode): void
    {
        $mode === null ? Cache::forget(self::CACHE.'refund_mode') : Cache::put(self::CACHE.'refund_mode', $mode, 600);
    }

    /** عدّاد استدعاءات المحاكاة (للتأكد من «مرة واحدة»). المفاتيح: checkout_calls | sessions_created | refund_calls | refunds_effective | status_calls. */
    public static function counter(string $name): int
    {
        return (int) Cache::get(self::CACHE.'count:'.$name, 0);
    }

    public static function resetState(): void
    {
        foreach (['outage', 'refund_mode'] as $key) {
            Cache::forget(self::CACHE.$key);
        }
    }

    // ───────────────────────── داخلي ─────────────────────────

    protected function setSessionStatus(string $sessionId, CommerceProviderState $state, array $override = []): void
    {
        $key = self::CACHE.'session:'.$sessionId;
        $record = Cache::get($key);

        if (! is_array($record)) {
            return;
        }

        $current = CommerceProviderState::tryFrom((string) $record['status']) ?? CommerceProviderState::Pending;

        if ($state->rank() <= $current->rank()) {
            return;
        }

        $record['status'] = $state->value;

        if ($state === CommerceProviderState::Paid) {
            $record['paid_at'] = now()->getTimestamp();
            $record['paid_amount_minor'] = isset($override['amount_minor']) ? (int) $override['amount_minor'] : $record['amount_minor'];
            $record['paid_currency'] = isset($override['currency']) ? strtoupper((string) $override['currency']) : $record['currency'];
        }

        Cache::put($key, $record, self::TTL_SECONDS);
    }

    protected function paymentId(string $sessionId): string
    {
        return 'sbxpay_'.substr(hash('sha256', $sessionId), 0, 24);
    }

    protected function secret(): string
    {
        return (string) config('commerce.providers.sandbox.webhook_secret', '');
    }

    protected function guard(): void
    {
        if (! CommerceEnvironment::sandboxAllowed()) {
            throw new CommerceException('sandbox_forbidden', 'المزوّد التجريبي غير متاح في هذه البيئة.');
        }
    }

    protected function refString(mixed $value): ?string
    {
        return (is_string($value) && preg_match('/^[A-Za-z0-9_\-:.]{1,128}$/', $value) === 1) ? $value : null;
    }

    protected function bump(string $name): void
    {
        $key = self::CACHE.'count:'.$name;
        Cache::add($key, 0, self::TTL_SECONDS);
        Cache::increment($key);
    }
}
