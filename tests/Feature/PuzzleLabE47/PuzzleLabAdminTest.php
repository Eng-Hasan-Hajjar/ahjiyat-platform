<?php

use App\Filament\Resources\PuzzleLabExerciseResource;
use App\Filament\Resources\PuzzleLabExerciseResource\Pages\CreatePuzzleLabExercise;
use App\Filament\Resources\PuzzleLabExerciseResource\Pages\EditPuzzleLabExercise;
use App\Filament\Resources\PuzzleLabExerciseResource\Pages\ListPuzzleLabExercises;
use App\Models\PuzzleLabExercise;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E47 - إدارة التمارين من Filament: الصلاحيات، إنشاء مسودة بنوعي التمرين بمحرّرات القوائم (بلا JSON)، المعاينة، النشر والإخفاء، وحماية التمرين الظاهر من الحفظ الناقص.
*/

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** حالة محرّر القائمة البسيطة (Repeater::simple) كما يحملها Livewire: عنصر لكل صف بحقله الداخلي. */
function lab_repeater(string $field, array $values): array
{
    return array_map(fn (string $value) => [$field => $value], $values);
}

function lab_viewer(): User
{
    test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->givePermissionTo(['admin.access', 'puzzles.view']);

    return $user;
}

test('ADM-01: the resource is reachable with the existing puzzles permissions only - a player is turned away, a viewer reads without publish rights', function () {
    $admin = lab_admin();
    $viewer = lab_viewer();
    $player = lab_player();
    $exercise = lab_exercise('sequence', 'draft');

    $this->actingAs($admin)->get('/admin/puzzle-lab-exercises')->assertOk()->assertSee('تمارين مختبر الأحجيات');

    $this->flushSession();
    $this->actingAs($player)->get('/admin/puzzle-lab-exercises')->assertForbidden();
    expect(PuzzleLabExerciseResource::canViewAny())->toBeFalse();

    $this->flushSession();
    $this->actingAs($viewer);
    expect(PuzzleLabExerciseResource::canViewAny())->toBeTrue()->and(PuzzleLabExerciseResource::canCreate())->toBeFalse();
    Livewire::test(ListPuzzleLabExercises::class)->assertSuccessful()->assertTableActionHidden('publish', $exercise)->assertTableActionHidden('hide', $exercise);
});

test('ADM-02: creating a memory exercise saves a DRAFT with type, level, order, texts and the pairs list - no JSON typing, hidden from players', function () {
    $admin = lab_admin();
    $this->actingAs($admin);

    Livewire::test(CreatePuzzleLabExercise::class)
        ->fillForm([
            'title' => 'ذاكرة الفواكه', 'game_type' => 'memory', 'level' => 'medium', 'sort_order' => 7,
            'summary' => 'أزواج فواكه', 'instructions' => 'طابق الأزواج.', 'wrong_note' => 'تذكّر المواضع.', 'success_explanation' => 'أحسنت.',
            'game_config' => ['faces' => lab_repeater('face', ['تفاح', 'موز', 'عنب', 'برتقال'])],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $exercise = PuzzleLabExercise::query()->where('title', 'ذاكرة الفواكه')->firstOrFail();

    expect($exercise->status)->toBe('draft')->and($exercise->game_type)->toBe('memory')->and($exercise->level)->toBe('medium')->and($exercise->sort_order)->toBe(7)
        ->and($exercise->faces())->toBe(['تفاح', 'موز', 'عنب', 'برتقال'])->and($exercise->created_by)->toBe($admin->id)->and($exercise->published_at)->toBeNull()
        ->and($exercise->slug)->toMatch('/^lab-[a-z0-9]{8}$/')->and($exercise->contentProblems())->toBe([]);

    // مسودة: لا تظهر للاعب بأي مسار.
    $this->flushSession();
    $this->actingAs(lab_player())->get(route('puzzle-lab.index'))->assertDontSee('ذاكرة الفواكه');
    $this->actingAs(lab_player())->get(route('puzzle-lab.show', $exercise))->assertNotFound();
});

test('ADM-03: creating a sequence exercise (custom slug) saves a draft; the type cannot be changed afterwards', function () {
    $this->actingAs(lab_admin());

    Livewire::test(CreatePuzzleLabExercise::class)
        ->fillForm([
            'title' => 'مراحل الماء', 'game_type' => 'sequence', 'level' => 'hard', 'sort_order' => 3, 'slug' => 'water-cycle',
            'summary' => 'دورة الماء', 'instructions' => 'رتّب.', 'wrong_note' => 'فكّر بالبداية.',
            'game_config' => ['items' => lab_repeater('item', ['تبخّر', 'تكاثف', 'هطول', 'جريان'])],
        ])
        ->call('create')->assertHasNoFormErrors();

    $exercise = PuzzleLabExercise::where('slug', 'water-cycle')->firstOrFail();
    expect($exercise->items())->toBe(['تبخّر', 'تكاثف', 'هطول', 'جريان'])->and($exercise->status)->toBe('draft');

    Livewire::test(EditPuzzleLabExercise::class, ['record' => $exercise->getRouteKey()])->assertFormFieldIsDisabled('game_type')->assertFormFieldIsDisabled('slug');
});

test('ADM-04: create form validation - title and type are required, the reserved slug and bad slugs are refused', function () {
    $this->actingAs(lab_admin());

    Livewire::test(CreatePuzzleLabExercise::class)->fillForm(['title' => '', 'game_type' => null])->call('create')->assertHasFormErrors(['title' => 'required', 'game_type' => 'required']);

    foreach (['preview', 'Bad Slug', 'ab'] as $bad) {
        Livewire::test(CreatePuzzleLabExercise::class)
            ->fillForm(['title' => 'س', 'game_type' => 'sequence', 'slug' => $bad, 'game_config' => ['items' => lab_repeater('item', ['a', 'b', 'c'])]])
            ->call('create')->assertHasFormErrors(['slug']);
    }
});

test('ADM-05: publishing an incomplete draft is refused with the reasons; a complete one is published, then hidden, then republished', function () {
    $this->actingAs(lab_admin());
    $draft = lab_exercise('sequence', 'draft', ['wrong_note' => null]);

    Livewire::test(EditPuzzleLabExercise::class, ['record' => $draft->getRouteKey()])->callAction('publish')->assertNotified('لا يمكن نشر التمرين بعد');
    expect($draft->fresh()->status)->toBe('draft');

    $draft->forceFill(['wrong_note' => 'ملاحظة.'])->save();
    Livewire::test(EditPuzzleLabExercise::class, ['record' => $draft->getRouteKey()])->callAction('publish')->assertNotified('نُشر التمرين ويظهر الآن للاعبين.');
    $published = $draft->fresh();
    expect($published->status)->toBe('published')->and($published->published_at)->not->toBeNull();

    $this->flushSession();
    $this->actingAs(lab_player())->get(route('puzzle-lab.show', $published))->assertOk();

    $this->flushSession();
    $this->actingAs(lab_admin());
    Livewire::test(EditPuzzleLabExercise::class, ['record' => $published->getRouteKey()])->assertActionHidden('publish')->callAction('hide')->assertNotified('أُخفي التمرين عن اللاعبين.');
    expect($published->fresh()->status)->toBe('hidden');

    $this->flushSession();
    $this->actingAs(lab_player())->get(route('puzzle-lab.show', $published))->assertNotFound();

    $this->flushSession();
    $this->actingAs(lab_admin());
    Livewire::test(ListPuzzleLabExercises::class)->assertTableActionVisible('publish', $published->fresh())->callTableAction('publish', $published->fresh());
    expect($published->fresh()->status)->toBe('published');
});

test('ADM-06: the table publish/hide actions follow the status and are limited to puzzles.publish', function () {
    $this->actingAs(lab_admin());
    $draft = lab_exercise('memory', 'draft');
    $live = lab_exercise('memory', 'published');

    Livewire::test(ListPuzzleLabExercises::class)
        ->assertTableActionVisible('publish', $draft)->assertTableActionHidden('hide', $draft)
        ->assertTableActionVisible('hide', $live)->assertTableActionHidden('publish', $live)
        ->assertTableActionVisible('preview', $draft)
        ->assertCanSeeTableRecords([$draft, $live]);
});

test('ADM-07: saving an edit that would leave a PUBLISHED exercise incomplete is refused and the stored exercise is untouched', function () {
    $this->actingAs(lab_admin());
    $live = lab_exercise('sequence', 'published', ['title' => 'عنوان سليم']);

    Livewire::test(EditPuzzleLabExercise::class, ['record' => $live->getRouteKey()])
        ->fillForm(['instructions' => '', 'title' => 'عنوان جديد'])
        ->call('save')
        ->assertNotified('لم يُحفظ التعديل: التمرين ظاهر للاعبين ولا يجوز أن يصبح غير مكتمل');

    $fresh = $live->fresh();
    expect($fresh->title)->toBe('عنوان سليم')->and($fresh->instructions)->toBe('اقرأ التعليمات ثم العب.')->and($fresh->isPlayable())->toBeTrue();

    // وتعديل صالح لتمرين ظاهر يُحفظ ويبقى ظاهرًا.
    Livewire::test(EditPuzzleLabExercise::class, ['record' => $live->getRouteKey()])->fillForm(['title' => 'عنوان محسّن', 'sort_order' => 9])->call('save')->assertHasNoFormErrors();
    expect($live->fresh()->title)->toBe('عنوان محسّن')->and($live->fresh()->sort_order)->toBe(9)->and($live->fresh()->isPlayable())->toBeTrue()->and($live->fresh()->updated_by)->not->toBeNull();
});

test('ADM-08: admin preview shows a draft to staff only - banner with status, the exercise itself, and nothing becomes available to players', function () {
    $admin = lab_admin();
    $draft = lab_exercise('sequence', 'draft', ['title' => 'مسودة للمعاينة']);

    $this->actingAs($admin)->get(route('puzzle-lab.preview', $draft))->assertOk()
        ->assertSee('معاينة إدارية')->assertSee('مسودة')->assertSee('مسودة للمعاينة')->assertSee('للتدريب فقط. لا تُحتسب جواهر أو نقاط خبرة.')
        ->assertSee('العودة إلى تعديل التمرين');

    $this->flushSession();
    $this->actingAs(lab_viewer())->get(route('puzzle-lab.preview', $draft))->assertOk()->assertDontSee('العودة إلى تعديل التمرين');

    $this->flushSession();
    $this->actingAs(lab_player())->get(route('puzzle-lab.preview', $draft))->assertNotFound();
    expect($draft->fresh()->status)->toBe('draft');
});

test('ADM-09: an incomplete draft previews with the exact reasons and no playable board; rounds are refused even for staff', function () {
    $admin = lab_admin();
    $draft = lab_exercise('memory', 'draft', ['summary' => null, 'game_config' => ['faces' => ['أ', 'ب']]]);

    $html = $this->actingAs($admin)->get(route('puzzle-lab.preview', $draft))->assertOk()
        ->assertSee('التمرين غير مكتمل')->assertSee('اكتب وصفًا قصيرًا')->assertSee('من 3 إلى 8 عناصر')->getContent();
    expect($html)->not->toContain('x-data="labRound');

    $this->actingAs($admin)->postJson(route('puzzle-lab.rounds.start', $draft))->assertStatus(422);
});

test('ADM-10: the player-facing round endpoints work on a draft for staff only (preview play), never for players', function () {
    $draft = lab_exercise('sequence', 'draft');

    $staff = lab_admin();
    $start = lab_start($staff, $draft);
    $this->actingAs($staff)->postJson(route('puzzle-lab.rounds.check', $draft), ['round' => $start['round'], 'order' => lab_correct_tokens($draft, $start)])->assertOk()->assertJson(['correct' => true]);

    $this->flushSession();
    $this->actingAs(lab_player())->postJson(route('puzzle-lab.rounds.start', $draft))->assertNotFound();
});
