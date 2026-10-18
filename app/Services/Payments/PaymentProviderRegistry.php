<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;

/**
 * E32 - سجل المزوّدين (من config/payments.php). مفتاح فريد لكل مزوّد؛ الصنف يُحلّ من حاوية الخدمات (قابل للاستبدال/الحقن بالاختبارات والمستقبل).
 * مزوّد غير معروف أو معطَّل ⇒ PaymentException (يُعامل كأنه غير موجود). لا يوجد مزوّد وهمي في الإنتاج.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /** @return list<string> مفاتيح المزوّدين المعرَّفين بالإعداد (مفعّلين أو لا). */
    public function configured(): array
    {
        return array_map('strval', array_keys((array) config('payments.providers', [])));
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_values(array_filter($this->configured(), fn (string $key) => $this->isEnabled($key)));
    }

    public function isEnabled(string $key): bool
    {
        try {
            return (bool) config('payments.enabled', true) && $this->provider($key)->isEnabled();
        } catch (PaymentException) {
            return false;
        }
    }

    /** @throws PaymentException مزوّد غير معروف أو معطَّل */
    public function get(string $key): PaymentProvider
    {
        $provider = $this->provider($key);

        if (! (bool) config('payments.enabled', true) || ! $provider->isEnabled()) {
            throw new PaymentException('طريقة الدفع غير متاحة حاليًا.');
        }

        return $provider;
    }

    /** مزوّد معرَّف (حتى لو معطَّل) لقراءة الحالة/المطابقة. @throws PaymentException */
    public function provider(string $key): PaymentProvider
    {
        $definition = config("payments.providers.{$key}");

        if (! is_array($definition) || ! is_string($definition['class'] ?? null) || preg_match('/^[a-z0-9_]{1,32}$/', $key) !== 1) {
            throw new PaymentException('طريقة دفع غير معروفة.');
        }

        if (! array_key_exists($key, $this->resolved)) {
            $instance = $this->container->make($definition['class']);

            if (! $instance instanceof PaymentProvider || $instance->key() !== $key) {
                throw new PaymentException('إعداد مزوّد الدفع غير صالح.');
            }

            $this->resolved[$key] = $instance;
        }

        return $this->resolved[$key];
    }

    /** يسمح للاختبارات/المستقبل بحقن مزوّد (يُشترط توافق المفتاح مع الإعداد). */
    public function swap(string $key, PaymentProvider $provider): void
    {
        $this->resolved[$key] = $provider;
    }

    public function forget(): void
    {
        $this->resolved = [];
    }
}
