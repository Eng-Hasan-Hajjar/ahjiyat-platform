<?php

use App\Filament\Resources\CommunityPuzzleReportResource\Pages\ListCommunityPuzzleReports;
use App\Filament\Resources\CreatorProfileResource\Pages\ListCreatorProfiles;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ListCreatorPuzzleRevisions;
use App\Filament\Resources\CreatorPuzzleRevisionResource\Pages\ViewCreatorPuzzleRevision;
use App\Models\CreatorProfile;
use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\UserAccountService;
use Database\Seeders\DemoCreatorSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E28-X (إضافي على قائمة Y1–Y10) - الجزء التشغيلي: لوحة الإشراف (Filament) بصلاحياتها، تجميد الحساب → إخفاء، تنظيف الوسائط اليتيمة، بيانات Demo، قائمة التنقل.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('public');
});

test('X1: the moderation resources open for a moderator and stay closed to players and to roles without creators.view', function () {
    $creator = e28_creator();
    e28_pending($creator, 'text', ['title' => 'قيد الفحص']);
    $moderator = e28_reviewer('moderator');

    $this->actingAs($moderator);
    Livewire::test(ListCreatorProfiles::class)->assertSuccessful();
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertSee('قيد الفحص');
    Livewire::test(ListCommunityPuzzleReports::class)->assertSuccessful();
    auth()->logout();

    $this->actingAs(e16User());
    expect(\App\Filament\Resources\CreatorPuzzleRevisionResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CreatorProfileResource::canViewAny())->toBeFalse()
        ->and(\App\Filament\Resources\CommunityPuzzleReportResource::canViewAny())->toBeFalse();
    $this->get('/admin')->assertForbidden();   // لاعب عادي لا يدخل اللوحة أصلًا
});

test('X2: a moderator approves from the review queue; reasons are mandatory for changes/reject; a view-only role cannot act', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'للاعتماد من اللوحة']);
    $second = e28_pending($creator, 'text', ['title' => 'للرفض من اللوحة']);

    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $this->actingAs($viewOnly);
    Livewire::test(ListCreatorPuzzleRevisions::class)->assertSuccessful()->assertTableActionHidden('approve', $revision)->assertTableActionHidden('reject', $revision);
    auth()->logout();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('approve', $revision)->assertHasNoTableActionErrors();

    $revision->refresh();
    expect($revision->status)->toBe(CreatorPuzzleRevision::STATUS_APPROVED)
        ->and($revision->work->refresh()->is_visible)->toBeTrue()
        ->and($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorPuzzleRevisions::class)->callTableAction('reject', $second, ['note' => 'سبب الرفض مكتوب وواضح.'])->assertHasNoTableActionErrors();
    expect($second->refresh()->status)->toBe(CreatorPuzzleRevision::STATUS_REJECTED);
});

test('X3: the queue detail page never shows the answer, and the queue never loads the encrypted content column', function () {
    $creator = e28_creator();
    $revision = e28_pending($creator, 'text', ['title' => 'لغز الإشراف', 'answer' => 'جوابإشرافيسري']);

    $this->actingAs(e28_reviewer('moderator'));
    $html = Livewire::test(ViewCreatorPuzzleRevision::class, ['record' => $revision->getKey()])->assertSuccessful()->html();

    expect($html)->toContain('لغز الإشراف')->and(str_contains($html, 'جوابإشرافيسري'))->toBeFalse();
    e28_assert_no_secrets($html, ['جوابإشرافيسري']);
});

test('X4: freezing a creator account hides their works immediately, and unfreezing brings them back (no stale visibility)', function () {
    $creator = e28_creator();
    $work = e28_published($creator, 'text', ['title' => 'عمل يتأثر بالتجميد']);
    $admin = e28_reviewer('administrator');

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect(e27_titles(e27_suggest($this, 'يتأثر')))->toBe(['عمل يتأثر بالتجميد']);

    app(UserAccountService::class)->freeze($creator, $admin, 'سبب التجميد المؤقت للاختبار.');

    $this->get(route('community.puzzles.show', $work->public_id))->assertNotFound();
    expect($work->refresh()->is_visible)->toBeFalse()->and($work->hidden_reason)->toBe(CreatorPuzzle::HIDDEN_ACCOUNT_FROZEN)
        ->and(e27_titles(e27_suggest($this, 'يتأثر')))->toBe([]);

    $this->get(route('creators.show', $creator->creatorProfile()->value('slug')))->assertNotFound();

    app(UserAccountService::class)->unfreeze($creator->refresh(), $admin);

    $this->get(route('community.puzzles.show', $work->public_id))->assertOk();
    expect($work->refresh()->is_visible)->toBeTrue()->and($work->hidden_reason)->toBeNull();
});

test('X5: creators:prune-media removes only old orphaned files - never a referenced or a recent one - and --dry-run deletes nothing', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => UploadedFile::fake()->image('a.png', 200, 200)]))->assertSessionHasNoErrors();
    $referenced = CreatorPuzzleRevision::query()->firstOrFail()->image_path;
    $directory = dirname($referenced);

    $disk = Storage::disk('public');
    $disk->put($directory.'/orphan-old.png', 'x');
    $disk->put($directory.'/orphan-new.png', 'x');
    touch($disk->path($referenced), time() - 5 * 86400);                  // مرجعي قديم: يبقى لأنه مرتبط
    touch($disk->path($directory.'/orphan-old.png'), time() - 5 * 86400);  // يتيم قديم: يُحذف

    Artisan::call('creators:prune-media', ['--dry-run' => true]);
    $disk->assertExists($directory.'/orphan-old.png');

    Artisan::call('creators:prune-media');

    $disk->assertMissing($directory.'/orphan-old.png');
    $disk->assertExists($directory.'/orphan-new.png');
    $disk->assertExists($referenced);
});

test('X6: the demo seeder is idempotent, uses only the official services, and never touches wallets, XP or official attempts', function () {
    $this->seed(DemoCreatorSeeder::class);
    $first = [
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ];

    $this->seed(DemoCreatorSeeder::class);

    expect([
        'users' => User::query()->count(),
        'profiles' => CreatorProfile::query()->count(),
        'works' => CreatorPuzzle::query()->count(),
        'revisions' => CreatorPuzzleRevision::query()->count(),
        'canonical' => Puzzle::query()->where('origin', 'community')->count(),
    ])->toBe($first)
        ->and($first['works'])->toBeGreaterThan(0)
        ->and(\App\Models\XpTransaction::query()->count())->toBe(0)
        ->and(\App\Models\GemTransaction::query()->count())->toBe(0)
        ->and(\App\Models\PuzzleAttempt::query()->count())->toBe(0)
        ->and(Puzzle::query()->where('origin', 'community')->where(fn ($q) => $q->where('gem_reward', '>', 0)->orWhere('xp_reward', '>', 0))->count())->toBe(0);
});

test('X7: the demo seeder refuses to run in production', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => app(DemoCreatorSeeder::class)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(CreatorProfile::query()->count())->toBe(0);
});

test('X8: navigation - community puzzles are listed for everyone; the creator studio entry only for verified players', function () {
    $guest = $this->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($guest)->toContain(route('community.puzzles.index'))->and(str_contains($guest, route('creator.apply')))->toBeFalse();

    $verified = $this->actingAs(e16User())->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect($verified)->toContain(route('creator.apply'))->toContain('استوديو المبدع');
    auth()->logout();

    $unverified = $this->actingAs(e16User(['email_verified_at' => null]))->get(route('community.puzzles.index'))->assertOk()->getContent();
    expect(str_contains($unverified, route('creator.apply')))->toBeFalse();
});

test('X9: beyond the partial-match limit the community search filter is a bare-column prefix LIKE served by the visible-title index, and reads no encrypted content', function () {
    config(['search.partial_match_max_id' => 0]);
    $creator = e28_creator();
    e28_published($creator, 'text', ['title' => 'نمط مجتمع واحد']);
    e27_cold();

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $titles = e27_titles(e27_suggest($this, 'نمط', e16User()));
    $log = \Illuminate\Support\Facades\DB::getQueryLog();
    \Illuminate\Support\Facades\DB::disableQueryLog();

    $query = collect($log)->first(fn ($q) => str_contains($q['query'], 'search_tier') && str_contains($q['query'], 'from "creator_puzzles"'));
    expect($titles)->toBe(['نمط مجتمع واحد'])->and($query)->not->toBeNull();

    $where = substr($query['query'], (int) strpos($query['query'], ' from "'));
    expect($where)->not->toContain('REPLACE(')->not->toContain('LOWER(')->toMatch('/like \? escape \'!\'/')
        ->and(str_contains($query['query'], 'content'))->toBeFalse();

    foreach ($query['bindings'] as $binding) {
        if (is_string($binding) && str_contains($binding, '%')) {
            expect($binding)->not->toStartWith('%');
        }
    }

    $plan = collect(\Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']))->pluck('detail')->implode(' | ');
    expect($plan)->toContain('creator_puzzles_visible_title_index');
});
