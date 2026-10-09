<?php

use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\StoreItem;
use App\Models\User;
use App\Services\PlayerIdentity\CosmeticLoadoutService;
use App\Services\Store\InventoryService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

function e111SeedLeaderboardPlayers(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $user = User::factory()->create();

        PuzzleAttempt::create([
            'user_id' => $user->id,
            'puzzle_id' => Puzzle::factory()->create()->id,
            'attempt_number' => 1,
            'is_correct' => true,
        ]);

        $avatar = StoreItem::factory()->cosmeticAvatar()->create();
        app(InventoryService::class)->grant($user, $avatar, 1, 'test');
        app(CosmeticLoadoutService::class)->equip($user, $avatar);
    }
}

function e111CountLeaderboardQueries($test): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->get(route('leaderboard.index'))->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the leaderboard runs a constant number of queries no matter how many players it lists - no N+1', function () {
    // E23: الزمن مجمَّد. تتبّع البصمة (device_sightings) يصدر UPDATE واحدًا فقط حين تتغيّر الثانية بين طلبين، فكان العدّ يختلف بواحد صدفةً (~5%) دون أي N+1.
    $this->freezeTime();

    e111SeedLeaderboardPlayers(3);
    $this->get(route('leaderboard.index'))->assertOk(); // تسخين إعدادات المنصة المُخزَّنة
    $small = e111CountLeaderboardQueries($this);

    e111SeedLeaderboardPlayers(9);
    $this->get(route('leaderboard.index'))->assertOk();
    $large = e111CountLeaderboardQueries($this);

    expect($large)->toBe($small);
});