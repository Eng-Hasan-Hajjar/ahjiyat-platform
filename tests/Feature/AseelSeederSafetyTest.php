<?php

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Puzzle;
use App\Models\Season;
use Database\Seeders\Seasons\AseelSeasonSeeder;

function seedAseelOnce(): Campaign
{
    (new AseelSeasonSeeder)->run();

    return Campaign::where('slug', 'aseel-season-01')->firstOrFail();
}

test('re-running the seeder does not overwrite a Season field the admin has since edited', function () {
    $campaign = seedAseelOnce();
    $season = $campaign->season;

    $season->update(['grand_prize_description' => 'جائزة حقيقية أدخلها الأدمن يدويًا']);

    (new AseelSeasonSeeder)->run();

    expect($season->fresh()->grand_prize_description)->toBe('جائزة حقيقية أدخلها الأدمن يدويًا');
});

test('re-running the seeder does not overwrite a Campaign title the admin has since edited', function () {
    $campaign = seedAseelOnce();

    $campaign->update(['title' => 'عنوان نهائي اعتمده العميل']);

    (new AseelSeasonSeeder)->run();

    expect($campaign->fresh()->title)->toBe('عنوان نهائي اعتمده العميل');
});

test('re-running the seeder does not overwrite a narrative step body the admin has since edited', function () {
    $campaign = seedAseelOnce();
    $gate1 = $campaign->stages->sortBy('sort_order')->first()->gates->sortBy('sort_order')->first();
    $narrativeStep = $gate1->steps->sortBy('sort_order')->first();

    $narrativeStep->update(['content' => ['body' => 'النص النهائي المعتمد من العميل']]);

    (new AseelSeasonSeeder)->run();

    expect($narrativeStep->fresh()->content['body'])->toBe('النص النهائي المعتمد من العميل');
});

test('re-running the seeder does not overwrite a puzzle image path the admin has since replaced', function () {
    seedAseelOnce();
    $puzzle = Puzzle::where('title', 'أثر ١: مكتب أصيل')->firstOrFail();

    $puzzle->update(['game_config' => [
        'image_before' => 'puzzles/spot-difference/real-photo-before.jpg',
        'image_after' => 'puzzles/spot-difference/real-photo-after.jpg',
    ]]);

    (new AseelSeasonSeeder)->run();

    expect($puzzle->fresh()->game_config['image_before'])->toBe('puzzles/spot-difference/real-photo-before.jpg');
});

test('re-running the seeder does not change a step reward the admin has since set to override', function () {
    $campaign = seedAseelOnce();
    $gate1 = $campaign->stages->sortBy('sort_order')->first()->gates->sortBy('sort_order')->first();
    $puzzleStep = $gate1->steps->sortBy('sort_order')->values()[1]; // "أثر في المكتب"

    $puzzleStep->update(['reward_mode' => CampaignStep::REWARD_MODE_OVERRIDE, 'reward_override_amount' => 500]);

    (new AseelSeasonSeeder)->run();

    expect($puzzleStep->fresh()->reward_mode)->toBe(CampaignStep::REWARD_MODE_OVERRIDE)
        ->and($puzzleStep->fresh()->reward_override_amount)->toBe(500);
});

test('re-running the seeder still does not duplicate any structure (idempotency preserved after the firstOrCreate fix)', function () {
    seedAseelOnce();
    $before = [
        Campaign::count(), Season::count(),
        \App\Models\CampaignStage::count(), \App\Models\CampaignGate::count(),
        CampaignStep::count(), Puzzle::count(),
    ];

    (new AseelSeasonSeeder)->run();
    $after = [
        Campaign::count(), Season::count(),
        \App\Models\CampaignStage::count(), \App\Models\CampaignGate::count(),
        CampaignStep::count(), Puzzle::count(),
    ];

    expect($after)->toBe($before);
});

test('the seeder still creates the full demo content correctly on a genuinely fresh database', function () {
    $campaign = seedAseelOnce();

    expect($campaign->title)->toBe('أصيل — الفتى الذي يسمع أكثر مما ينبغي')
        ->and($campaign->stages)->toHaveCount(3);
});