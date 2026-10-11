<?php

namespace App\Jobs\Concerns;

/**
 * E24: حدود إعادة المحاولة المشتركة لمهام الطابور (كلها دفعات idempotent بمفاتيح دلالية، فإعادة التشغيل آمنة).
 *  - timeout أقل من retry_after (90ث افتراضيًا في config/queue.php) كي لا يلتقط عامل آخر مهمة ما زالت تعمل فتتكرر.
 *  - failOnTimeout: المهمة التي تجاوزت المهلة تُسجَّل فاشلة (failed_jobs) بدل أن تعيد المحاولة بلا نهاية.
 *  - backoff متصاعد: لا عاصفة إعادة محاولات متزامنة عند عطل مؤقت (قاعدة بيانات/Redis).
 * tries تبقى معرَّفة في كل مهمة كما كانت.
 */
trait BoundedRetries
{
    public int $timeout = 80;

    public bool $failOnTimeout = true;

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180];
    }
}
