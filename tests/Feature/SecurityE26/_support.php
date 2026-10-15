<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| E26 - مساعدات مشتركة لاختبارات الأمان. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| لا شيء هنا يهاجم خدمة حقيقية: كل شيء محلي على قاعدة اختبار SQLite وكاش array.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../Teams/TeamTestHelpers.php';
require_once __DIR__.'/../Chat/ChatTestHelpers.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';
require_once __DIR__.'/../OperationsE25/_support.php';

if (! function_exists('e26_user')) {
    function e26_user(array $attrs = []): User
    {
        return e16User($attrs);
    }

    function e26_freeze(User $user): User
    {
        $user->forceFill(['is_frozen' => true])->save();

        return $user->refresh();
    }

    /** [متاح، معلّق] للعملة الافتراضية. @return array{0: int, 1: int} */
    function e26_wallet_state(\App\Models\User $user): array
    {
        $wallet = app(\App\Services\Economy\CurrencyWalletService::class)->balanceFor($user, app(\App\Services\Economy\CurrencyRegistry::class)->defaultEarnedCurrency())->refresh();

        return [(int) $wallet->available_balance, (int) $wallet->pending_balance];
    }

    /** يرسل طلبًا N مرة ويعيد قائمة رموز الحالة (للتحقق من لحظة بدء 429). @return list<int> */
    function e26_hammer($test, string $method, string|Closure $url, int $times, array|Closure $data = [], bool $json = false): array
    {
        $statuses = [];

        for ($i = 0; $i < $times; $i++) {
            $target = $url instanceof Closure ? $url($i) : $url;
            $payload = $data instanceof Closure ? $data($i) : $data;
            $call = $json ? $method.'Json' : $method;
            $statuses[] = $test->{$call}($target, $payload)->getStatusCode();
        }

        return $statuses;
    }

    /** أول فهرس (من 1) حصل على 429، أو null. */
    function e26_first_429(array $statuses): ?int
    {
        foreach ($statuses as $i => $status) {
            if ($status === 429) {
                return $i + 1;
            }
        }

        return null;
    }

    /** جدول رموز Sanctum غير موجود بالمستودع عمدًا (واجهة الموبايل خاملة). نُنشئه لاختبار الواجهة فقط ويُتراجَع عنه مع معاملة الاختبار. */
    function e26_sanctum_table(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /** كود PHP بلا تعليقات وسلاسل التوثيق (للتدقيقات الساكنة: التعليق قد يذكر الممنوع بالشرح). */
    function e26_php_code(string $file): string
    {
        $out = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** كل ملفات PHP تحت مجلدات المشروع المذكورة (نسبة لجذر المشروع). @return list<string> */
    function e26_php_files(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    function e26_relative(string $absolute): string
    {
        return str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absolute), '/\\'));
    }
}
