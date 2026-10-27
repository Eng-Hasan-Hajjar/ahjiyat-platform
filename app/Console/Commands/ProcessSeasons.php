<?php

namespace App\Console\Commands;

use App\Services\Seasons\SeasonProcessor;
use Illuminate\Console\Command;

/**
 * E42 - يلتقط ما ينتج عن مرور الوقت للمواسم: بدء المراحل (إشعار مرة واحدة)، الإعلانات المطلوب إشعارها، تجميد نافذة الموسم المؤرشف بالزمن.
 * لا منطق هنا: كل شيء بـSeasonProcessor (idempotent، ذري). يُجدوَل كل 5 دقائق؛ غيابه لا يكسر شيئًا (يتأخر الإشعار/التجميد فقط).
 */
class ProcessSeasons extends Command
{
    protected $signature = 'seasons:process';

    protected $description = 'معالجة المواسم المجدولة: بدء المراحل وإشعاراتها، الإعلانات المجدولة، وتجميد نافذة الموسم المؤرشف. Idempotent؛ يُجدوَل كل 5 دقائق.';

    public function handle(SeasonProcessor $processor): int
    {
        $stats = $processor->run();

        $this->info(sprintf(
            'مراحل بدأت وأُشعر بها: %d (بصمت: %d) · إعلانات أُرسلت: %d (بصمت: %d) · نوافذ جُمّدت: %d · أخطاء: %d.',
            $stats['phases_started'], $stats['phases_silent'], $stats['announcements_sent'], $stats['announcements_silent'], $stats['windows_frozen'], $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
