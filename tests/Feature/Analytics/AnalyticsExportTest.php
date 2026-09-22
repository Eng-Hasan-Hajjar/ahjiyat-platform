<?php

use App\Models\User;
use App\Services\ReportExportService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

function analyticsCenterFor(User $user)
{
    test()->actingAs($user);

    return \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();
}

test('exportUsers requires reports.export permission', function () {
    $unauthorized = User::factory()->create();
    $unauthorized->assignRole('content-manager');

    $page = analyticsCenterFor($unauthorized);

    expect(fn () => $page->exportUsers())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('reports.export alone is not enough to export gems - analytics.financial also required', function () {
    $role = \App\Models\Role::create(['name' => 'export-only', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'analytics.view', 'reports.export']);

    $user = User::factory()->create();
    $user->assignRole('export-only');

    $page = analyticsCenterFor($user);

    expect(fn () => $page->exportGems())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('reports.export alone is not enough to export security - analytics.security also required', function () {
    $role = \App\Models\Role::create(['name' => 'export-only-2', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'analytics.view', 'reports.export']);

    $user = User::factory()->create();
    $user->assignRole('export-only-2');

    $page = analyticsCenterFor($user);

    expect(fn () => $page->exportSecurity())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('reports.export + analytics.financial together allow the gems export', function () {
    $role = \App\Models\Role::create(['name' => 'finance-exporter', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'analytics.view', 'reports.export', 'analytics.financial']);

    $user = User::factory()->create();
    $user->assignRole('finance-exporter');

    $page = analyticsCenterFor($user);

    expect($page->exportGems())->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
});

test('reports.export + analytics.security together allow the security export', function () {
    $role = \App\Models\Role::create(['name' => 'security-exporter', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'analytics.view', 'reports.export', 'analytics.security']);

    $user = User::factory()->create();
    $user->assignRole('security-exporter');

    $page = analyticsCenterFor($user);

    expect($page->exportSecurity())->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
});

test('reports.export + analytics.users together allow the users export', function () {
    $role = \App\Models\Role::create(['name' => 'users-exporter', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'analytics.view', 'reports.export', 'analytics.users']);

    $user = User::factory()->create();
    $user->assignRole('users-exporter');

    $page = analyticsCenterFor($user);

    expect($page->exportUsers())->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
});

test('the users CSV report has the correct headers and no sensitive columns', function () {
    User::factory()->create(['name' => 'مستخدم تجريبي', 'created_at' => now()]);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain('الاسم')
        ->and($content)->toContain('مستخدم تجريبي')
        ->and($content)->not->toContain('password')
        ->and($content)->not->toContain('remember_token');
});

test('the puzzle activity CSV never includes answer_hash or solution_data', function () {
    $puzzle = \App\Models\Puzzle::factory()->create(['answer_hash' => hash('sha256', 'secret-answer')]);
    \App\Models\PuzzleAttempt::factory()->create(['puzzle_id' => $puzzle->id, 'created_at' => now()]);

    $response = app(ReportExportService::class)->puzzleActivityReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toContain(hash('sha256', 'secret-answer'));
});

test('CSV cells starting with formula-injection characters are sanitized', function () {
    User::factory()->create(['name' => '=SUM(A1:A10)', 'created_at' => now()]);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain(' =SUM(A1:A10)')
        ->and($content)->not->toContain(",=SUM(A1:A10)");
});

test('a tab character before a formula trigger is also sanitized', function () {
    User::factory()->create(['name' => "\t=SUM(A1:A10)", 'created_at' => now()]);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toContain(",\t=SUM(A1:A10)");
});

test('a newline or carriage return before a formula trigger is also sanitized', function () {
    User::factory()->create(['name' => "\r\n+CMD|'/c calc'!A1", 'created_at' => now()]);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toContain(",\r\n+CMD");
});

test('the CSV output includes a UTF-8 BOM for correct Arabic display in Excel', function () {
    User::factory()->create(['name' => 'أحمد', 'created_at' => now()]);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");
});

test('the date filter is respected in the users export', function () {
    User::factory()->create(['created_at' => now()->subDays(60), 'name' => 'قديم جداً']);
    User::factory()->create(['created_at' => now(), 'name' => 'حديث']);

    $response = app(ReportExportService::class)->usersReport(AnalyticsPeriod::fromPreset('last_30_days'));

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain('حديث')
        ->and($content)->not->toContain('قديم جداً');
});