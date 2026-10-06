<#
  verify-e19.ps1 : حزمة التحقق الإلزامية لـE19 (الفرق). تُشغَّل من جذر المشروع **قبل** git add / commit / push:
      powershell -ExecutionPolicy Bypass -File .\verify-e19.ps1
  تنتهي بـ PASS وكود خروج 0 فقط إن نجحت كل الفحوص؛ وإلا FAIL وكود 1. ممنوع الدفع قبل PASS.
  أداة مساعدة غير متتبَّعة (لا تضفها للـGit): هي والبصمات والحزمة بجذر المشروع تُحذف بعد الاستعمال أو تُستثنى.
  مفاتيح اختيارية (للتجربة فقط، لا للدفع النهائي):  -SkipBuild   -SkipRegressions
#>
param(
    [switch]$SkipBuild,
    [switch]$SkipRegressions,
    [string]$BaseSha = '0afaf11e3897426d906692c35ede584fbff86a4b',
    [string]$Manifest = 'e19-manifest-sha256.txt'
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
    $text = [regex]::Replace($text, '(?m)//[^\r\n]*', '')
    $text = [regex]::Replace($text, '(?s)\{\{--.*?--\}\}', '')
    return $text
}

function Run-Tests([string]$label, [string[]]$paths) {
    $argList = @('artisan', 'test') + $paths
    $out = & php @argList 2>&1 | Out-String
    $code = $LASTEXITCODE
    $summary = ($out -split "`r?`n" | Where-Object { $_ -match '^\s*Tests:' } | Select-Object -Last 1)
    if (($code -eq 0) -and $summary -and ($summary -notmatch 'failed')) {
        Pass ($label + ' : ' + $summary.Trim())
    } else {
        $tail = ($out -split "`r?`n" | Select-Object -Last 25) -join "`n"
        Fail $label ('exit ' + $code + ' ' + $summary + "`n" + $tail)
    }
}

function Run-Step([string]$label, [string[]]$argList) {
    $out = & php @argList 2>&1 | Out-String
    $code = $LASTEXITCODE
    if ($code -eq 0) { Pass $label } else { Fail $label ('exit ' + $code + "`n" + (($out -split "`r?`n" | Select-Object -Last 12) -join "`n")) }
    return $out
}

Write-Host 'verify-e19 : فحص حزمة الفرق قبل الدفع' -ForegroundColor White

# ---------------------------------------------------------------- 0. المتطلبات
Section '0. المتطلبات'
if (Test-Path -LiteralPath 'artisan') { Pass 'جذر مشروع Laravel (artisan موجود)' } else { Fail 'جذر المشروع' 'شغّل السكربت من جذر المشروع'; Write-Host 'VERIFY-E19: FAIL' -ForegroundColor Red; exit 1 }
if (Test-Path -LiteralPath $Manifest) { Pass ('ملف البصمات موجود: ' + $Manifest) } else { Fail 'ملف البصمات' ('غير موجود: ' + $Manifest); Write-Host 'VERIFY-E19: FAIL' -ForegroundColor Red; exit 1 }
foreach ($tool in @('php', 'git', 'composer')) {
    if (Get-Command $tool -ErrorAction SilentlyContinue) { Pass ($tool + ' متاح') } else { Fail ($tool + ' متاح') 'غير موجود بالمسار' }
}
if (-not $SkipBuild) { if (Get-Command npm -ErrorAction SilentlyContinue) { Pass 'npm متاح' } else { Fail 'npm متاح' 'غير موجود بالمسار' } }

# ---------------------------------------------------------------- 1-2. الملفات والبصمات
Section '1-2. كل ملفات E19 موجودة وبصماتها مطابقة'
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
if (($missing -eq 0) -and ($differs -eq 0)) { Pass ('كل الملفات مطابقة: ' + $matched + ' من ' + $entries.Count) } else { Fail 'مطابقة الملفات' ('مطابق ' + $matched + ' | مفقود ' + $missing + ' | مختلف ' + $differs + ' من ' + $entries.Count + ' (أعد استخراج e19-teams.zip فوق المشروع)') }

$essential = @(
    'app/Filament/Resources/TeamResource.php', 'app/Filament/Resources/TeamResource/RelationManagers/MembersRelationManager.php',
    'app/Services/Teams/TeamService.php', 'app/Services/Teams/TeamMembershipService.php', 'app/Services/Teams/TeamInvitationService.php',
    'app/Services/Teams/TeamJoinRequestService.php', 'app/Services/Teams/TeamCompetitiveRankingService.php', 'app/Policies/TeamPolicy.php',
    'app/Models/Team.php', 'app/Models/TeamMembership.php', 'config/teams.php', 'docs/teams.md'
)
$lost = @($essential | Where-Object { (-not $manifestPaths.ContainsKey($_)) -or (-not (Test-Path -LiteralPath (To-Local $_))) })
if ($lost.Count -eq 0) { Pass 'لا ملف أساسي مفقود' } else { Fail 'ملف أساسي مفقود' ($lost -join ', ') }

# ---------------------------------------------------------------- 3. PHP lint
Section '3. php -l لكل PHP'
$phpFiles = @($entries | Where-Object { $_.Path -like '*.php' })
$lintBad = 0
foreach ($e in $phpFiles) {
    $o = & php -l (To-Local $e.Path) 2>&1 | Out-String
    if ($o -notmatch 'No syntax errors') { Write-Host ('  LINT FAIL  ' + $e.Path) -ForegroundColor Red; $lintBad++ }
}
if ($lintBad -eq 0) { Pass ('lint نظيف: ' + $phpFiles.Count + ' ملف PHP') } else { Fail 'php -l' ($lintBad.ToString() + ' ملف فيه أخطاء') }

# ---------------------------------------------------------------- 4-5. الترحيلات
Section '4-5. الترحيلات'
$migs = @($entries | Where-Object { $_.Path -like 'database/migrations/2026_10_15_*' })
if ($migs.Count -eq 6) { Pass 'الترحيلات الجديدة الست موجودة' } else { Fail 'الترحيلات الجديدة' ('المتوقع 6 والموجود ' + $migs.Count) }
$gitOk = $false
& git rev-parse --verify --quiet ($BaseSha + '^{commit}') 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) { $gitOk = $true }
if ($gitOk) {
    $migStatus = @(& git diff --name-status $BaseSha -- database/migrations 2>&1)
    $badMig = @($migStatus | Where-Object { $_ -match '^[MDRC]' })
    if ($badMig.Count -eq 0) { Pass 'لا ترحيل قديم عُدِّل أو حُذف' } else { Fail 'ترحيل قديم عُدِّل' ($badMig -join '; ') }
} else { Fail 'مرجع Git' ('الالتزام الأساسي غير موجود محليًا: ' + $BaseSha + ' (نفّذ git fetch)') }

# ---------------------------------------------------------------- 12-13. فروق Git والملفات المحظورة
Section '12-13. فروق Git والملفات المحظورة'
if ($gitOk) {
    $helper = '^(verify-e\d+\.ps1|e\d+-[^/\\]*\.(zip|txt))$'
    $changed = New-Object System.Collections.Generic.HashSet[string]
    foreach ($p in @(& git diff --name-only $BaseSha 2>$null)) { if ($p) { [void]$changed.Add($p.Replace('\', '/')) } }
    foreach ($p in @(& git ls-files -o --exclude-standard 2>$null)) { if ($p -and ($p -notmatch $helper)) { [void]$changed.Add($p.Replace('\', '/')) } }
    $extra = @($changed | Where-Object { -not $manifestPaths.ContainsKey($_) })
    $absent = @($manifestPaths.Keys | Where-Object { -not $changed.Contains($_) })
    if (($extra.Count -eq 0) -and ($absent.Count -eq 0)) { Pass ('الفرق عن الأساس يطابق الحزمة تمامًا: ' + $changed.Count + ' ملف') }
    else { Fail 'فروق Git' ('زائد: ' + ($extra -join ', ') + ' | ناقص: ' + ($absent -join ', ')) }
}
$tracked = @(& git ls-files 2>$null)
# ملفات .gitignore الحارسة (bootstrap/cache و storage/logs) مقصودة بلاراڤيل وتُتتبَّع بحق: تُستثنى من الفحص.
$forbidden = @($tracked | Where-Object { ($_ -notmatch '(^|/)\.gitignore$') -and ($_ -match '(^|/)\.env$|\.sqlite$|db_puzzle|laravel11_auth|^bootstrap/cache/|^public/build/|^node_modules/|^storage/logs/|(^|/)verify-e\d+\.ps1$|(^|/)e\d+-[^/]*(manifest|\.zip)') })
if ($forbidden.Count -eq 0) { Pass 'ملفات محظورة متتبَّعة = 0' } else { Fail 'ملفات محظورة متتبَّعة' ($forbidden -join ', ') }

# ---------------------------------------------------------------- 14-16. التدقيقات الثابتة
Section '14-16. تدقيقات ثابتة'
$prod = @($entries | Where-Object { ($_.Path -notlike 'tests/*') -and ($_.Path -notlike 'docs/*') -and ($_.Path -match '\.(php|js|css)$') })
$debugHits = @()
foreach ($e in $prod) {
    $code = Strip-Comments ([System.IO.File]::ReadAllText((Resolve-Path -LiteralPath (To-Local $e.Path)).Path))
    if ($code -match '\bdd\(|\bdump\(|\bray\(|\bvar_dump\(|\bTODO\b|\bFIXME\b|console\.log') { $debugHits += $e.Path }
}
if ($debugHits.Count -eq 0) { Pass 'بقايا تصحيح = 0' } else { Fail 'بقايا تصحيح' ($debugHits -join ', ') }

$rankPath = To-Local 'app/Services/Teams/TeamCompetitiveRankingService.php'
if (Test-Path -LiteralPath $rankPath) {
    $rank = Strip-Comments ([System.IO.File]::ReadAllText((Resolve-Path -LiteralPath $rankPath).Path))
    if (($rank -notmatch 'TeamMembership|membershipOf|teamIdFor|team_memberships') -and ($rank -match 'team_id_snapshot')) { Pass 'ترتيب الفرق يستعمل اللقطة لا العضوية الحالية (خطأ التاريخ = 0)' }
    else { Fail 'خطأ العضوية الحالية بالترتيب التاريخي' 'خدمة الترتيب تشير لعضوية حالية أو لا تقرأ team_id_snapshot' }
} else { Fail 'خدمة الترتيب' 'مفقودة' }

$ecoFiles = @($prod | Where-Object { $_.Path -match '^app/(Services/Teams/|Models/Team|Http/Controllers/Team|Listeners/SendTeam|Jobs/ComputeTeamRankings|Services/Analytics/TeamAnalyticsService|Filament/Resources/TeamResource|Policies/TeamPolicy)' })
$ecoHits = @()
foreach ($e in $ecoFiles) {
    $code = Strip-Comments ([System.IO.File]::ReadAllText((Resolve-Path -LiteralPath (To-Local $e.Path)).Path))
    if ($code -match '(?i)CurrencyWalletService|creditPending|creditAvailable|grantXp|XpService|InventoryService|EntitlementService|StorePurchase|CompetitiveRewardGrant|CompetitiveRewardDistributionService|ProgressionRewardService|pending_balance|total_xp|\bwallet') { $ecoHits += $e.Path }
}
if ($ecoHits.Count -eq 0) { Pass ('تعديل اقتصاد مباشر = 0 (فُحص ' + $ecoFiles.Count + ' ملفًا)') } else { Fail 'تعديل اقتصاد مباشر' ($ecoHits -join ', ') }

# ---------------------------------------------------------------- 17. strict PSR
Section '17. strict PSR-4'
$psr = & composer dump-autoload -o --strict-psr 2>&1 | Out-String
if (($LASTEXITCODE -eq 0) -and ($psr -notmatch 'does not comply')) { Pass 'composer dump-autoload -o --strict-psr نظيف' } else { Fail 'strict PSR-4' (($psr -split "`r?`n" | Select-Object -Last 8) -join "`n") }

# ---------------------------------------------------------------- 11. الصلاحيات
Section '11. الصلاحيات'
$perm = Run-Step 'php artisan permissions:sync' @('artisan', 'permissions:sync')
if (($perm -match 'teams\.view') -or ($perm -match '0 صلاحية جديدة')) { Pass 'صلاحيات الفرق مسجَّلة (teams.view|manage|deactivate)' } else { Write-Host ('  (ملاحظة) مخرجات المزامنة: ' + (($perm -split "`r?`n" | Select-Object -Last 2) -join ' | ')) -ForegroundColor DarkYellow }

# ---------------------------------------------------------------- 6. اختبارات E19
Section '6. اختبارات E19 (المستهدفة)'
Run-Tests 'E19' @('tests/Feature/Teams/')

# ---------------------------------------------------------------- 7. الانحدارات
if (-not $SkipRegressions) {
    Section '7. الانحدارات المستهدفة'
    Run-Tests 'E16 الاجتماعية' @('tests/Feature/Social/')
    Run-Tests 'E17 التحديات والمنافسات' @('tests/Feature/FriendChallenges/', 'tests/Feature/Competitive/')
    Run-Tests 'E18 الجوائز والتعرّف' @('tests/Feature/CompetitiveRewards/')
    Run-Tests 'الإشعارات' @('tests/Feature/Notifications/')
    Run-Tests 'الملف العام والهوية والثيم' @('tests/Feature/PlayerIdentity/', 'tests/Feature/ThemeRenderingTest.php', 'tests/Unit/CssInteractivityTest.php')
    Run-Tests 'التحليلات' @('tests/Feature/Analytics/')
    Run-Tests 'الصلاحيات والعمليات' @('tests/Feature/Rbac/', 'tests/Feature/Operations/', 'tests/Feature/AdminSettingsPageTest.php')
    Run-Tests 'الاقتصاد والمتجر والتقدم والانخراط' @('tests/Feature/Economy/', 'tests/Feature/Store/', 'tests/Feature/Progression/', 'tests/Feature/Engagement/')
} else { Write-Host '  (تخطّي الانحدارات: -SkipRegressions. لا يصلح للدفع النهائي)' -ForegroundColor DarkYellow }

# ---------------------------------------------------------------- 9-10. المسارات والإعدادات
Section '9-10. route:cache / config:cache'
[void](Run-Step 'php artisan route:cache' @('artisan', 'route:cache'))
[void](Run-Step 'php artisan config:cache' @('artisan', 'config:cache'))
& php artisan optimize:clear 2>&1 | Out-Null

# ---------------------------------------------------------------- 8. البناء
if (-not $SkipBuild) {
    Section '8. npm run build'
    $b = & npm run build 2>&1 | Out-String
    if (($LASTEXITCODE -eq 0) -and ($b -match 'built in')) { Pass 'npm run build' } else { Fail 'npm run build' (($b -split "`r?`n" | Select-Object -Last 8) -join "`n") }
} else { Write-Host '  (تخطّي البناء: -SkipBuild. لا يصلح للدفع النهائي)' -ForegroundColor DarkYellow }

# ---------------------------------------------------------------- الخلاصة
Write-Host ''
Write-Host ('الملخص: ' + $script:passes + ' فحصًا ناجحًا | ' + $script:failures.Count + ' فاشلًا') -ForegroundColor White
if ($script:failures.Count -eq 0) {
    Write-Host 'VERIFY-E19: PASS' -ForegroundColor Green
    Write-Host 'مسموح الآن: git add (الملفات المتغيّرة فقط، لا أدوات التحقق) ثم commit ثم push.' -ForegroundColor Green
    exit 0
}
Write-Host 'VERIFY-E19: FAIL' -ForegroundColor Red
foreach ($f in $script:failures) { Write-Host ('  - ' + $f) -ForegroundColor Red }
Write-Host 'ممنوع الدفع. أصلح ما فشل (غالبًا: أعد استخراج e19-teams.zip) ثم أعد التشغيل.' -ForegroundColor Red
exit 1
