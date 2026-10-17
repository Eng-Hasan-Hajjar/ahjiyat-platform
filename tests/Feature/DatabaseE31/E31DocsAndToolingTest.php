<?php

require_once __DIR__.'/_support.php';

/*
| E31 - الوثائق والأدوات المرافقة: الدليل موجود بأقسامه الجوهرية ولا يدّعي نجاحًا لم يتحقق، .env.example يعرض MySQL بلا أسرار،
| إعداد اختبار MySQL يحمي قاعدة العمل، سكربت المصفوفة يرفض أسماء غير الاختبار، وCI فيه مهمة MySQL بلا أسرار.
*/

function e31_read(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

test('Y-1: the MySQL production guide covers the required procedures and keeps unvalidated MySQL items marked as pending', function () {
    $doc = e31_read('docs/mysql-production-e31.md');

    foreach (['8.0.16', 'utf8mb4_unicode_ci', 'db:parity', 'db:transfer-sqlite-to-mysql', 'Get-FileHash', 'Copy-Item', 'PRAGMA wal_checkpoint', 'TransactionPolicy', 'FinancialHistoryGuard',
        'OPS_ALLOW_SQLITE_PRODUCTION', 'explain-audit.sql', 'run-mysql-matrix.ps1', 'APP_KEY', 'التراجع', 'Cutover'] as $needle) {
        expect($doc)->toContain($needle);
    }

    // 12 خطوة انتقال
    preg_match('/## 9\).*?### التراجع/su', $doc, $cutover);
    expect(preg_match_all('/^\d+\.\s/m', $cutover[0] ?? ''))->toBe(12);

    expect($doc)->toContain('PENDING USER-LOCAL MYSQL VALIDATION');
});

test('Y-2: documents embed no real secret - only placeholders for passwords', function () {
    $doc = e31_read('docs/mysql-production-e31.md');

    preg_match_all('/(?:PASSWORD|MYSQL_PWD|IDENTIFIED BY|-Password)\s*[=\']\s*[\'"]?([^\s\'";`)]+)/i', $doc, $matches);

    foreach ($matches[1] as $value) {
        expect(preg_match('/^(<[^>]+>|\$[A-Za-z_:]+|)$/u', $value))->toBe(1, "قيمة غير placeholder: {$value}");
    }
});

test('Y-3: .env.example shows MySQL as the primary database with an empty password and no real secret', function () {
    $env = e31_read('.env.example');

    expect($env)->toContain('DB_CONNECTION=mysql')->toContain('DB_DATABASE=ahjiyat_platform')->toContain('DB_PASSWORD=')->toContain('DB_TIMEZONE=+00:00')
        ->toContain('docs/mysql-production-e31.md');
    expect(preg_match('/^DB_PASSWORD=\s*$/m', $env))->toBe(1);
});

test('Y-4: the MySQL phpunit configuration forces the mysql driver, uses a guarded test database name and keeps SQLite as the default suite', function () {
    $mysql = e31_read('phpunit.mysql.xml.dist');
    $default = e31_read('phpunit.xml.dist');

    expect($mysql)->toContain('name="DB_CONNECTION" value="mysql" force="true"')
        ->and($mysql)->toContain('value="ahjiyat_platform_test_mysql"')
        ->and($mysql)->not->toContain('value="ahjiyat_platform"')
        ->and($default)->toContain('name="DB_CONNECTION" value="sqlite"');

    // الاسم الافتراضي في الإعداد يمرّ من حارس قاعدة الاختبار، واسم قاعدة العمل لا يمرّ
    expect(\Tests\Support\TestDatabaseGuard::isAllowed('mysql', 'ahjiyat_platform_test_mysql'))->toBeTrue()
        ->and(\Tests\Support\TestDatabaseGuard::isAllowed('mysql', 'ahjiyat_platform'))->toBeFalse();

    $xml = new DOMDocument;
    expect($xml->loadXML($mysql))->toBeTrue();
});

test('Y-5: the matrix script refuses any database name that is not an explicit test database, never installs anything and never runs an unscoped test command', function () {
    $script = e31_read('tools/e31/run-mysql-matrix.ps1');

    expect($script)->toContain("(^|_)(test|testing)(_(mysql|mariadb))?$")
        ->and($script)->not->toMatch('/^\s*&?\s*(composer|npm|git|npx)\b/mi')   // لا يستدعي أيًّا منها (الإشارة إليها في رسالة نصية مقبولة)
        ->and($script)->not->toMatch('/pest\s*$|pest\s+--configuration=\S+\s*$/m');   // كل استدعاء pest يمرّر مسارات

    foreach (['tests/Feature/Auth', 'tests/Feature/Store', 'tests/Feature/DiscoveryE27', 'tests/Feature/CreatorE28', 'tests/Feature/EngagementE29', 'tests/Feature/SponsorshipE30', 'tests/Feature/CreatorSeriesE30', 'tests/Feature/Chat', 'tests/Feature/Notifications', 'tests/Feature/Competitive', 'tests/Feature/Economy', 'tests/Feature/DatabaseE31'] as $path) {
        expect($script)->toContain($path);
    }

    // الملف يبدأ بـBOM (عربي في PowerShell 5.1)
    expect(substr(file_get_contents(base_path('tools/e31/run-mysql-matrix.ps1')), 0, 3))->toBe("\xEF\xBB\xBF");
});

test('Y-6: CI has a MySQL job with test-only credentials, no repository secrets, and still keeps the SQLite job', function () {
    $ci = e31_read('.github/workflows/ci.yml');
    $live = preg_replace('/^\s*#.*$/m', '', $ci);

    expect($ci)->toContain('mysql-parity:')->toContain('image: mysql:8.0')->toContain('validate:')
        ->and($live)->not->toContain('secrets.')
        ->and($live)->toContain('phpunit.mysql.xml.dist')
        ->and($live)->toContain('tests/Feature/DatabaseE31');
});

test('Y-7: the EXPLAIN audit file is read-only SQL (EXPLAIN statements only) and names the expected index for each query', function () {
    $sql = e31_read('tools/e31/explain-audit.sql');
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', preg_replace('/^\s*--.*$/m', '', $sql)))));

    expect(count($statements))->toBeGreaterThanOrEqual(15);

    foreach ($statements as $statement) {
        expect(preg_match('/^(SET NAMES utf8mb4|EXPLAIN\s+SELECT)\b/i', $statement))->toBe(1, substr($statement, 0, 60));
        expect(preg_match('/\b(INSERT|UPDATE\s+\w+\s+SET|DELETE|DROP|ALTER|TRUNCATE|CREATE)\b/i', preg_replace('/FOR UPDATE/i', '', $statement)))->toBe(0);
    }

    // كل فهرس متوقَّع مذكور في التعليقات موجود فعلًا في المخطط المترجم (لا يشير الملف إلى فهرس غير موجود)
    $compiled = (new \App\Services\Database\MysqlDdlCompiler)->compile();
    $model = (new \App\Services\Database\MysqlDdlLinter)->analyze($compiled['statements'])['tables'];
    $existing = [];

    foreach ($model as $table) {
        foreach (array_keys($table['indexes']) as $index) {
            $existing[$index] = true;
        }
    }

    preg_match_all('/المتوقع:\s*([a-z0-9_]+)/', $sql, $named);

    foreach ($named[1] as $index) {
        expect(isset($existing[$index]))->toBeTrue("الفهرس {$index} المذكور في explain-audit.sql غير موجود في المخطط");
    }
});
