<?php

use App\Models\PuzzleCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrator');
});

test('a valid image upload is accepted by PuzzleResource', function () {
    $category = PuzzleCategory::factory()->create();
    $file = UploadedFile::fake()->image('puzzle.jpg', 400, 300)->size(500);

    $this->actingAs($this->admin);

    $response = \Livewire\Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm([
            'title' => 'أحجية اختبار',
            'puzzle_category_id' => $category->id,
            'type' => 'image',
            'difficulty' => 'easy',
            'prompt' => 'سؤال تجريبي',
            'image_path' => $file,
            'answer_raw' => 'إجابة',
        ])
        ->call('create');

    $response->assertHasNoFormErrors();
});

test('an oversized image upload is rejected by PuzzleResource', function () {
    $category = PuzzleCategory::factory()->create();
    $file = UploadedFile::fake()->image('too-big.jpg')->size(5000);

    $this->actingAs($this->admin);

    $response = \Livewire\Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm([
            'title' => 'أحجية اختبار',
            'puzzle_category_id' => $category->id,
            'type' => 'image',
            'difficulty' => 'easy',
            'prompt' => 'سؤال تجريبي',
            'image_path' => $file,
            'answer_raw' => 'إجابة',
        ])
        ->call('create');

    $response->assertHasFormErrors(['image_path']);
});

test('a non-image file (e.g. a PHP script) is rejected by PuzzleResource', function () {
    $category = PuzzleCategory::factory()->create();
    $file = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');

    $this->actingAs($this->admin);

    $response = \Livewire\Livewire::test(\App\Filament\Resources\PuzzleResource\Pages\CreatePuzzle::class)
        ->fillForm([
            'title' => 'أحجية اختبار',
            'puzzle_category_id' => $category->id,
            'type' => 'image',
            'difficulty' => 'easy',
            'prompt' => 'سؤال تجريبي',
            'image_path' => $file,
            'answer_raw' => 'إجابة',
        ])
        ->call('create');

    $response->assertHasFormErrors(['image_path']);
});