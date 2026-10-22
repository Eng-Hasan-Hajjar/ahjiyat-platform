<?php

namespace App\Services\Reputation;

/**
 * E37 - قارئ إعداد السمعة (config/reputation.php) مع **تحقّق صحة** قبل أي حساب:
 *  - كل مجموعة أوزان مجموعها 100 بالضبط وكل وزن ≥ 0.
 *  - كل سقف > 0؛ prior_mean ضمن 1..5؛ floor ضمن 1..5 (دون الحد الأعلى)؛ نافذة الصاعدين 1..365 يومًا؛ الحدود الدنيا ≥ 1.
 * إعداد غير سليم = استثناء ReputationConfigException (يوقف الأمر المجدول قبل أي كتابة؛ القراءة العلنية من اللقطة لا تتأثر ولا تحسب شيئًا).
 */
class ReputationSettings
{
    /** مجموعات الأوزان (مسار الإعداد) التي يجب أن يساوي مجموع كل منها 100. */
    public const WEIGHT_GROUPS = [
        'creator.weights', 'creator.engagement.weights', 'creator.consistency.weights',
        'rising.weights', 'curator.weights', 'helpfulness.weights',
    ];

    /** مجموعات السقوف (مسار الإعداد) التي يجب أن يكون كل سقف فيها > 0. */
    public const CAP_GROUPS = [
        'creator.engagement.caps', 'creator.consistency.caps', 'rising.caps', 'curator.caps', 'helpfulness.caps',
    ];

    public function enabled(): bool
    {
        return (bool) config('reputation.enabled', true);
    }

    public function scale(): int
    {
        return max(100, (int) config('reputation.scale', 10000));
    }

    public function formulaVersion(): int
    {
        return max(1, (int) config('reputation.formula_version', 1));
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return config('reputation.'.$path, $default);
    }

    public function float(string $path, float $default = 0.0): float
    {
        return (float) config('reputation.'.$path, $default);
    }

    public function int(string $path, int $default = 0): int
    {
        return (int) config('reputation.'.$path, $default);
    }

    /** @return array<string, int|float> */
    public function group(string $path): array
    {
        return (array) config('reputation.'.$path, []);
    }

    public function windowDays(): int
    {
        return max(1, $this->int('rising.window_days', 30));
    }

    /** @throws ReputationConfigException */
    public function assertValid(): void
    {
        foreach (self::WEIGHT_GROUPS as $path) {
            $weights = $this->group($path);

            if ($weights === []) {
                throw new ReputationConfigException("مجموعة الأوزان {$path} مفقودة.");
            }

            foreach ($weights as $name => $weight) {
                if (! is_numeric($weight) || $weight < 0) {
                    throw new ReputationConfigException("وزن غير صالح {$path}.{$name}.");
                }
            }

            if (abs(array_sum($weights) - 100) > 0.0001) {
                throw new ReputationConfigException("مجموع الأوزان في {$path} يجب أن يساوي 100 (الحالي ".array_sum($weights).').');
            }
        }

        foreach (self::CAP_GROUPS as $path) {
            $caps = $this->group($path);

            if ($caps === []) {
                throw new ReputationConfigException("مجموعة السقوف {$path} مفقودة.");
            }

            foreach ($caps as $name => $cap) {
                if (! is_numeric($cap) || $cap <= 0) {
                    throw new ReputationConfigException("سقف غير صالح {$path}.{$name}: يجب أن يكون أكبر من صفر.");
                }
            }
        }

        $mean = $this->float('creator.rating.prior_mean');
        $floor = $this->float('creator.rating.floor');

        if ($mean < 1 || $mean > 5 || $floor < 1 || $floor >= 5) {
            throw new ReputationConfigException('prior_mean/floor خارج المدى (1..5، والحد الأدنى دون 5).');
        }

        if ($this->float('creator.rating.prior_weight') < 0 || $this->float('rising.prior_weight') < 0) {
            throw new ReputationConfigException('prior_weight لا يكون سالبًا.');
        }

        if ($this->int('rising.window_days') < 1 || $this->int('rising.window_days') > 365) {
            throw new ReputationConfigException('نافذة الصاعدين بين 1 و365 يومًا.');
        }

        foreach (['creator.eligibility.min_works', 'creator.eligibility.min_ratings', 'creator.eligibility.min_raters', 'rising.eligibility.min_works', 'rising.eligibility.min_recent_ratings',
            'curator.eligibility.min_collections', 'curator.eligibility.min_savers', 'helpfulness.eligibility.min_comments', 'helpfulness.eligibility.min_likers',
            'curator.min_visible_items', 'refresh.batch', 'refresh.chunk', 'rankings.pool', 'rankings.list_limit'] as $path) {
            if ($this->int($path) < 1) {
                throw new ReputationConfigException("الحد {$path} يجب أن يكون 1 على الأقل.");
            }
        }

        if ($this->int('rankings.cache_seconds') < 0) {
            throw new ReputationConfigException('مدة الكاش لا تكون سالبة.');
        }
    }
}
