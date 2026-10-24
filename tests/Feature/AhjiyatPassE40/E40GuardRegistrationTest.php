<?php

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Database\FinancialHistoryGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E40 (AX19) - تسجيل المشتريات الرقمية في حارس التاريخ المالي + سلامة مواضع الملفات
|--------------------------------------------------------------------------
| لماذا؟ جدول digital_purchases مفتاحه user_id بـRESTRICT (لا يحذف التاريخ تتابعيًا)، فلا يلتقطه اختبار «التتابعي» في E31. لو غاب سجلّه من
| FinancialHistoryGuard لسمحت UserPolicy::delete بالحذف ثم انفجر ذلك خطأ قاعدة بيانات خامًا بدل رفض ودّي («جمِّد ولا تحذف»).
| وكذلك: ملف نُسخ إلى مجلد خاطئ (نطاق لا يطابق مساره) يترك النسخة الأصلية بلا تعديل بصمت. هذان الاختباران يلتقطان الحالتين.
*/

beforeEach(fn () => e40_seed());

test('AX19-124: premium purchases are registered in the financial-history guard, so a buyer is frozen, never deleted', function () {
    expect(FinancialHistoryGuard::HISTORY_TABLES)->toHaveKey('digital_purchases')
        ->and(FinancialHistoryGuard::HISTORY_TABLES['digital_purchases'][0])->toBe('user_id');

    $super = User::factory()->create();
    $super->assignRole('super-admin');
    $backup = User::factory()->create();
    $backup->assignRole('super-admin');

    $pass = e40_pass();
    $buyer = e40_player($pass);
    e40_buy_premium($buyer, $pass);
    $clean = User::factory()->create();
    $guard = app(FinancialHistoryGuard::class);

    expect($guard->historyFor($buyer))->toContain('مشتريات المسار المميز')
        ->and($guard->hasHistory($buyer))->toBeTrue()
        ->and($guard->hasHistory($clean))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $buyer))->toBeFalse()
        ->and(app(UserPolicy::class)->delete($super, $clean))->toBeTrue();
});

test('AX19-125: the database itself still refuses to delete a buyer (RESTRICT) - the guard is the friendly layer, not the only one', function () {
    $pass = e40_pass();
    $buyer = e40_player($pass);
    $order = e40_buy_premium($buyer, $pass);

    expect(fn () => DB::table('users')->where('id', $buyer->id)->delete())->toThrow(QueryException::class)
        ->and(DB::table('digital_purchases')->where('id', $order->id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $buyer->id)->exists())->toBeTrue();
});

test('AX19-126: every PHP class file under app/ declares the namespace its path implies, and no class is declared twice', function () {
    $root = base_path('app');
    $seen = [];
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
            || preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $cls) !== 1) {
            continue; // ملف بلا نطاق/صنف (مساعدات ونحوها): خارج هذا الفحص
        }

        $expected = 'App'.(str_contains($relative, '/') ? '\\'.str_replace('/', '\\', dirname($relative)) : '');
        $fqcn = trim($ns[1]).'\\'.$cls[1];

        if (trim($ns[1]) !== $expected) {
            $violations[] = "{$relative}: namespace {$ns[1]} (expected {$expected})";
        }

        if ($cls[1] !== basename($relative, '.php')) {
            $violations[] = "{$relative}: class {$cls[1]} does not match the file name";
        }

        if (isset($seen[$fqcn])) {
            $violations[] = "{$relative}: {$fqcn} is also declared in {$seen[$fqcn]}";
        }

        $seen[$fqcn] = $relative;
    }

    expect($violations)->toBe([]);
});
