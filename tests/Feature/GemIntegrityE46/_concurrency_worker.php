<?php

/*
| E46 - عامل (عملية PHP مستقلة) لاختبار التزامن الحقيقي على MySQL. ليس ملف اختبار؛ يشغّله ConcurrencyMysqlTest عبر proc_open
| فتكون لكل عامل جلسة MySQL ومعاملة وقفل خاص به (لا اتصال مشترك ولا محاكاة داخل العملية).
|
| الاستعمال (للاختبار فقط): php _concurrency_worker.php '<json>'   مع متغيرات DB_* تشير إلى قاعدة اختبار مؤقتة منتهية بـ_test.
| يرفض العمل إن لم يكن اسم القاعدة قاعدة اختبار صريحة (لا يلمس قاعدة العمل أبدًا). يطبع سطر JSON واحدًا أخيرًا بالنتيجة.
*/

use App\Models\Currency;
use App\Models\RedemptionRequest;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\PendingGemReleaseService;
use App\Services\RedemptionService;

require __DIR__.'/../../../vendor/autoload.php';

$args = json_decode($argv[1] ?? '', true);

if (! is_array($args) || ! isset($args['action'])) {
    fwrite(STDERR, "bad args\n");
    exit(2);
}

$database = (string) getenv('DB_DATABASE');

if (! preg_match('/(^|_)(test|testing)(_(mysql|mariadb))?$/i', $database)) {
    fwrite(STDERR, "refusing to run against a non-test database: {$database}\n");
    exit(3);
}

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$startAt = (float) ($args['start_at'] ?? 0);

while (microtime(true) < $startAt) {
    usleep(200);
}

$result = ['ok' => true, 'action' => $args['action'], 'pid' => getmypid(), 't_start' => microtime(true)];

try {
    $wallets = app(CurrencyWalletService::class);

    switch ($args['action']) {
        case 'debit':
            $wallets->debitAvailable(User::findOrFail($args['user_id']), Currency::findOrFail($args['currency_id']), (int) $args['amount'], 'e46_conc_debit');
            break;

        case 'release':
            $tx = $wallets->releasePending(User::findOrFail($args['user_id']), Currency::findOrFail($args['currency_id']), (int) $args['amount'], 'e46_conc_release');
            $result['released'] = $tx?->amount;
            break;

        case 'release_job':
            $result['stats'] = app(PendingGemReleaseService::class)->run();
            break;

        case 'race_redemption':
            // نفس تسلسل إجراء Filament: فحص الحالة بقراءة غير مقفولة، ثم تنفيذ الخدمة. المتراس يضمن أن العاملَين اجتازا الفحص قبل أن ينفّذ أيٌّ منهما.
            $request = RedemptionRequest::findOrFail($args['request_id']);
            $admin = User::findOrFail($args['admin_id']);
            $expected = $args['op'] === 'reject' ? RedemptionRequest::STATUS_PENDING : RedemptionRequest::STATUS_APPROVED;
            $result['passed_check'] = $request->fresh()->status === $expected;

            if (! $result['passed_check']) {
                break;
            }

            touch($args['barrier_dir'].'/checked-'.$args['id']);
            $deadline = microtime(true) + 20;

            while (! file_exists($args['barrier_dir'].'/checked-'.$args['other']) && microtime(true) < $deadline) {
                usleep(500);
            }

            $result['other_checked'] = file_exists($args['barrier_dir'].'/checked-'.$args['other']);
            $service = app(RedemptionService::class);
            $args['op'] === 'reject' ? $service->reject($request, $admin, 'race') : $service->markFulfilled($request, $admin);
            break;

        case 'churn':
            // حركات حقيقية عبر الخدمة على محافظ محدَّدة طوال مدة معيّنة (تُستعمل لقياس إنذارات التدقيق الكاذبة أثناء الحركة).
            $ops = 0;
            $currency = Currency::findOrFail($args['currency_id']);
            $users = User::whereIn('id', $args['user_ids'])->get()->all();
            $end = $startAt + (float) $args['duration'];
            mt_srand((int) ($args['seed'] ?? 1));

            while (microtime(true) < $end) {
                $user = $users[mt_rand(0, count($users) - 1)];

                try {
                    match (mt_rand(0, 4)) {
                        0, 1 => $wallets->creditPending($user, $currency, mt_rand(1, 20), 'e46_conc_earn'),
                        2 => $wallets->releasePending($user, $currency, mt_rand(1, 15), 'e46_conc_release'),
                        3 => $wallets->debitAvailable($user, $currency, mt_rand(1, 10), 'e46_conc_debit'),
                        default => $wallets->refund($user, $currency, mt_rand(1, 5), 'redemption_rejected:0'),
                    };
                } catch (Throwable) {
                    // رصيد غير كافٍ ونحوه: متوقَّع ولا يُحتسب
                }

                $ops++;
            }

            $result['ops'] = $ops;
            break;

        default:
            throw new InvalidArgumentException('unknown action '.$args['action']);
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['error'] = get_class($e);
    $result['message'] = mb_substr($e->getMessage(), 0, 200);
}

$result['t_end'] = microtime(true);
echo "\n".json_encode($result, JSON_UNESCAPED_UNICODE)."\n";
