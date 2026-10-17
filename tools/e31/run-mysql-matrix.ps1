<#
.SYNOPSIS
  E31 - مصفوفة اختبارات MySQL المركّزة (Auth, Wallet/Economy, Store, Competitive, Chat, Notifications, Search E27, Creator E28, Engagement E29, Sponsorship E30, Creator Series E30, Database E31).

.DESCRIPTION
  يشغّل كل مجموعة بمسارات صريحة (لا اختبار كامل ولا أمر بلا مسار) على قاعدة MySQL اختبار مخصصة، ثم يطبع جدول نتائج، ثم يشغّل db:parity للقراءة فقط.
  لا يمسّ قاعدة العمل: يرفض أي اسم قاعدة لا ينتهي بـ _test أو _testing أو _test_mysql (يحمي RefreshDatabase من حذف جداول العمل).
  يحتاج PHP وملف vendor موجودين مسبقًا (لا يثبّت شيئًا ولا ينفّذ composer/npm). لا يطبع كلمة المرور.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools/e31/run-mysql-matrix.ps1 -Username root -Password ""

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File tools/e31/run-mysql-matrix.ps1 -Only Auth,Store -SkipCreate
#>
[CmdletBinding()]
param(
    [string]$DbHost = '127.0.0.1',
    [int]$Port = 3306,
    [string]$Database = 'ahjiyat_platform_test_mysql',
    [string]$Username = 'root',
    [string]$Password = '',
    # لا تُنشئ القاعدة (إن كانت موجودة سلفًا أو لا تملك صلاحية CREATE DATABASE)
    [switch]$SkipCreate,
    # أسماء مجموعات محددة فقط (مثل Auth,Store). الافتراضي: كلها
    [string[]]$Only = @(),
    # توقف عند أول مجموعة فاشلة
    [switch]$StopOnFail
)

$ErrorActionPreference = 'Stop'

# ── حواجز السلامة ─────────────────────────────────────────────────────────────
if ($Database -notmatch '(^|_)(test|testing)(_(mysql|mariadb))?$') {
    Write-Host "مرفوض: اسم القاعدة '$Database' لا ينتهي بـ _test أو _testing أو _test_mysql. هذه الأداة تحذف كل جداول القاعدة المختارة (migrate:fresh) فلا تُوجَّه لقاعدة العمل." -ForegroundColor Red
    exit 2
}
if ($Database -notmatch '^[A-Za-z0-9_]{1,50}$') {
    Write-Host "مرفوض: اسم القاعدة يجب أن يتألف من حروف لاتينية وأرقام وشرطة سفلية فقط (حتى 50 خانة)." -ForegroundColor Red
    exit 2
}

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location $root

if (-not (Test-Path 'vendor\bin\pest')) {
    Write-Host 'لم أجد vendor\bin\pest: شغّل composer install مرة واحدة يدويًا في مشروعك أولًا (هذه الأداة لا تثبّت شيئًا).' -ForegroundColor Red
    exit 2
}
if (-not (Test-Path 'phpunit.mysql.xml.dist')) {
    Write-Host 'لم أجد phpunit.mysql.xml.dist في جذر المشروع.' -ForegroundColor Red
    exit 2
}

# بيئة هذه الجلسة فقط (تتقدّم على .env وعلى افتراضات phpunit.mysql.xml.dist)
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = $DbHost
$env:DB_PORT = "$Port"
$env:DB_DATABASE = $Database
$env:DB_USERNAME = $Username
$env:DB_PASSWORD = $Password
$env:DB_TIMEZONE = '+00:00'
$env:APP_ENV = 'testing'

# ── إنشاء قاعدة الاختبار (utf8mb4) ───────────────────────────────────────────
if (-not $SkipCreate) {
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("e31-create-db-" + [guid]::NewGuid().ToString('N') + '.php')
    $script = @'
<?php
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';charset=utf8mb4';
try {
    $pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = getenv('DB_DATABASE');
    if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $name)) { fwrite(STDERR, "bad name\n"); exit(2); }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $v = $pdo->query('select version()')->fetchColumn();
    echo 'MySQL server version: ' . $v . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'تعذّر الاتصال/الإنشاء: ' . get_class($e) . ' (رمز ' . $e->getCode() . ')' . PHP_EOL);
    exit(1);
}
'@
    [System.IO.File]::WriteAllText($tmp, $script, (New-Object System.Text.UTF8Encoding($false)))   # UTF-8 بلا BOM كي لا يطبع PHP بايتات زائدة
    try {
        & php $tmp
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'تعذّر الوصول إلى خادم MySQL. تأكد أنه يعمل وأن المضيف/المنفذ/المستخدم/كلمة المرور صحيحة (انظر docs/mysql-production-e31.md، قسم الإعداد المحلي).' -ForegroundColor Red
            exit 2
        }
    } finally {
        Remove-Item -Path $tmp -ErrorAction SilentlyContinue
    }
}

# ── المجموعات (مسارات صريحة فقط) ────────────────────────────────────────────
$groups = [ordered]@{
    'Auth'                = @('tests/Feature/Auth', 'tests/Feature/Rbac')
    'WalletEconomy'       = @('tests/Feature/Economy', 'tests/Feature/GemWalletServiceTest.php', 'tests/Feature/RedemptionServiceTest.php', 'tests/Feature/PuzzleAttemptServiceTest.php', 'tests/Feature/GameSessionTest.php')
    'Store'               = @('tests/Feature/Store')
    'Competitive'         = @('tests/Feature/Competitive', 'tests/Feature/CompetitiveRewards', 'tests/Feature/TeamCompetition')
    'Chat'                = @('tests/Feature/Chat')
    'Notifications'       = @('tests/Feature/Notifications')
    'SearchE27'           = @('tests/Feature/DiscoveryE27')
    'CreatorE28'          = @('tests/Feature/CreatorE28')
    'EngagementE29'       = @('tests/Feature/EngagementE29')
    'SponsorshipE30'      = @('tests/Feature/SponsorshipE30')
    'CreatorSeriesE30'    = @('tests/Feature/CreatorSeriesE30')
    'DatabaseE31'         = @('tests/Feature/DatabaseE31', 'tests/Unit/TestDatabaseGuardTest.php')
}

$selected = @($groups.Keys)
if ($Only.Count -gt 0) {
    $selected = @($groups.Keys | Where-Object { $Only -contains $_ })
    $unknown = @($Only | Where-Object { -not $groups.Contains($_) })
    if ($unknown.Count -gt 0) {
        Write-Host ("مجموعات غير معروفة: " + ($unknown -join ', ') + ". المتاح: " + (@($groups.Keys) -join ', ')) -ForegroundColor Red
        exit 2
    }
}

Write-Host ''
Write-Host "== E31 MySQL matrix : $DbHost`:$Port / $Database (user: $Username) ==" -ForegroundColor Cyan

$results = @()
$failed = $false

foreach ($name in $selected) {
    $paths = $groups[$name]
    Write-Host ''
    Write-Host ("-- {0}: {1}" -f $name, ($paths -join ' ')) -ForegroundColor Cyan
    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    & php vendor/bin/pest --configuration=phpunit.mysql.xml.dist @paths
    $code = $LASTEXITCODE
    $watch.Stop()
    $results += [pscustomobject]@{ Group = $name; Result = $(if ($code -eq 0) { 'PASS' } else { 'FAIL' }); Seconds = [math]::Round($watch.Elapsed.TotalSeconds, 1) }
    if ($code -ne 0) {
        $failed = $true
        if ($StopOnFail) { break }
    }
}

Write-Host ''
Write-Host '== Summary ==' -ForegroundColor Cyan
$results | Format-Table -AutoSize

# ── db:parity (قراءة فقط) على القاعدة التي بنتها الاختبارات ────────────────────
Write-Host '-- db:parity (read-only) on the migrated test database' -ForegroundColor Cyan
& php artisan db:parity --connection=mysql --runtime
$parity = $LASTEXITCODE
if ($parity -ne 0) { $failed = $true }

Write-Host ''
if ($failed) {
    Write-Host 'RESULT: FAIL - أرسل لي مخرجات المجموعات الفاشلة (دون كلمات مرور).' -ForegroundColor Red
    exit 1
}
Write-Host 'RESULT: PASS - هذا هو الدليل التشغيلي على MySQL الذي يحتاجه إغلاق E31.' -ForegroundColor Green
exit 0
