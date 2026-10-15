<?php

namespace App\Services\Operations;

/**
 * E25 - مجموعة نتائج الفحص. تُحوَّل لنص/JSON بعد تمريرها على SecretRedactor في الأوامر.
 * القاعدة الوحيدة للخروج: أي FAIL => الإصدار/البيئة غير جاهز (exit 1)؛ WARN وINFO لا يمنعان.
 */
final class CheckReport
{
    /** @var list<CheckResult> */
    private array $results = [];

    public function __construct(private readonly bool $production = false) {}

    public function add(CheckResult $result): void
    {
        $this->results[] = $result;
    }

    public function merge(self $other): void
    {
        foreach ($other->results() as $result) {
            $this->results[] = $result;
        }
    }

    /** @return list<CheckResult> */
    public function results(): array
    {
        return $this->results;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    public function count(string $status): int
    {
        return count(array_filter($this->results, fn (CheckResult $r) => $r->status === $status));
    }

    public function failures(): int
    {
        return $this->count(CheckResult::FAIL);
    }

    public function warnings(): int
    {
        return $this->count(CheckResult::WARN);
    }

    public function hasFailures(): bool
    {
        return $this->failures() > 0;
    }

    /** @return array<string, list<CheckResult>> */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->results as $result) {
            $groups[$result->group][] = $result;
        }

        return $groups;
    }

    /** @return array{production: bool, ready: bool, summary: array<string, int>, checks: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'production' => $this->production,
            'ready' => ! $this->hasFailures(),
            'summary' => [
                'pass' => $this->count(CheckResult::PASS),
                'warn' => $this->warnings(),
                'fail' => $this->failures(),
                'info' => $this->count(CheckResult::INFO),
            ],
            'checks' => array_map(fn (CheckResult $r) => $r->toArray(), $this->results),
        ];
    }
}
