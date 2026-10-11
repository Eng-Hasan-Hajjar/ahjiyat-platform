<?php

use App\Models\LevelDefinition;
use App\Models\PlayerProgression;
use App\Models\User;
use App\Models\UserLevelUnlock;
use App\Services\Progression\LevelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E24 - كاش كتالوج المستويات للعرض فقط. ترقية المستوى والمكافآت (recalculateFor) تقرأ القاعدة مباشرةً ولا تتأثر بالكاش إطلاقًا.
 */
beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'name' => 'ثانٍ', 'xp_required_total' => 100]);
    LevelDefinition::factory()->create(['level_number' => 3, 'name' => 'ثالث', 'xp_required_total' => 300]);
    $this->levels = app(LevelService::class);
});

function e24_progress(User $user, int $xp, int $level): PlayerProgression
{
    return PlayerProgression::updateOrCreate(['user_id' => $user->id], ['total_xp' => $xp, 'current_level' => $level]);
}

test('E24: display methods return the same level/next/percent as before', function () {
    $user = User::factory()->create();
    $p = e24_progress($user, 150, 2);

    expect($this->levels->currentLevelFor($user, $p)->level_number)->toBe(2)
        ->and($this->levels->nextLevelFor($user, $p)->level_number)->toBe(3)
        ->and($this->levels->progressPercentFor($user, $p))->toBe(25.0)
        // بلا تمرير التقدّم (سلوك الاستدعاء القديم) النتيجة نفسها
        ->and($this->levels->currentLevelFor($user)->level_number)->toBe(2)
        ->and($this->levels->progressPercentFor($user))->toBe(25.0);
});

test('E24: top level reports 100 percent and no next level; inactive next levels are skipped', function () {
    $user = User::factory()->create();
    LevelDefinition::where('level_number', 3)->first()->update(['is_active' => false]);
    $p = e24_progress($user, 150, 2);

    expect($this->levels->nextLevelFor($user, $p))->toBeNull()
        ->and($this->levels->progressPercentFor($user, $p))->toBe(100.0);
});

test('E24: with no level rows at all the current level is the unsaved fallback, never an exception', function () {
    LevelDefinition::query()->delete();
    Cache::flush();
    $user = User::factory()->create();

    $level = $this->levels->currentLevelFor($user);

    expect($level->exists)->toBeFalse()->and($level->level_number)->toBe(1)->and($level->name)->toBe('المستوى الأول');
});

test('E24: the level table is read at most once for many display calls, and progression once when passed along', function () {
    $user = User::factory()->create();
    $p = e24_progress($user, 150, 2);
    Cache::flush();

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach (range(1, 5) as $_) {
        $this->levels->currentLevelFor($user, $p);
        $this->levels->nextLevelFor($user, $p);
        $this->levels->progressPercentFor($user, $p);
    }
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($log->filter(fn ($q) => str_contains($q, 'level_definitions'))->count())->toBe(1)
        ->and($log->filter(fn ($q) => str_contains($q, 'player_progressions'))->count())->toBe(0);
});

test('E24: saving or deleting a level invalidates the catalog immediately (admin edits are visible at once)', function () {
    $user = User::factory()->create();
    $p = e24_progress($user, 150, 2);

    expect($this->levels->nextLevelFor($user, $p)->name)->toBe('ثالث'); // يملأ الكاش

    LevelDefinition::where('level_number', 3)->first()->update(['name' => 'ثالث معدَّل']);
    expect($this->levels->nextLevelFor($user, $p)->name)->toBe('ثالث معدَّل');

    LevelDefinition::where('level_number', 3)->first()->delete();
    expect($this->levels->nextLevelFor($user, $p))->toBeNull();

    LevelDefinition::factory()->create(['level_number' => 3, 'name' => 'ثالث جديد', 'xp_required_total' => 400]);
    expect($this->levels->nextLevelFor($user, $p)->name)->toBe('ثالث جديد');
});

test('E24: a TTL of zero bypasses the cache entirely', function () {
    config(['performance.cache.level_catalog_ttl' => 0]);
    $user = User::factory()->create();
    $p = e24_progress($user, 10, 1);

    $this->levels->currentLevelFor($user, $p);

    expect(Cache::has(LevelService::CATALOG_CACHE_KEY))->toBeFalse();
});

test('E24: cache isolation - two users at different levels never see each other\'s level through the shared catalog', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $pa = e24_progress($a, 350, 3);
    $pb = e24_progress($b, 10, 1);

    expect($this->levels->currentLevelFor($a, $pa)->level_number)->toBe(3)
        ->and($this->levels->currentLevelFor($b, $pb)->level_number)->toBe(1)
        ->and($this->levels->nextLevelFor($b, $pb)->level_number)->toBe(2)
        ->and($this->levels->nextLevelFor($a, $pa))->toBeNull();
});

test('E24: level-up authority (recalculateFor) reads the database, never the display cache', function () {
    $user = User::factory()->create();
    $p = e24_progress($user, 150, 1);

    // نملأ كاش العرض بعتبة المستوى 2 = 100 ثم نغيّرها في القاعدة مباشرةً (دون أحداث النموذج) إلى 500: الكاش قديم عمدًا.
    expect($this->levels->nextLevelFor($user, $p)->xp_required_total)->toBe(100);
    DB::table('level_definitions')->where('level_number', 2)->update(['xp_required_total' => 500]);

    $this->levels->recalculateFor($user, $p);

    expect($p->fresh()->current_level)->toBe(1)
        ->and(UserLevelUnlock::where('user_id', $user->id)->count())->toBe(0);
});
