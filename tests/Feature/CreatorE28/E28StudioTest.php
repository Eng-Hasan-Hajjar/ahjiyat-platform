<?php

use App\Models\CreatorPuzzle;
use App\Models\CreatorPuzzleRevision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    Storage::fake('public');
});

function e28_png(string $name = 'p.png', int $w = 200, int $h = 200): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

test('Y2-10 (10): an approved creator opens the studio, the list and the create page', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->get(route('creator.dashboard'))->assertOk()->assertSee('استوديو المبدع');
    $this->get(route('creator.puzzles.index'))->assertOk();
    $this->get(route('creator.puzzles.create'))->assertOk();
    $this->get(route('creator.puzzles.create', ['kind' => 'sequence']))->assertOk();
});

test('Y2-11 (11): the creator creates a draft - stored encrypted, in draft status, owned by the creator', function () {
    $creator = e28_creator();
    $payload = e28_data('text', ['title' => 'مسودتي الأولى']);

    $response = $this->actingAs($creator)->post(route('creator.puzzles.store'), $payload);
    $work = CreatorPuzzle::query()->firstOrFail();
    $response->assertRedirect(route('creator.puzzles.edit', $work->public_id));

    $revision = $work->latestRevision;
    expect($revision->status)->toBe('draft')->and($revision->kind)->toBe('text')->and($revision->title)->toBe('مسودتي الأولى')
        ->and($work->creator->user_id)->toBe($creator->id)->and($work->is_visible)->toBeFalse()->and($work->published_puzzle_id)->toBeNull();

    // الجواب الخام لا يُخزَّن نصًا صريحًا بقاعدة البيانات (حقل مشفَّر).
    expect(\Illuminate\Support\Facades\DB::table('creator_puzzle_revisions')->value('content'))->not->toContain('القلم');

    $this->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->assertSee('مسودتي الأولى');
});

test('Y2-12 (12): a non-creator (player, applicant, guest) is denied the studio', function () {
    $this->get(route('creator.dashboard'))->assertRedirect(route('login'));

    $player = e16User();
    $this->actingAs($player)->get(route('creator.dashboard'))->assertRedirect(route('creator.apply'));
    $this->actingAs($player)->post(route('creator.puzzles.store'), e28_data())->assertRedirect(route('creator.apply'));

    app(\App\Services\Creators\CreatorApplicationService::class)->apply($player, 'متقدّم ينتظر قرار المراجعة على طلبه الأول.');
    $this->actingAs($player->fresh())->get(route('creator.puzzles.index'))->assertRedirect(route('creator.apply'));

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-13 (13): a creator cannot read, edit, submit or delete another creator\'s work (404, nothing changes)', function () {
    $owner = e28_creator();
    $intruder = e28_creator();
    $work = e28_draft($owner, 'text', ['title' => 'خاصة بالمالك'])->work;
    $before = $work->latestRevision()->first()->only(['title', 'status']);

    $this->actingAs($intruder);
    $this->get(route('creator.puzzles.edit', $work->public_id))->assertNotFound();
    $this->put(route('creator.puzzles.update', $work->public_id), e28_data('text', ['title' => 'اختراق']))->assertNotFound();
    $this->post(route('creator.puzzles.submit', $work->public_id))->assertNotFound();
    $this->delete(route('creator.puzzles.destroy', $work->public_id))->assertNotFound();
    $this->get(route('creator.puzzles.preview', $work->public_id))->assertNotFound();

    expect($work->latestRevision()->first()->only(['title', 'status']))->toBe($before)->and(CreatorPuzzle::query()->count())->toBe(1);
});

test('Y2-14 (14): every supported game type is accepted by the studio', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['text', 'multiple_choice', 'sequence', 'memory'] as $kind) {
        $this->post(route('creator.puzzles.store'), e28_data($kind))->assertSessionHasNoErrors()->assertRedirect();
    }

    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => e28_png()]))->assertSessionHasNoErrors();

    expect(CreatorPuzzleRevision::query()->orderBy('id')->pluck('kind')->all())->toBe(['text', 'multiple_choice', 'sequence', 'memory', 'image']);
});

test('Y2-15 (15): an unsupported game type is rejected (spot_difference and unknown types)', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    foreach (['spot_difference', 'quiz', 'rm -rf', ''] as $kind) {
        $this->post(route('creator.puzzles.store'), e28_data('text', ['kind' => $kind]))->assertSessionHasErrors('kind');
    }

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-16 (16): an arbitrary renderer is rejected (present in the request, even empty)', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['renderer' => 'games.spot-difference']))->assertSessionHasErrors('renderer');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['renderer' => '']))->assertSessionHasErrors('renderer');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['game_type' => 'spot_difference']))->assertSessionHasErrors('game_type');

    expect(CreatorPuzzle::query()->count())->toBe(0);
});

test('Y2-17 (17): an arbitrary validation_type / score_mode is rejected, and the engine derives them server-side', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['validation_type' => 'always_true']))->assertSessionHasErrors('validation_type');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['score_mode' => 'flat']))->assertSessionHasErrors('score_mode');

    $work = e28_published($creator, 'sequence');
    $puzzle = $work->publishedPuzzle;
    $definition = app(\App\GameEngine\GameTypeRegistry::class)->definitionFor('sequence');
    expect($puzzle->renderer)->toBe($definition->renderer())->and($puzzle->validation_type)->toBe($definition->validationType())->and($puzzle->score_mode)->toBe($definition->scoreMode());
});

test('Y2-18 (18): raw HTML / script input is refused at entry and always rendered escaped', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $this->post(route('creator.puzzles.store'), e28_data('text', ['title' => '<script>alert(1)</script>']))->assertSessionHasErrors('title');
    $this->post(route('creator.puzzles.store'), e28_data('text', ['prompt' => 'سؤال <img src=x onerror=alert(1)> طويل بما يكفي']))->assertSessionHasErrors('prompt');
    $this->post(route('creator.puzzles.store'), e28_data('multiple_choice', ['choices' => ['<b>x</b>', 'y', 'z'], 'answer' => 'y']))->assertSessionHasErrors();

    expect(CreatorPuzzle::query()->count())->toBe(0);

    // نص يحوي رموزًا رياضية آمنة يُعرض مهرَّبًا (لا تفسير HTML) في صفحة التحرير.
    $this->post(route('creator.puzzles.store'), e28_data('text', ['title' => 'ما قيمة 3 > 2 و 1 < 2 ؟', 'prompt' => 'أيّهما أكبر: 3 > 2 أم 2 > 3 في المقارنة العددية؟']))->assertSessionHasNoErrors();
    $work = CreatorPuzzle::query()->firstOrFail();
    $html = $this->get(route('creator.puzzles.edit', $work->public_id))->assertOk()->getContent();
    expect($html)->toContain('3 &gt; 2');
});

test('Y2-19 (19): a valid image uploads to the owner directory under a server-generated name', function () {
    $creator = e28_creator();
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => e28_png('صورة.png')]))->assertSessionHasNoErrors();

    $revision = CreatorPuzzleRevision::query()->firstOrFail();
    $profileId = $creator->creatorProfile()->value('id');

    expect($revision->image_path)->toStartWith("community/puzzles/{$profileId}/")->and($revision->image_path)->toMatch('/\.png$/')->and($revision->image_path)->not->toContain('صورة');
    Storage::disk('public')->assertExists($revision->image_path);
});

test('Y2-20 (20): SVG (and script-bearing disguised files) are rejected', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $svg]))->assertSessionHasErrors('image');

    $disguised = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1; ?>');
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $disguised]))->assertSessionHasErrors('image');

    expect(CreatorPuzzle::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('Y2-21 (21): oversized or out-of-range images are rejected', function () {
    $creator = e28_creator();
    $this->actingAs($creator);

    $big = UploadedFile::fake()->image('big.jpg', 300, 300)->size((int) config('creators.media.max_kb') + 10);
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $big]))->assertSessionHasErrors('image');

    $tiny = e28_png('t.png', 20, 20);
    $this->post(route('creator.puzzles.store'), e28_data('image', ['image' => $tiny]))->assertSessionHasErrors('image');

    expect(CreatorPuzzle::query()->count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('Y2-22 (22): a path-traversal file name is neutralized - the stored path never contains the client name', function () {
    $creator = e28_creator();
    $evil = UploadedFile::fake()->image('../../../evil.png', 200, 200);

    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data('image', ['image' => $evil]))->assertSessionHasNoErrors();

    $path = CreatorPuzzleRevision::query()->firstOrFail()->image_path;
    $profileId = $creator->creatorProfile()->value('id');

    expect($path)->toStartWith("community/puzzles/{$profileId}/")->and($path)->not->toContain('..')->and($path)->not->toContain('evil')
        ->and($path)->toMatch('#^community/puzzles/\d+/[0-9a-f-]{36}\.png$#');
    expect(array_filter(Storage::disk('public')->allFiles(), fn ($f) => ! str_starts_with($f, 'community/puzzles/')))->toBe([]);
});
