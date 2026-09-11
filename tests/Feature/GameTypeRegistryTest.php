<?php

use App\GameEngine\Definitions\LegacyGameTypeDefinition;
use App\GameEngine\Definitions\MemoryGameTypeDefinition;
use App\GameEngine\Definitions\SequenceGameTypeDefinition;
use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Validators\ExactStringValidator;
use App\GameEngine\Validators\MemoryMatchValidator;
use App\GameEngine\Validators\SequenceMatchValidator;
use App\Models\Puzzle;

beforeEach(function () {
    $this->registry = app(GameTypeRegistry::class);
});

test('resolves the legacy definition for a null game_type', function () {
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    expect($this->registry->validatorFor($puzzle))->toBeInstanceOf(ExactStringValidator::class)
        ->and($this->registry->rendererFor($puzzle))->toBe('games.legacy-input');
});

test('resolves the sequence definition for game_type=sequence', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'game_config' => ['items' => ['أ', 'ب']],
    ]);

    expect($this->registry->validatorFor($puzzle))->toBeInstanceOf(SequenceMatchValidator::class)
        ->and($this->registry->rendererFor($puzzle))->toBe('games.sequence');
});

test('resolves the memory definition for game_type=memory', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'memory',
        'game_config' => ['faces' => ['أ', 'ب', 'ج']],
    ]);

    expect($this->registry->validatorFor($puzzle))->toBeInstanceOf(MemoryMatchValidator::class);
});

test('an unknown game_type fails safely with a clear exception', function () {
    $puzzle = Puzzle::factory()->make(['game_type' => 'not_a_real_type']);

    expect(fn () => $this->registry->validatorFor($puzzle))->toThrow(InvalidArgumentException::class);
});

test('sequence normalization derives the identical solution_data as before the refactor', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'game_config' => ['items' => ['الأول', 'الثاني', 'الثالث']],
    ]);

    expect($puzzle->fresh()->solution_data)->toBe(['order' => [0, 1, 2]]);
});

test('memory normalization derives the identical doubled card set as before the refactor', function () {
    $puzzle = Puzzle::factory()->create([
        'game_type' => 'memory',
        'game_config' => ['faces' => ['أ', 'ب']],
    ]);

    $cards = $puzzle->fresh()->game_config['cards'];

    expect($cards)->toHaveCount(4)
        ->and(collect($cards)->pluck('face')->sort()->values()->all())->toBe(['أ', 'أ', 'ب', 'ب']);
});

test('game type options are derived from registered definitions without hardcoding', function () {
    expect($this->registry->gameTypeOptions())->toHaveKeys(['sequence', 'memory']);
});

test('public payload never includes solution_data or answer_hash for any registered game type', function () {
    $sequence = Puzzle::factory()->create([
        'game_type' => 'sequence',
        'game_config' => ['items' => ['أ', 'ب']],
    ])->fresh();

    $memory = Puzzle::factory()->create([
        'game_type' => 'memory',
        'game_config' => ['faces' => ['أ', 'ب']],
    ])->fresh();

    $legacy = Puzzle::factory()->create(['answer_raw' => 'صح']);

    $sequencePayload = (new SequenceGameTypeDefinition)->publicPayload($sequence);
    $memoryPayload = (new MemoryGameTypeDefinition)->publicPayload($memory);
    $legacyPayload = (new LegacyGameTypeDefinition)->publicPayload($legacy);

    expect($sequencePayload)->not->toHaveKey('solution_data')
        ->and($memoryPayload)->not->toHaveKey('solution_data')
        ->and($legacyPayload)->not->toHaveKey('solution_data')
        ->and($legacyPayload)->not->toHaveKey('answer_hash');
});