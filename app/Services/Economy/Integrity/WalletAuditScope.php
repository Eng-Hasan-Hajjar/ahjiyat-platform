<?php

namespace App\Services\Economy\Integrity;

/**
 * E46: نطاق تدقيق سلامة الجواهر (قيمة ثابتة، لا اتصال بالقاعدة).
 *
 * - userId: مستخدم واحد (أو null لكل المستخدمين).
 * - currencyIds: قائمة معرّفات عملات (أو null لكل العملات). ملاحظة: الافتراضي عند التشغيل من الأمر هو عملة «الجواهر المكتسبة» الافتراضية فقط؛
 *   مرِّر null صراحة لتدقيق كل العملات، وكل عملة تُدقَّق بمعزل عن غيرها (التجميع بـ user_id + currency_id).
 * - chunk: عدد المحافظ في الدفعة (لا يُحمَّل أكثر منه في الذاكرة). الترتيب حتمي بمعرّف المحفظة.
 * - graceSeconds: نافذة «حركة جارية»؛ فرق ظهر على محفظة تحرّكت خلالها (أو تغيّرت قراءتها بين المرورين) لا يُحسم ويُصنَّف «لا يمكن الجزم».
 * - includeOrphans: فحص مجموعات دفتر بلا صفّ محفظة.
 */
final class WalletAuditScope
{
    public const DEFAULT_CHUNK = 500;

    public const DEFAULT_GRACE_SECONDS = 120;

    public readonly int $chunk;

    public readonly int $graceSeconds;

    /** @var list<int>|null */
    public readonly ?array $currencyIds;

    /** @param  list<int>|null  $currencyIds */
    public function __construct(
        public readonly ?int $userId = null,
        ?array $currencyIds = null,
        int $chunk = self::DEFAULT_CHUNK,
        int $graceSeconds = self::DEFAULT_GRACE_SECONDS,
        public readonly bool $includeOrphans = true,
    ) {
        $this->currencyIds = $currencyIds === null ? null : array_values(array_unique(array_map('intval', $currencyIds)));
        $this->chunk = max(1, $chunk);
        $this->graceSeconds = max(0, $graceSeconds);
    }

    /** تسمية مختصرة للنطاق تُحفَظ مع نتيجة الفحص (لا تحوي بيانات شخصية). */
    public function kind(): string
    {
        if ($this->userId !== null) {
            return 'user';
        }

        return $this->currencyIds === null ? 'all_currencies' : 'currencies';
    }

    /** نطاق «كامل» = لا يقتصر على مستخدم واحد؛ وحده يصلح أن يكون «آخر فحص موثوق» في بطاقة المركز. */
    public function isFullCoverage(): bool
    {
        return $this->userId === null;
    }
}
