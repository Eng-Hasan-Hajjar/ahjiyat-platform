<?php

namespace App\Console\Commands;

use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\Demo\StageAddonInstaller;
use Illuminate\Console\Command;

class CinematicInstallStage extends Command
{
    protected $signature = 'cinematic:install-stage
        {stage : رقم المرحلة (3 = الساعة المتوقفة، 4 = الأبواب الثلاثة)}
        {--apply : تنفيذ الإضافة فعليًا (بدونه: معاينة فقط بلا أي كتابة)}
        {--create-draft : إنشاء مسودة جديدة إن لم توجد مسودة مفتوحة}
        {--story= : slug القصة المستهدفة (الافتراضي من القالب: unwritten-page)}
        {--link=* : ربط مفتاح معلّق بمشهد حقيقي عند التثبيت، بصيغة pending_key=real_scene_key (يتكرر)}
        {--yes-production : تأكيد صريح للتشغيل في بيئة الإنتاج}';

    protected $description = 'PLM-3/4: إضافة المرحلة الثالثة «الساعة المتوقفة» أو الرابعة «الأبواب الثلاثة» إلى مسودة القصة الموجودة. الافتراضي معاينة بلا كتابة. لا ينشر ولا يربط وسائط ولا يعدّل أي مشهد موجود.';

    public function handle(StageAddonInstaller $installer): int
    {
        $stage = (int) $this->argument('stage');
        $slug = $this->option('story') ?: null;
        $links = [];

        foreach ((array) $this->option('link') as $pair) {
            if (! is_string($pair) || substr_count($pair, '=') !== 1 || str_starts_with($pair, '=') || str_ends_with($pair, '=')) {
                $this->error("صيغة --link غير صالحة: «{$pair}». المطلوب pending_key=real_scene_key.");

                return self::FAILURE;
            }

            [$k, $v] = explode('=', $pair, 2);
            $links[trim($k)] = trim($v);
        }

        try {
            if (! $this->option('apply')) {
                $plan = $installer->plan($stage, $slug, $links);
                $this->info('معاينة فقط — لم يُكتب شيء في قاعدة البيانات.');
                $this->line('المرحلة: '.$stage.' | القصة: '.($plan['slug'] ?? '—').' | الحالة: '.$plan['status']);
                $this->line('سيُضاف: '.$plan['counts']['stages'].' مرحلة | '.$plan['counts']['chapters'].' فصول | '.$plan['counts']['scenes'].' مشهدًا');

                foreach ($plan['problems'] as $p) {
                    $this->warn('  - '.$p);
                }

                foreach ($plan['notes'] as $n) {
                    $this->line('  * '.$n);
                }

                $this->printPending($plan['pending']);
                $this->line($plan['status'] === 'ready' ? 'للتطبيق أعد التشغيل مع --apply.' : ($plan['status'] === 'already_applied' ? 'المرحلة مضافة مسبقًا؛ لا شيء للتطبيق.' : 'لا يمكن التطبيق قبل معالجة ما سبق.'));

                return $plan['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
            }

            $result = $installer->install($stage, $slug, (bool) $this->option('create-draft'), (bool) $this->option('yes-production'), $links);
        } catch (CinematicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $readiness = $result['readiness'];
        $this->info($result['created'] ? "أُضيفت المرحلة {$stage} كمسودة إلى: ".$result['story']->title : "المرحلة {$stage} مضافة مسبقًا؛ لم يُعدَّل شيء.");
        $this->line('المراحل: '.$readiness['counts']['stages'].' | الفصول: '.$readiness['counts']['chapters'].' | المشاهد: '.$readiness['counts']['scenes']);
        $this->line('الحالة: '.($readiness['ok'] ? 'جاهزة للنشر' : 'غير جاهزة للنشر (متوقع: وسائط بانتظار تسليم محمد، وربط معلّق مع نهاية الثانية المنقّحة)'));
        $this->printPending($result['pending']);

        foreach (array_slice($readiness['blockers'], 0, 40) as $blocker) {
            $this->line('  - '.$blocker['message']);
        }

        $this->line('لم يُنشر شيء ولم تُربط وسائط. المعاينة من لوحة الإدارة (الأحجيات ← السلاسل السينمائية).');

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $pending */
    private function printPending(array $pending): void
    {
        foreach ($pending as $p) {
            $this->line('  ربط معلّق '.$p['key'].': '.($p['resolved'] ? 'مربوط بالمشهد '.$p['linked_to'] : 'غير محلول — '.$p['meaning'].' (مشاهد: '.implode(', ', $p['gates']).')'));
        }
    }
}
