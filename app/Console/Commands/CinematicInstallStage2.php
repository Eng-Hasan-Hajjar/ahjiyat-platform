<?php

namespace App\Console\Commands;

use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\Demo\Stage2ContentInstaller;
use Illuminate\Console\Command;

class CinematicInstallStage2 extends Command
{
    protected $signature = 'cinematic:install-stage2
        {--apply : تنفيذ الإضافة فعليًا (بدونه: معاينة فقط بلا أي كتابة)}
        {--create-draft : إنشاء مسودة جديدة إن لم توجد مسودة مفتوحة}
        {--story= : slug القصة المستهدفة (الافتراضي من القالب: unwritten-page)}
        {--yes-production : تأكيد صريح للتشغيل في بيئة الإنتاج}';

    protected $description = 'CIN-2: إضافة المرحلة الثانية «الصورة المفقودة» (7 فصول) إلى مسودة القصة الموجودة. الافتراضي معاينة بلا كتابة. لا ينشر ولا يربط وسائط ولا يمس المرحلة الأولى.';

    public function handle(Stage2ContentInstaller $installer): int
    {
        $slug = $this->option('story') ?: null;

        try {
            if (! $this->option('apply')) {
                $plan = $installer->plan($slug);
                $this->info('معاينة فقط — لم يُكتب شيء في قاعدة البيانات.');
                $this->line('القصة: '.($plan['slug'] ?? '—').' | الحالة: '.$plan['status']);
                $this->line('سيُضاف: '.$plan['counts']['stages'].' مرحلة | '.$plan['counts']['chapters'].' فصول | '.$plan['counts']['scenes'].' مشهدًا');

                foreach ($plan['problems'] as $p) {
                    $this->warn('  - '.$p);
                }

                foreach ($plan['notes'] ?? [] as $n) {
                    $this->line('  * '.$n);
                }

                $this->line($plan['status'] === 'ready' ? 'للتطبيق أعد التشغيل مع --apply.' : ($plan['status'] === 'already_applied' ? 'المرحلة الثانية مضافة مسبقًا؛ لا شيء للتطبيق.' : 'لا يمكن التطبيق قبل معالجة ما سبق.'));

                return $plan['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
            }

            $result = $installer->install($slug, (bool) $this->option('create-draft'), (bool) $this->option('yes-production'));
        } catch (CinematicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $readiness = $result['readiness'];
        $this->info($result['created'] ? 'أُضيفت المرحلة الثانية كمسودة إلى: '.$result['story']->title : 'المرحلة الثانية مضافة مسبقًا؛ لم يُعدَّل شيء.');
        $this->line('المراحل: '.$readiness['counts']['stages'].' | الفصول: '.$readiness['counts']['chapters'].' | المشاهد: '.$readiness['counts']['scenes']);
        $this->line('الحالة: '.($readiness['ok'] ? 'جاهزة للنشر' : 'غير جاهزة للنشر (متوقع: فيديوهات/صور/نصوص بانتظار الوثيقة والإنتاج)'));

        foreach (array_slice($readiness['blockers'], 0, 40) as $blocker) {
            $this->line('  - '.$blocker['message']);
        }

        $this->line('لم يُنشر شيء ولم تُربط وسائط. المعاينة من لوحة الإدارة (الأحجيات ← السلاسل السينمائية).');

        return self::SUCCESS;
    }
}
