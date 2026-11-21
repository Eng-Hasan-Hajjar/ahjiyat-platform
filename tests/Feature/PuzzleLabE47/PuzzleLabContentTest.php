<?php

use App\Models\PuzzleLabExercise;
use Database\Seeders\PuzzleLabExerciseSeeder;

require_once __DIR__.'/_support.php';

/*
| E47 - المحتوى: حارس النموذج (لا تمرين ظاهر بمحتوى ناقص)، قواعد الرابط، والتمارين الأربعة الأصلية (Seeder صريح وidempotent وغير كاتب فوق الموجود).
*/

test('MOD-01: a complete exercise has no content problems; every missing piece is reported in Arabic', function () {
    expect(lab_exercise('sequence', 'draft')->contentProblems())->toBe([]);

    $empty = new PuzzleLabExercise(['game_type' => 'memory', 'level' => 'easy', 'game_config' => ['faces' => ['أ']]]);
    $problems = implode(' | ', $empty->contentProblems());

    foreach (['اكتب عنوان التمرين', 'اكتب وصفًا قصيرًا', 'اكتب تعليمات التمرين', 'اكتب الملاحظة التعليمية', 'من 3 إلى 8 عناصر'] as $expected) {
        expect($problems)->toContain($expected);
    }
});

test('MOD-02: content limits - 3 to 8 entries, no duplicates, short faces, no lone zero, valid type and level', function () {
    $problems = fn (array $override) => (new PuzzleLabExercise($override + [
        'game_type' => 'memory', 'level' => 'easy', 'title' => 'ت', 'summary' => 'و', 'instructions' => 'ت', 'wrong_note' => 'م',
        'game_config' => ['faces' => ['أ', 'ب', 'ج']],
    ]))->contentProblems();

    expect($problems([]))->toBe([])
        ->and($problems(['game_config' => ['faces' => ['أ', 'ب']]]))->not->toBe([])
        ->and($problems(['game_config' => ['faces' => range(1, 9)]]))->not->toBe([])
        ->and(implode($problems(['game_config' => ['faces' => ['أ', 'أ', 'ب']]])))->toContain('تكرار')
        ->and(implode($problems(['game_config' => ['faces' => ['أ', 'ب', str_repeat('ط', 15)]]])))->toContain('أطول من 14')
        ->and(implode($problems(['game_config' => ['faces' => ['0', 'ب', 'ج']]])))->toContain('الرقم 0')
        ->and($problems(['game_type' => 'spot_difference']))->not->toBe([])
        ->and($problems(['level' => 'extreme']))->not->toBe([])
        // فراغات زائدة وعناصر فارغة تُنظَّف قبل العدّ.
        ->and($problems(['game_config' => ['faces' => ['  أ ', '', 'ب', null, 'ج']]]))->toBe([]);
});

test('MOD-03: the model refuses to store a published exercise with bad content, from any writer (Filament, seeder, tinker)', function () {
    $live = lab_exercise('sequence', 'published');

    expect(fn () => $live->forceFill(['game_config' => ['items' => ['فقط', 'اثنان']]])->save())->toThrow(\DomainException::class);
    expect($live->fresh()->items())->toHaveCount(4);

    // المسودة تقبل الناقص (حفظ مسودة).
    $draft = lab_exercise('sequence', 'draft');
    $draft->forceFill(['game_config' => ['items' => ['فقط']]])->save();
    expect($draft->fresh()->items())->toHaveCount(1);
    expect(fn () => $draft->publish())->toThrow(\DomainException::class);
    expect($draft->fresh()->status)->toBe('draft');
});

test('MOD-04: status, publish stamp and authorship are not mass-assignable', function () {
    $exercise = new PuzzleLabExercise(['title' => 'ت', 'status' => 'published', 'published_at' => now(), 'created_by' => 5, 'updated_by' => 5]);

    expect($exercise->status)->toBeNull()->and($exercise->published_at)->toBeNull()->and($exercise->created_by)->toBeNull()->and($exercise->updated_by)->toBeNull();
});

test('MOD-05: the slug is generated when missing, immutable afterwards, and "preview" or malformed slugs are refused', function () {
    $auto = lab_exercise('sequence', 'draft');
    expect($auto->slug)->toMatch('/^lab-[a-z0-9]{8}$/');

    expect(fn () => $auto->forceFill(['slug' => 'another-slug'])->save())->toThrow(\LogicException::class);

    foreach (['preview', 'Bad Slug', 'ab', 'x_y'] as $bad) {
        expect(fn () => lab_exercise('sequence', 'draft', ['slug' => $bad]))->toThrow(\InvalidArgumentException::class);
    }
});

test('MOD-06: hide then publish again keeps the first publish date; hiding takes the exercise away from the lab list', function () {
    $exercise = lab_exercise('memory', 'draft');
    $exercise->publish();
    $first = $exercise->fresh()->published_at;

    $exercise->hide();
    expect($exercise->fresh()->isPlayable())->toBeFalse();

    $exercise->fresh()->publish();
    expect($exercise->fresh()->isPlayable())->toBeTrue()->and($exercise->fresh()->published_at->equalTo($first))->toBeTrue();
});

test('SEED-01: the seeder creates exactly four original exercises - two per type - all published, valid and playable end to end', function () {
    $this->seed(PuzzleLabExerciseSeeder::class);

    $all = PuzzleLabExercise::query()->ordered()->get();
    expect($all)->toHaveCount(4)
        ->and($all->where('game_type', 'memory'))->toHaveCount(2)
        ->and($all->where('game_type', 'sequence'))->toHaveCount(2)
        ->and($all->pluck('status')->unique()->all())->toBe(['published'])
        ->and($all->pluck('title')->unique())->toHaveCount(4)
        ->and($all->pluck('level')->unique()->count())->toBeGreaterThan(1);

    $player = lab_player();

    foreach ($all as $exercise) {
        expect($exercise->contentProblems())->toBe([])
            ->and($exercise->wrong_note)->not->toBeEmpty()
            ->and($exercise->success_explanation)->not->toBeEmpty();

        $this->actingAs($player)->get(route('puzzle-lab.show', $exercise))->assertOk()->assertSee($exercise->title);

        $start = lab_start($player, $exercise);
        $payload = $exercise->game_type === 'memory'
            ? ['matches' => lab_memory_pairs($player, $exercise, $start)]
            : ['order' => lab_correct_tokens($exercise, $start)];

        $this->actingAs($player)->postJson(route('puzzle-lab.rounds.check', $exercise), ['round' => $start['round']] + $payload)
            ->assertOk()->assertJson(['correct' => true, 'explanation' => $exercise->success_explanation]);
    }

    $this->actingAs($player)->get(route('puzzle-lab.index'))->assertOk()
        ->assertSee('أزواج الطبيعة')->assertSee('مضاعفات الاثني عشر')->assertSee('مراحل نمو النبتة')->assertSee('الكواكب من الأقرب إلى الشمس');
});

test('SEED-02: re-running never duplicates and never overwrites - an edited or hidden seeded exercise is left exactly as the editor left it', function () {
    $this->seed(PuzzleLabExerciseSeeder::class);

    PuzzleLabExercise::where('slug', 'lab-memory-nature')->firstOrFail()->forceFill(['title' => 'عنوان عدّله المحرّر'])->save();
    PuzzleLabExercise::where('slug', 'lab-sequence-plant')->firstOrFail()->hide();

    $this->seed(PuzzleLabExerciseSeeder::class);
    $this->seed(PuzzleLabExerciseSeeder::class);

    expect(PuzzleLabExercise::count())->toBe(4)
        ->and(PuzzleLabExercise::where('slug', 'lab-memory-nature')->value('title'))->toBe('عنوان عدّله المحرّر')
        ->and(PuzzleLabExercise::where('slug', 'lab-sequence-plant')->value('status'))->toBe('hidden');
});

test('SEED-03: existing content is untouched - a manual exercise survives and other tables are not written', function () {
    $mine = lab_exercise('sequence', 'published', ['title' => 'تمريني الخاص']);
    $before = lab_table_counts();

    $this->seed(PuzzleLabExerciseSeeder::class);

    $after = lab_table_counts();
    $changed = array_keys(array_filter($after, fn ($n, $t) => $n !== $before[$t], ARRAY_FILTER_USE_BOTH));

    expect($changed)->toBe(['puzzle_lab_exercises'])->and(PuzzleLabExercise::where('title', 'تمريني الخاص')->exists())->toBeTrue()->and(PuzzleLabExercise::count())->toBe(5);
});

test('SEED-04: explicit-only - refuses production and is not part of the default DatabaseSeeder', function () {
    // نستدعي run() مباشرة: db:seed نفسه يطلب تأكيدًا تفاعليًا بالإنتاج قبل أن يصل إلى الـseeder.
    $this->app['env'] = 'production';
    expect(fn () => (new PuzzleLabExerciseSeeder)->run())->toThrow(\RuntimeException::class);
    expect(PuzzleLabExercise::count())->toBe(0);

    $this->app['env'] = 'testing';
    expect(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))->not->toContain('PuzzleLabExerciseSeeder');
});

test('SEED-05: seeded content is plain original text - no media, no links, no external services', function () {
    foreach (PuzzleLabExerciseSeeder::exercises() as $row) {
        $text = json_encode($row, JSON_UNESCAPED_UNICODE);

        expect($text)->not->toMatch('~https?://|www\.|\.(png|jpe?g|gif|webp|mp4|mp3|svg)\b~i')
            ->and(array_keys($row))->not->toContain('image_path')->and(array_keys($row))->not->toContain('video');
    }
});
