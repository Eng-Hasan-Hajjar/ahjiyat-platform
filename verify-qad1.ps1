<#
  verify-qad1.ps1 : حزمة التحقق الإلزامية لـQA-D1 (بيانات Demo/QA). تُشغَّل من جذر المشروع **قبل** git add / commit / push:
      powershell -ExecutionPolicy Bypass -File .\verify-qad1.ps1
  PASS وكود خروج 0 فقط إن نجحت كل الفحوص. ممنوع الدفع قبل PASS. الأداة والبصمات أدوات محلية: لا تُضاف لـGit.
  كل الأوامر (composer وphp) تُشغَّل **مباشرة بالواجهة** (لا Start-Job): ثبت أن composer يتعلّق داخل المهام الخلفية على ويندوز.
  مفتاح اختياري: -WithRegressions (انحدارات مستهدفة إضافية: الفرق والتحدّيات والأدوار). لا حاجة له عادةً: الحزمة بيانات فقط.
#>
param(
    [switch]$WithRegressions,
    [string]$BaseSha = '423595c960af2081a15f85077b78d525bdde460a',
    [string]$Manifest = 'qad1-manifest-sha256.txt'
)

$ErrorActionPreference = 'Continue'
$script:failures = New-Object System.Collections.Generic.List[string]
$script:passes = 0

function Pass([string]$name) { Write-Host ('  [PASS] ' + $name) -ForegroundColor Green; $script:passes++ }
function Fail([string]$name, [string]$detail) {
    Write-Host ('  [FAIL] ' + $name) -ForegroundColor Red
    if ($detail) { Write-Host ('         ' + $detail) -ForegroundColor Yellow }
    $script:failures.Add($name) | Out-Null
}
function Section([string]$title) { Write-Host ''; Write-Host ('== ' + $title + ' ==') -ForegroundColor Cyan }
function To-Local([string]$rel) { return $rel.Replace('/', [System.IO.Path]::DirectorySeparatorChar) }

function Get-NormalizedHash([string]$path) {
    $bytes = [System.IO.File]::ReadAllBytes($path)
    $ms = New-Object System.IO.MemoryStream
    foreach ($b in $bytes) { if ($b -ne 13) { $ms.WriteByte($b) } }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    return ([System.BitConverter]::ToString($sha.ComputeHash($ms.ToArray()))).Replace('-', '').ToLower()
}

function Strip-Comments([string]$text) {
    $text = [regex]::Replace($text, '(?s)/\*.*?\*/', '')
    $text = [regex]::Replace($text, '(?m)(^|\s)//[^\r\n]*', '$1')
    $text = [regex]::Replace($text, '(?s)\{\{--.*?--\}\}', '')
    return $text
}

function Run-Tests([string]$label, [string[]]$paths) {
    $argList = @('artisan', 'test') + $paths
    $out = & php @argList 2>&1 | Out-String
    $code = $LASTEXITCODE
    $summary = ($out -split "`r?`n" | Where-Object { $_ -match '^\s*Tests:' } | Select-Object -Last 1)
    if (($code -eq 0) -and $summary -and ($summary -notmatch 'failed')) { Pass ($label + ' : ' + $summary.Trim()) }
    else {
        $tail = ($out -split "`r?`n" | Select-Object -Last 25) -join "`n"
        Fail $label ('exit ' + $code + ' ' + $summary + "`n" + $tail)
    }
}

Write-Host 'verify-qad1 : فحص حزمة بيانات Demo/QA قبل الدفع' -ForegroundColor White

# ---------------------------------------------------------------- 0. المتطلبات
Section '0. المتطلبات'
if (Test-Path -LiteralPath 'artisan') { Pass 'جذر مشروع Laravel (artisan موجود)' } else { Fail 'جذر المشروع' 'شغّل السكربت من جذر المشروع'; Write-Host 'VERIFY-QAD1: FAIL' -ForegroundColor Red; exit 1 }
if (Test-Path -LiteralPath $Manifest) { Pass ('ملف البصمات موجود: ' + $Manifest) } else { Fail 'ملف البصمات' ('غير موجود: ' + $Manifest); Write-Host 'VERIFY-QAD1: FAIL' -ForegroundColor Red; exit 1 }
foreach ($tool in @('php', 'git', 'composer')) {
    if (Get-Command $tool -ErrorAction SilentlyContinue) { Pass ($tool + ' متاح') } else { Fail ($tool + ' متاح') 'غير موجود بالمسار' }
}

# ---------------------------------------------------------------- 1-2. الملفات والبصمات
Section '1-2. كل ملفات الحزمة موجودة وبصماتها مطابقة'
$entries = New-Object System.Collections.Generic.List[object]
foreach ($line in (Get-Content -LiteralPath $Manifest -Encoding UTF8)) {
    if ($line.Trim().Length -lt 67) { continue }
    $entries.Add([pscustomobject]@{ Hash = $line.Substring(0, 64); Path = $line.Substring(66).Trim() }) | Out-Null
}
$manifestPaths = @{}
$missing = 0; $differs = 0; $matched = 0
foreach ($e in $entries) {
    $manifestPaths[$e.Path] = $true
    $local = To-Local $e.Path
    if (-not (Test-Path -LiteralPath $local)) { Write-Host ('  MISSING  ' + $e.Path) -ForegroundColor Red; $missing++; continue }
    if ((Get-NormalizedHash $local) -ne $e.Hash) { Write-Host ('  DIFFERS  ' + $e.Path) -ForegroundColor Yellow; $differs++; continue }
    $matched++
}
if (($missing -eq 0) -and ($differs -eq 0)) { Pass ('كل الملفات مطابقة: ' + $matched + ' من ' + $entries.Count) } else { Fail 'مطابقة الملفات' ('مطابق ' + $matched + ' | مفقود ' + $missing + ' | مختلف ' + $differs + ' (أعد استخراج qad1-demo-data.zip فوق المشروع)') }

$essential = @('database/seeders/DemoQaSeeder.php', 'database/seeders/Demo/DemoSupport.php', 'database/seeders/Demo/DemoSocialSeeder.php', 'database/seeders/Demo/DemoTeamSeeder.php',
    'database/seeders/Demo/DemoProgressionSeeder.php', 'database/seeders/Demo/DemoCampaignSeeder.php', 'database/seeders/Demo/DemoCompetitionSeeder.php', 'database/seeders/Demo/DemoTeamCompetitionSeeder.php',
    'database/seeders/Demo/DemoNotificationSeeder.php', 'tests/Feature/DemoQa/DemoQaSeederTest.php', 'tests/Feature/DemoQa/DemoQaSmokeTest.php', 'docs/demo-qa-data.md')
$lost = @($essential | Where-Object { (-not $manifestPaths.ContainsKey($_)) -or (-not (Test-Path -LiteralPath (To-Local $_))) })
if ($lost.Count -eq 0) { Pass 'لا ملف أساسي مفقود (والتوثيق docs/demo-qa-data.md موجود)' } else { Fail 'ملف أساسي مفقود' ($lost -join ', ') }

# ---------------------------------------------------------------- 3. PHP lint
Section '3. php -l لكل PHP'
$phpFiles = @($entries | Where-Object { $_.Path -like '*.php' })
$lintBad = 0
foreach ($e in $phpFiles) {
    $o = & php -l (To-Local $e.Path) 2>&1 | Out-String
    if ($o -notmatch 'No syntax errors') { Write-Host ('  LINT FAIL  ' + $e.Path) -ForegroundColor Red; $lintBad++ }
}
if ($lintBad -eq 0) { Pass ('lint نظيف: ' + $phpFiles.Count + ' ملف PHP') } else { Fail 'php -l' ($lintBad.ToString() + ' ملف فيه أخطاء') }

# ---------------------------------------------------------------- 4-6. لا ترحيلات ولا كود منتج
Section '4-6. لا ترحيلات، ولا كود منتج، والفرق عن الأساس يطابق الحزمة'
$gitOk = $false
& git rev-parse --verify --quiet ($BaseSha + '^{commit}') 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) { $gitOk = $true }
if ($gitOk) {
    $migChanges = @(& git diff --name-status $BaseSha -- database/migrations 2>&1 | Where-Object { $_ })
    $migUntracked = @(& git ls-files -o --exclude-standard -- database/migrations 2>&1 | Where-Object { $_ })
    if (($migChanges.Count -eq 0) -and ($migUntracked.Count -eq 0)) { Pass 'ترحيلات جديدة = 0، ولا ترحيل قديم عُدِّل' } else { Fail 'ترحيلات' (($migChanges + $migUntracked) -join '; ') }
} else { Fail 'مرجع Git' ('الالتزام الأساسي غير موجود محليًا: ' + $BaseSha + ' (نفّذ git fetch)') }

$allowed = '^(database/seeders/(Demo/[A-Za-z]+|DemoQaSeeder)\.php|tests/Feature/DemoQa/[A-Za-z]+\.php|docs/demo-qa-data\.md)$'
$outside = @($manifestPaths.Keys | Where-Object { $_ -notmatch $allowed })
if ($outside.Count -eq 0) { Pass 'الحزمة بذور واختبارات ووثائق فقط: لا Models/Services/Controllers/Config/Routes' } else { Fail 'ملفات خارج نطاق بيانات الديمو' ($outside -join ', ') }

if ($gitOk) {
    $helper = '^(verify-(e|qa)[a-z]*\d+\.ps1|(e|qa[a-z]*)\d+-[^/\\]*\.(zip|txt))$'
    $changed = New-Object System.Collections.Generic.HashSet[string]
    foreach ($p in @(& git diff --name-only $BaseSha 2>$null)) { if ($p) { [void]$changed.Add($p.Replace('\', '/')) } }
    foreach ($p in @(& git ls-files -o --exclude-standard 2>$null)) { if ($p -and ($p -notmatch $helper)) { [void]$changed.Add($p.Replace('\', '/')) } }
    $extra = @($changed | Where-Object { -not $manifestPaths.ContainsKey($_) })
    $absent = @($manifestPaths.Keys | Where-Object { -not $changed.Contains($_) })
    if (($extra.Count -eq 0) -and ($absent.Count -eq 0)) { Pass ('الفرق عن الأساس يطابق الحزمة تمامًا: ' + $changed.Count + ' ملف') }
    else { Fail 'فروق Git' ('زائد: ' + ($extra -join ', ') + ' | ناقص: ' + ($absent -join ', ')) }
}

# ---------------------------------------------------------------- 7. الملفات المحظورة والأدوات المتتبَّعة
Section '7. الملفات المحظورة وأدوات التحقق غير متتبَّعة'
$tracked = @(& git ls-files 2>$null)
$forbidden = @($tracked | Where-Object { ($_ -notmatch '(^|/)\.gitignore$') -and ($_ -match '(^|/)\.env$|\.sqlite$|db_puzzle|laravel11_auth|^bootstrap/cache/|^public/build/|^node_modules/|^storage/logs/') })
if ($forbidden.Count -eq 0) { Pass 'ملفات محظورة متتبَّعة = 0' } else { Fail 'ملفات محظورة متتبَّعة' ($forbidden -join ', ') }
$helpersTracked = @($tracked | Where-Object { $_ -match '(^|/)verify-[a-z]*\d+\.ps1$|(^|/)[a-z]*\d+-[^/]*(manifest|\.zip)' })
if ($helpersTracked.Count -eq 0) { Pass 'أدوات التحقق والبصمات متتبَّعة = 0' } else { Fail 'أدوات مساعدة متتبَّعة' ($helpersTracked -join ', ') }

# ---------------------------------------------------------------- 8. تدقيقات ثابتة على البذور
Section '8. تدقيقات ثابتة على بذور الديمو'
$seeders = @($entries | Where-Object { $_.Path -match '^database/seeders/' })
$bad = @{ debug = @(); random = @(); economy = @(); destructive = @() }
foreach ($e in $seeders) {
    $code = Strip-Comments ([System.IO.File]::ReadAllText((Resolve-Path -LiteralPath (To-Local $e.Path)).Path))
    if ($code -match '\bdd\(|\bdump\(|\bray\(|\bvar_dump\(|\bTODO\b|\bFIXME\b') { $bad.debug += $e.Path }
    if ($code -match 'fake\(|\brand\(|mt_rand|random_int|shuffle\(|inRandomOrder|Str::random|Factory|factory\(|array_rand|Str::uuid|Str::ulid') { $bad.random += $e.Path }
    if ($code -match '->increment\(|->decrement\(|available_balance|pending_balance|lifetime_|total_xp|DB::table\(.wallets.\)|Wallet::|campaign_completed|is_completed|campaign_progress') { $bad.economy += $e.Path }
    if ($code -match 'truncate|migrate:fresh|db:wipe|->delete\(|forceDelete|DELETE FROM|Schema::drop|dropIfExists') { $bad.destructive += $e.Path }
}
if ($bad.debug.Count -eq 0) { Pass 'بقايا تصحيح = 0' } else { Fail 'بقايا تصحيح' ($bad.debug -join ', ') }
if ($bad.random.Count -eq 0) { Pass 'لا عشوائية بالبذور (حتمية)' } else { Fail 'عشوائية بالبذور' ($bad.random -join ', ') }
if ($bad.economy.Count -eq 0) { Pass 'لا تعديل اقتصاد/XP/تقدّم مشتق مباشر (الخدمات الرسمية فقط)' } else { Fail 'تعديل اقتصاد مباشر' ($bad.economy -join ', ') }
if ($bad.destructive.Count -eq 0) { Pass 'لا حذف ولا مسح ولا إعادة ضبط (غير مدمِّرة)' } else { Fail 'عمليات مدمِّرة' ($bad.destructive -join ', ') }

$dbSeeder = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath (To-Local 'database/seeders/DatabaseSeeder.php')).Path)
if ($dbSeeder -notmatch 'Demo') { Pass 'DemoQaSeeder غير موصول بـDatabaseSeeder (اختياري، لا يعمل مع النشر)' } else { Fail 'ربط بـDatabaseSeeder' 'DatabaseSeeder يشير إلى Demo' }
$entry = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath (To-Local 'database/seeders/DemoQaSeeder.php')).Path)
$guardOk = ($entry -match "ALLOWED_ENVIRONMENTS\s*=\s*\['local',\s*'testing',\s*'staging'\]") -and ($entry -match 'assertSafeEnvironment\(\);') -and ($entry -match 'environment\(self::ALLOWED_ENVIRONMENTS\)')
if ($guardOk) { Pass 'حارس البيئة: local/testing/staging فقط، يُستدعى أول الـrun (الإنتاج مرفوض)' } else { Fail 'حارس البيئة' 'غير موجود أو غير مستدعى' }
$composerJson = [System.IO.File]::ReadAllText((Resolve-Path -LiteralPath 'composer.json').Path)
if ($composerJson -notmatch 'DemoQa') { Pass 'لا ربط بسكربتات composer (لا تشغيل تلقائي)' } else { Fail 'ربط بـcomposer' 'composer.json يشير إلى DemoQa' }

# ---------------------------------------------------------------- 9. strict PSR (مباشر بالواجهة)
Section '9. strict PSR-4 (composer مباشرة)'
Write-Host '  (تشغيل مباشر: قد يستغرق دقيقة؛ Ctrl+C يوقفه)' -ForegroundColor DarkGray
$psrWatch = [System.Diagnostics.Stopwatch]::StartNew()
$psr = & composer dump-autoload -o --strict-psr --no-scripts --no-interaction --no-ansi 2>&1 | Out-String
$psrCode = $LASTEXITCODE
$psrWatch.Stop()
if (($psrCode -eq 0) -and ($psr -notmatch 'does not comply')) { Pass ('composer dump-autoload -o --strict-psr نظيف (' + [int]$psrWatch.Elapsed.TotalSeconds + ' ثانية)') }
else { Fail 'strict PSR-4' (($psr -split "`r?`n" | Select-Object -Last 8) -join "`n") }

# ---------------------------------------------------------------- 10. اختبارات الديمو (مرتان)
Section '10. اختبارات الديمو: التشغيل الأول'
Run-Tests 'Demo run #1' @('tests/Feature/DemoQa/')
Section '10b. اختبارات الديمو: التشغيل الثاني'
Run-Tests 'Demo run #2' @('tests/Feature/DemoQa/')

# ---------------------------------------------------------------- 11. انحدارات اختيارية
if ($WithRegressions) {
    Section '11. انحدارات مستهدفة (اختيارية)'
    Run-Tests 'الأدوار والصلاحيات' @('tests/Feature/Rbac/')
    Run-Tests 'الفرق (E19)' @('tests/Feature/Teams/')
    Run-Tests 'تحدّيات وبطولات الفرق (E20)' @('tests/Feature/TeamCompetition/')
}

# ---------------------------------------------------------------- الخلاصة
Write-Host ''
Write-Host ('الملخص: ' + $script:passes + ' فحصًا ناجحًا | ' + $script:failures.Count + ' فاشلًا') -ForegroundColor White
if ($script:failures.Count -eq 0) {
    Write-Host 'VERIFY-QAD1: PASS' -ForegroundColor Green
    Write-Host 'مسموح الآن: git add (ملفات الحزمة فقط بأسمائها، لا أدوات التحقق) ثم commit ثم push.' -ForegroundColor Green
    exit 0
}
Write-Host 'VERIFY-QAD1: FAIL' -ForegroundColor Red
foreach ($f in $script:failures) { Write-Host ('  - ' + $f) -ForegroundColor Red }
Write-Host 'ممنوع الدفع. أصلح ما فشل (غالبًا: أعد استخراج qad1-demo-data.zip) ثم أعد التشغيل.' -ForegroundColor Red
exit 1
