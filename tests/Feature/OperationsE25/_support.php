<?php

use Illuminate\Support\Facades\Artisan;

/*
| E25 - مساعدات مشتركة لاختبارات العمليات. ليست ملف اختبار (لا تنتهي بـTest.php) فلا يشغّلها Pest وحدها؛ تُحمَّل بـrequire_once.
*/

if (! function_exists('e25_check')) {
    /**
     * يشغّل أمرًا تشغيليًا بصيغة JSON ويعيد [exitCode, data, rawOutput].
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    function e25_run(string $command, array $options = []): array
    {
        $code = Artisan::call($command, array_merge(['--json' => true], $options));
        $raw = Artisan::output();
        $data = json_decode($raw, true);

        return [$code, is_array($data) ? $data : [], $raw];
    }

    /** نتائج فحص production:check كاملة. */
    function e25_check(array $config = []): array
    {
        if ($config !== []) {
            config($config);
        }

        return e25_run('production:check');
    }

    /** كل حالات إعداد معيّن (قد يملك الإعداد الواحد أكثر من نتيجة). @return list<string> */
    function e25_statuses(array $data, string $setting): array
    {
        return array_values(array_map(
            fn (array $c) => $c['status'],
            array_filter($data['checks'] ?? [], fn (array $c) => $c['setting'] === $setting),
        ));
    }

    /** يحوّل الإنتاج: بيئة التطبيق + config('app.env'). */
    function e25_as_production(): void
    {
        config(['app.env' => 'production']);
        app()['env'] = 'production';
    }

    function e25_repo_file(string $relative): string
    {
        return (string) file_get_contents(base_path($relative));
    }

    /** هل يمكن تشغيل bash حقيقي (سكربتات tools/*) هنا؟ ويندوز/XAMPP لا. */
    function e25_can_run_bash(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $out = @shell_exec('command -v bash 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
