<?php

require_once __DIR__.'/NotificationTestHelpers.php';

use App\Models\CurrencyTransaction;
use App\Models\PlayerStreak;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Models\XpTransaction;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

test('E15-A: guests are redirected to login for every notification route (no private data)', function () {
    $note = e15Note($this->user);

    $this->get(route('notifications.index'))->assertRedirect(route('login'));
    $this->get(route('notifications.preferences'))->assertRedirect(route('login'));
    $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
    $this->post(route('notifications.read', $note->id))->assertRedirect(route('login'));
    $this->post(route('notifications.open', $note->id))->assertRedirect(route('login'));
    $this->delete(route('notifications.destroy', $note->id))->assertRedirect(route('login'));
});

test('E15-B/191: a user sees ONLY their own notifications', function () {
    e15Note($this->user, ['title' => 'إشعاري أنا']);
    e15Note($this->other, ['title' => 'إشعار شخص آخر سري']);

    $this->actingAs($this->user)->get(route('notifications.index'))
        ->assertOk()->assertSee('إشعاري أنا')->assertDontSee('إشعار شخص آخر سري');
});

test('E15-B/191: IDOR - another user notification id gives 404 on read, open and delete, and nothing changes', function () {
    $theirs = e15Note($this->other);

    $this->actingAs($this->user);
    $this->post(route('notifications.read', $theirs->id))->assertNotFound();
    $this->post(route('notifications.open', $theirs->id))->assertNotFound();
    $this->delete(route('notifications.destroy', $theirs->id))->assertNotFound();

    expect($theirs->fresh())->not->toBeNull()->and($theirs->fresh()->read_at)->toBeNull();
});

test('E15-C/192: unread count is correct in the page header and in the navbar badge', function () {
    e15Note($this->user);
    e15Note($this->user);
    e15Note($this->user, ['read_at' => now()]);
    e15Note($this->other); // لا يُحتسَب

    $this->actingAs($this->user)->get(route('notifications.index'))
        ->assertOk()->assertSee('لديك 2 إشعار غير مقروء')->assertSee('data-bell-badge', false);

    expect($this->user->unreadNotifications()->count())->toBe(2);
});

test('the navbar badge caps at 99+', function () {
    for ($i = 0; $i < 101; $i++) {
        e15Note($this->user);
    }

    $this->actingAs($this->user)->get(route('home'))->assertOk()->assertSee('99+');
});

test('E15-D/193: marking one own notification as read', function () {
    $a = e15Note($this->user);
    $b = e15Note($this->user);

    $this->actingAs($this->user)->post(route('notifications.read', $a->id))->assertRedirect();

    expect($a->fresh()->read_at)->not->toBeNull()->and($b->fresh()->read_at)->toBeNull();
});

test('E15-D/194: mark-all-read touches only the current user rows', function () {
    $mine = [e15Note($this->user), e15Note($this->user)];
    $theirs = e15Note($this->other);

    $this->actingAs($this->user)->post(route('notifications.read-all'))->assertRedirect();

    expect($this->user->unreadNotifications()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull()
        ->and($this->other->unreadNotifications()->count())->toBe(1);
});

test('a user can delete their own notification', function () {
    $mine = e15Note($this->user);

    $this->actingAs($this->user)->delete(route('notifications.destroy', $mine->id))->assertRedirect();

    expect($this->user->notifications()->count())->toBe(0);
});

test('E15-45: open marks read then redirects to the registry internal route', function () {
    $note = e15Note($this->user, ['type' => NotificationType::DailyQuestsAvailable]);

    $this->actingAs($this->user)->post(route('notifications.open', $note->id))->assertRedirect(route('quests.show'));

    expect($note->fresh()->read_at)->not->toBeNull();
});

test('E15-V/213: a tampered stored URL or a non-allowlisted route NEVER becomes a redirect target', function (array $tamper) {
    $note = e15Note($this->user, $tamper);

    $this->actingAs($this->user)->post(route('notifications.open', $note->id))
        ->assertRedirect(route('notifications.index'));
})->with([
    'external url as route' => [['route' => 'https://evil.example.com/steal']],
    'protocol-relative' => [['route' => '//evil.example.com']],
    'javascript scheme' => [['route' => 'javascript:alert(1)']],
    'real route but not allowed for this type' => [['route' => 'wallet.index']],
    'non-scalar params' => [['params' => [['nested' => 'x']]]],
    'unknown route name' => [['route' => 'no.such.route']],
]);

test('E15-136: GET pages never mutate state - viewing the center does not mark anything read', function () {
    $note = e15Note($this->user);

    $this->actingAs($this->user)->get(route('notifications.index'))->assertOk();
    $this->get(route('notifications.preferences'))->assertOk();

    expect($note->fresh()->read_at)->toBeNull();
});

test('E15-221/139: the full page paginates (15 per page) and never loads every row', function () {
    for ($i = 1; $i <= 20; $i++) {
        e15Note($this->user, ['created_at' => now()->subMinutes($i)]);
    }

    $page1 = $this->actingAs($this->user)->get(route('notifications.index'))->assertOk();
    expect(substr_count($page1->getContent(), 'data-notification-id'))->toBe(15);

    $page2 = $this->get(route('notifications.index', ['page' => 2]))->assertOk();
    expect(substr_count($page2->getContent(), 'data-notification-id'))->toBe(5);
});

test('E15-112: filters - unread only, and by category (checked on the list rows, not the navbar dropdown)', function () {
    $new = e15Note($this->user, ['title' => 'عنوان-ألف-جديد']);
    $old = e15Note($this->user, ['title' => 'عنوان-باء-قديم', 'read_at' => now()]);
    $streak = e15Note($this->user, ['title' => 'عنوان-جيم-سلسلة', 'type' => NotificationType::StreakAtRisk]);
    $row = fn ($n) => 'data-notification-id="'.$n->id.'"';

    $this->actingAs($this->user);

    $this->get(route('notifications.index', ['filter' => 'unread']))
        ->assertSee($row($new), false)->assertSee($row($streak), false)->assertDontSee($row($old), false);

    $this->get(route('notifications.index', ['category' => NotificationCategory::Streak->value]))
        ->assertSee($row($streak), false)->assertDontSee($row($new), false)->assertDontSee($row($old), false);

    // فئة غير معروفة (من الرابط) تُتجاهَل بدل أن تكسر الصفحة أو تُسرِّب استعلامًا حرًا.
    $this->get(route('notifications.index', ['category' => 'arbitrary-from-url']))
        ->assertOk()->assertSee($row($new), false)->assertSee($row($old), false)->assertSee($row($streak), false);
});

test('E15-W/214: stored title and body are escaped - never executed', function () {
    e15Note($this->user, ['title' => '<script>alert("x")</script>', 'body' => '<img src=x onerror=alert(1)>']);

    $html = $this->actingAs($this->user)->get(route('notifications.index'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert("x")</script>')
        ->and($html)->not->toContain('<img src=x onerror')
        ->and($html)->toContain('&lt;script&gt;');
});

test('E15-113: a clear empty state', function () {
    $this->actingAs($this->user)->get(route('notifications.index'))->assertOk()->assertSee('لا توجد إشعارات');
});

test('E15-Q/215: read / open / read-all / delete grant NOTHING - no XP, currency, quest, streak or purchase', function () {
    $note = e15Note($this->user, ['type' => NotificationType::DailyQuestsAvailable]);
    $other = e15Note($this->user);

    $snapshot = fn () => [
        XpTransaction::count(), CurrencyTransaction::count(), UserQuestProgress::count(),
        PlayerStreak::count(), StorePurchase::count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
    ];
    $before = $snapshot();

    $this->actingAs($this->user);
    $this->post(route('notifications.read', $other->id));
    $this->post(route('notifications.open', $note->id));
    $this->post(route('notifications.read-all'));
    $this->delete(route('notifications.destroy', $other->id));

    expect($snapshot())->toBe($before);
});

test('E15-219/220: the bell renders for a verified user and never for guests or unverified users', function () {
    e15Note($this->user, ['title' => 'عنوان بالجرس']);

    $this->actingAs($this->user)->get(route('home'))->assertOk()
        ->assertSee('عرض كل الإشعارات')->assertSee('عنوان بالجرس')->assertSee('aria-label="الإشعارات', false);

    auth()->logout();
    $this->get(route('home'))->assertOk()->assertDontSee('عرض كل الإشعارات')->assertDontSee('data-bell-badge', false);

    $unverified = User::factory()->create(['email_verified_at' => null]);
    e15Note($unverified, ['title' => 'لن يظهر']);
    $this->actingAs($unverified)->get(route('home'))->assertDontSee('عرض كل الإشعارات')->assertDontSee('لن يظهر');
});

test('E15-Y/240: the navbar costs at most 2 queries on the notifications table (no N+1)', function () {
    for ($i = 0; $i < 12; $i++) {
        e15Note($this->user);
    }

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "notifications"')) {
            $queries++;
        }
    });

    $this->actingAs($this->user)->get(route('home'))->assertOk();

    expect($queries)->toBeLessThanOrEqual(2);
});
