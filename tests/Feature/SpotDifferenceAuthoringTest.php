<?php

use App\Filament\Forms\Components\HotspotEditor;
use App\Filament\GameTypeAuthoring\FilamentGameTypeSchemaRegistry;
use App\Filament\GameTypeAuthoring\SpotDifferenceHotspotValidation;
use App\GameEngine\Definitions\SpotDifferenceGameTypeDefinition;
use App\Models\Puzzle;

test('the HotspotEditor field points at the correct sibling image field by default', function () {
    $field = HotspotEditor::make('solution_data.hotspots');

    expect($field->getImageStatePath())->toBe('game_config.image_after')
        ->and($field->getBeforeImageStatePath())->toBeNull();
});

test('the HotspotEditor image field paths are configurable', function () {
    $field = HotspotEditor::make('solution_data.hotspots')
        ->imageField('game_config.custom_after')
        ->beforeImageField('game_config.custom_before');

    expect($field->getImageStatePath())->toBe('game_config.custom_after')
        ->and($field->getBeforeImageStatePath())->toBe('game_config.custom_before');
});

test('the HotspotEditor field is registered as part of the spot_difference authoring schema', function () {
    $fields = app(FilamentGameTypeSchemaRegistry::class)->allFields();

    $hotspotField = collect($fields)->first(fn ($field) => $field instanceof HotspotEditor);

    expect($hotspotField)->not->toBeNull();
});

test('valid hotspot data passes validation with no error', function () {
    $error = SpotDifferenceHotspotValidation::firstError([
        ['x' => 0.25, 'y' => 0.35, 'radius' => 0.05],
        ['x' => 0.62, 'y' => 0.58, 'radius' => 0.05],
    ]);

    expect($error)->toBeNull();
});

test('an empty hotspot list is rejected - at least one is required', function () {
    expect(SpotDifferenceHotspotValidation::firstError([]))->not->toBeNull();
});

test('an out-of-range x value is rejected', function () {
    $error = SpotDifferenceHotspotValidation::firstError([
        ['x' => 1.5, 'y' => 0.5, 'radius' => 0.05],
    ]);

    expect($error)->not->toBeNull()->and($error)->toContain('الأفقي');
});

test('an out-of-range y value is rejected', function () {
    $error = SpotDifferenceHotspotValidation::firstError([
        ['x' => 0.5, 'y' => -0.2, 'radius' => 0.05],
    ]);

    expect($error)->not->toBeNull()->and($error)->toContain('العمودي');
});

test('a non-numeric x/y value is rejected', function () {
    $error = SpotDifferenceHotspotValidation::firstError([
        ['x' => 'abc', 'y' => 0.5, 'radius' => 0.05],
    ]);

    expect($error)->not->toBeNull();
});

test('an out-of-range radius is rejected', function () {
    $tooSmall = SpotDifferenceHotspotValidation::firstError([
        ['x' => 0.5, 'y' => 0.5, 'radius' => 0.001],
    ]);
    $tooLarge = SpotDifferenceHotspotValidation::firstError([
        ['x' => 0.5, 'y' => 0.5, 'radius' => 0.9],
    ]);

    expect($tooSmall)->not->toBeNull()
        ->and($tooLarge)->not->toBeNull();
});

test('the second hotspot is validated too, not just the first', function () {
    $error = SpotDifferenceHotspotValidation::firstError([
        ['x' => 0.5, 'y' => 0.5, 'radius' => 0.05],
        ['x' => 2.0, 'y' => 0.5, 'radius' => 0.05],
    ]);

    expect($error)->toContain('رقم 2');
});

test('a puzzle with hotspots entered the old way (manual Repeater) still normalizes and loads correctly', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'solution_data' => [
            'hotspots' => [
                ['x' => '0.3', 'y' => '0.4', 'radius' => '0.05'],
            ],
        ],
    ]);

    expect($puzzle->fresh()->solution_data['hotspots'][0]['x'])->toBeFloat()
        ->and($puzzle->fresh()->solution_data['hotspots'][0]['x'])->toBe(0.3);
});

test('existing hotspot data is exactly what the HotspotEditor state path will read (same column, same shape)', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'solution_data' => ['hotspots' => [['x' => 0.1, 'y' => 0.2, 'radius' => 0.05]]],
    ]);

    expect(data_get($puzzle->toArray(), 'solution_data.hotspots.0.x'))->toBe(0.1);
});

test('publicPayload never exposes hotspot coordinates or radius after authoring changes', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'puzzles/spot-difference/a.png', 'image_after' => 'puzzles/spot-difference/b.png'],
        'solution_data' => ['hotspots' => [
            ['x' => 0.42, 'y' => 0.24, 'radius' => 0.05],
            ['x' => 0.11, 'y' => 0.88, 'radius' => 0.075],
        ]],
    ]);

    $payload = app(SpotDifferenceGameTypeDefinition::class)->publicPayload($puzzle);

    expect($payload)->toHaveKeys(['image_before', 'image_after', 'required_differences'])
        ->and($payload)->not->toHaveKey('hotspots')
        ->and($payload['required_differences'])->toBe(2)
        ->and(json_encode($payload))->not->toContain('0.42')
        ->and(json_encode($payload))->not->toContain('radius');
});

test('required_differences in the public payload always equals the hotspot count, by construction', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'spot_difference',
        'game_config' => ['image_before' => 'a.png', 'image_after' => 'b.png'],
        'solution_data' => ['hotspots' => array_fill(0, 5, ['x' => 0.5, 'y' => 0.5, 'radius' => 0.05])],
    ]);

    $payload = app(SpotDifferenceGameTypeDefinition::class)->publicPayload($puzzle);

    expect($payload['required_differences'])->toBe(5);
});