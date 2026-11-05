<?php

namespace App\Console\Commands;

use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\Demo\Stage1DemoInstaller;
use Illuminate\Console\Command;

class CinematicInstallDemo extends Command
{
    protected $signature = 'cinematic:install-demo';

    protected $description = 'CIN-1: تثبيت مسودة المرحلة الأولى التجريبية (المكتبة الغامضة) في بيئة تطوير معزولة. لا تنشر ولا تُنشئ فيديو، وترفض الإنتاج.';

    public function handle(Stage1DemoInstaller $installer): int
    {
        try {
            $result = $installer->install();
        } catch (CinematicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $readiness = $result['readiness'];

        $this->info($result['created']
            ? 'تم تثبيت المرحلة الأولى كمسودة: '.$result['story']->title
            : 'المحتوى موجود مسبقًا؛ لم يُعدَّل شيء: '.$result['story']->title);

        $this->line('المراحل: '.$readiness['counts']['stages'].' | الفصول: '.$readiness['counts']['chapters'].' | المشاهد: '.$readiness['counts']['scenes']);
        $this->line('الحالة: '.($readiness['ok'] ? 'جاهزة للنشر' : 'غير جاهزة للنشر (متوقع: الفيديوهات بانتظار الإنتاج)'));

        foreach ($readiness['blockers'] as $blocker) {
            $this->line('  - '.$blocker['message']);
        }

        $this->line('لم يُنشر شيء. المعاينة من لوحة الإدارة (الأحجيات ← السلاسل السينمائية).');

        return self::SUCCESS;
    }
}
