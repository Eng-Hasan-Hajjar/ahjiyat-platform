<?php

use App\Models\User;
use App\Services\ReportExportService;
use App\Support\AnalyticsPeriod;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

test('exportUsers requires reports.export permission', function () {
    $unauthorized = User::factory()->create();
    $unauthorized->assignRole('content-manager');

    $this->actingAs($unauthorized);
    $page = \Livewire\Livewire::test(\App\Filament\Pages\AnalyticsCenter::class)->instance();

    expect(fn () => $page->exportUsers())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
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