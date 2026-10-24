<?php

namespace App\Services\Commerce;

/**
 * E41 - سجل مزوّدي الدفع الاستهلاكي (يحلّ بالمفتاح؛ لا مزوّد واحد مدموج عالميًا). يقرأ config/commerce.php ويحلّ كل صنف من الحاوية.
 *
 *  - get(key)              : أي مزوّد **مسجَّل** (مفعَّل للبدء الجديد أم لا) — يخدم webhook والاسترداد والمطابقة لما أُنشئ سابقًا (AV). المجهول ⇒ CommerceException('unknown_provider').
 *  - forNewPurchase(...)   : مزوّد **الشراء الجديد** = الافتراضي المفعَّل الذي يقبل العملة وإلا البديل (اليدوي). المعطَّل ⇒ لا بدء جديد.
 *  - sandbox في الإنتاج    : يُعامل كأنه غير موجود (has=false, get يرفض): لا يمكن حقنه ولا استعماله بتاتًا.
 *  - register(...)         : حقن مزوّد (اختبارات/محوّل مستقبلي) دون تعديل DigitalPurchase. مزوّد sandbox المحقون يخضع للحارس نفسه.
 */
class CommercePaymentProviderRegistry
{
    /** @var array<string, CommercePaymentProvider> */
    protected array $instances = [];

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys((array) config('commerce.providers', []));
        $keys = array_values(array_unique(array_merge($keys, array_keys($this->instances))));

        return array_values(array_filter($keys, fn (string $key): bool => $this->allowed($key)));
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /** @throws CommerceException */
    public function get(string $key): CommercePaymentProvider
    {
        if (! $this->has($key)) {
            throw new CommerceException('unknown_provider', 'طريقة الدفع غير معروفة.');
        }

        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        $class = config("commerce.providers.{$key}.class");

        if (! is_string($class) || ! is_a($class, CommercePaymentProvider::class, true)) {
            throw new CommerceException('unknown_provider', 'طريقة الدفع غير معروفة.');
        }

        $provider = app($class);

        if ($provider->key() !== $key) {
            throw new CommerceException('unknown_provider', 'طريقة الدفع غير معروفة.');
        }

        return $this->instances[$key] = $provider;
    }

    /** @return array<string, CommercePaymentProvider> */
    public function all(): array
    {
        $providers = [];

        foreach ($this->keys() as $key) {
            $providers[$key] = $this->get($key);
        }

        return $providers;
    }

    public function register(CommercePaymentProvider $provider): void
    {
        $this->instances[$provider->key()] = $provider;
    }

    public function forget(string $key): void
    {
        unset($this->instances[$key]);
    }

    public function defaultKey(): string
    {
        return (string) config('commerce.default_provider', 'manual');
    }

    public function fallbackKey(): string
    {
        return (string) config('commerce.fallback_provider', 'manual');
    }

    /**
     * مزوّد الشراء الجديد. `$manualOnly`: اختيار المشتري صراحةً للتحويل اليدوي (يُتحقق منه كأي مزوّد؛ لا يتجاوز تعطيله).
     *
     * @throws CommerceException provider_disabled عند عدم وجود مزوّد مفعَّل يقبل العملة
     */
    public function forNewPurchase(string $currency, bool $manualOnly = false): CommercePaymentProvider
    {
        $candidates = $manualOnly ? [ManualCommercePaymentProvider::KEY] : array_values(array_unique([$this->defaultKey(), $this->fallbackKey()]));

        foreach ($candidates as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $provider = $this->get($key);

            if ($provider->isEnabled() && $this->acceptsCurrency($provider, $currency)) {
                return $provider;
            }
        }

        throw new CommerceException('provider_disabled', 'طريقة الدفع غير متاحة حاليًا.');
    }

    /** تقاطع العملات: مسموح للتجارة ∧ يقبلها المزوّد. (قيد المسار نفسه يفحصه مالك المنتج.) */
    public function acceptsCurrency(CommercePaymentProvider $provider, string $currency): bool
    {
        $currency = strtoupper($currency);
        $commerce = array_map('strtoupper', (array) config('commerce.allowed_currencies', []));
        $accepted = $provider->currencies();

        return in_array($currency, $commerce, true) && ($accepted === null || in_array($currency, array_map('strtoupper', $accepted), true));
    }

    protected function allowed(string $key): bool
    {
        return $key !== SandboxCommercePaymentProvider::KEY || CommerceEnvironment::sandboxAllowed();
    }
}
