<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;

test('E15-218/47/48: prune deletes only OLD READ notifications - never unread, never recent', function () {
    $user = User::factory()->create();
    $oldRead = e15Note($user, ['read_at' => now()->subDays(120)]);
    $oldUnread = e15Note($user, ['created_at' => now()->subDays(400)]);
    $recentRead = e15Note($user, ['read_at' => now()->subDays(10)]);
    $borderline = e15Note($user, ['read_at' => now()->subDays(89)]);

    $this->artisan('notifications:prune')->assertExitCode(0);

    expect(DatabaseNotification::find($oldRead->id))->toBeNull()
        ->and(DatabaseNotification::find($oldUnread->id))->not->toBeNull()
        ->and(DatabaseNotification::find($recentRead->id))->not->toBeNull()
        ->and(DatabaseNotification::find($borderline->id))->not->toBeNull();

    $this->artisan('notifications:prune', ['--days' => 5])->assertExitCode(0);
    expect(DatabaseNotification::find($recentRead->id))->toBeNull()->and(DatabaseNotification::find($oldUnread->id))->not->toBeNull();
});

test('E15-79/88: both commands are registered with the scheduler (hourly re-engagement, daily prune)', function () {
    Artisan::call('schedule:list');
    $out = Artisan::output();

    expect($out)->toContain('notifications:dispatch-reengagement')->and($out)->toContain('notifications:prune');
});

// ============================ تدقيقات ثابتة (قابلة للتنفيذ) ============================

function e15Sources(): array
{
    return array_merge(
        glob(app_path('Services/Notifications/*.php')),
        glob(app_path('Listeners/*.php')),
        glob(app_path('Events/*.php')),
        [app_path('Http/Controllers/NotificationController.php'), app_path('View/Components/NotificationBell.php')],
        glob(app_path('Console/Commands/DispatchReEngagementNotifications.php')),
        glob(app_path('Console/Commands/PruneNotifications.php')),
    );
}

test('E15-235 static audit: the notifications domain has no reward / progression / wallet dependency', function () {
    $forbidden = ['CurrencyWalletService', 'XpService', 'ProgressionRewardService', 'StorePurchaseService', 'LevelService',
        '->grantXp(', '->creditPending(', '->creditAvailable(', '->syncCurrentQuests(', '->progressFor(', '->streakFor(',
        '->recordQualifyingActivity(', 'GemWalletService'];

    foreach (e15Sources() as $file) {
        $code = file_get_contents($file);
        foreach ($forbidden as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file)." must not reference {$needle}");
        }
    }
});

test('E15-234/186 static audit: no external push / email-campaign / SMS SDK anywhere (code or composer)', function () {
    $composer = strtolower(file_get_contents(base_path('composer.json')));

    foreach (['firebase', 'onesignal', 'webpush', 'twilio', 'apns', 'fcm'] as $needle) {
        expect(str_contains($composer, $needle))->toBeFalse("composer.json must not require {$needle}");

        foreach (e15Sources() as $file) {
            expect(str_contains(strtolower(file_get_contents($file)), $needle))->toBeFalse(basename($file)." must not mention {$needle}");
        }
    }
});

test('E15-236/237 static audit: notification views never render raw HTML or run scripts, and never accept a stored URL', function () {
    $views = array_merge(glob(resource_path('views/notifications/*.blade.php')), [resource_path('views/components/notification-bell.blade.php')]);

    foreach ($views as $view) {
        $code = file_get_contents($view);
        expect(str_contains($code, '{!!'))->toBeFalse(basename($view).' must not use raw output')
            ->and(str_contains($code, '<script'))->toBeFalse(basename($view).' must not embed scripts')
            ->and(str_contains($code, "data['url']"))->toBeFalse()
            ->and(str_contains($code, "data['action_url']"))->toBeFalse();
    }
});

test('E15-238/239 static audit: every implemented type has a deterministic semantic key, and the scheduler is chunked', function () {
    $code = collect(e15Sources())->map(fn ($f) => file_get_contents($f))->implode("\n");

    foreach (['"achievement:{$event->user->id}:{$event->achievement->id}"', '"streak-risk:{$row->user_id}:{$today}"',
        '"daily-quests:{$row->user_id}:daily:{$today}"', '"security-password-reset:{$user->id}:{$fingerprint}"'] as $key) {
        expect(str_contains($code, $key))->toBeTrue("missing idempotency key pattern {$key}");
    }

    $service = file_get_contents(app_path('Services/Notifications/ReEngagementService.php'));

    expect(substr_count($service, '->chunkById('))->toBe(2)
        ->and(str_contains($service, 'User::all('))->toBeFalse()
        ->and(preg_match('/User::query\(\)->get\(\)/', $service))->toBe(0);
});

test('E15-176/174: no websockets and no aggressive polling were introduced in the bell', function () {
    $bell = file_get_contents(resource_path('views/components/notification-bell.blade.php'));

    expect(preg_match('/setInterval|setTimeout|fetch\(|EventSource|WebSocket|Echo\./', $bell))->toBe(0);
});

test('E15 UI regression: the bell panel is an OPAQUE surface - never .glass (96% transparent, nested backdrop-filter shows page text through it)', function () {
    $bell = file_get_contents(resource_path('views/components/notification-bell.blade.php'));
    preg_match('/<div x-show="open"[^>]*class="([^"]+)"/s', $bell, $m);
    $classes = preg_split('/\s+/', trim($m[1] ?? ''));

    expect($classes)->toContain('bg-night-900')
        ->and($classes)->not->toContain('glass')
        ->and(collect($classes)->contains(fn ($c) => preg_match('/^bg-night-\d+\//', $c) === 1))->toBeFalse(); // لا شفافية alpha
});

test('E15 UI regression: interactive containers on the notification pages never use .puzzle-card (its glow layer swallowed clicks)', function () {
    foreach (['notifications/index', 'notifications/preferences'] as $view) {
        $html = file_get_contents(resource_path("views/{$view}.blade.php"));

        preg_match_all('/<(form|article)\b[^>]*class="([^"]*)"/', $html, $m, PREG_SET_ORDER);
        expect($m)->not->toBeEmpty("{$view} must have containers");

        foreach ($m as [, $tag, $classes]) {
            expect(preg_split('/\s+/', $classes))->not->toContain('puzzle-card', "{$view}: <{$tag}> must not be a puzzle-card");
        }
    }

    $profile = file_get_contents(resource_path('views/profile/edit.blade.php'));
    preg_match('/<div class="([^"]*)">\s*<h2[^>]*>الإشعارات<\/h2>/u', $profile, $p);
    expect($p[1] ?? '')->not->toContain('puzzle-card')->and($p[1] ?? '')->toContain('glass');
});
