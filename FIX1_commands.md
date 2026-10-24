# أوامر التصحيح (Windows / PowerShell) — بلا أي أمر git

```powershell
$zip = "$HOME\Downloads\E40-fix1.zip"            # عدّل المسار إن لزم
$tmp = Join-Path $env:TEMP "e40-fix1"
Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
Expand-Archive -Path $zip -DestinationPath $tmp -Force

Set-Location "C:\Users\engya\Desktop\puzzles\puzzle-gems-platform"

# 1) احذف الملف المكرر الخاطئ فقط (لا المجلد؛ فيه MysqlSafeBlueprint.php)
Remove-Item .\app\Database\FinancialHistoryGuard.php
Get-ChildItem .\app\Database                      # يجب أن يظهر MysqlSafeBlueprint.php وحده

# 2) انسخ الملفات الثلاثة بمساراتها الصحيحة
Copy-Item -Path "$tmp\E40-fix1\overlay\*" -Destination . -Recurse -Force

# 3) تحقق من أن الملف في مكانه الصحيح وأن لا نسخة مكررة
Get-ChildItem -Recurse .\app -Filter FinancialHistoryGuard.php | Select-Object FullName   # يجب أن يظهر مسار واحد: app\Services\Database\...

php artisan optimize:clear
composer dump-autoload

# 4) الاختبارات (واحدة تلو الأخرى)
vendor\bin\pest --compact tests\Feature\AhjiyatPassE40\E40GuardRegistrationTest.php     # 3 ناجحة
vendor\bin\pest --compact tests\Feature\AhjiyatPassE40                                  # 237 ناجحة
vendor\bin\pest --compact tests\Feature\DatabaseE31
vendor\bin\pest --compact tests\Feature\CreatorFinanceE39\E39OpsTest.php
vendor\bin\pest --compact tests\Feature\PaymentsE32\E32AdminOpsTest.php
```
