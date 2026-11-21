<?php

use App\Filament\Pages\CinematicBuilderPage;
use App\Models\Puzzle;
use App\Services\Cinematic\CinematicPuzzleBridge;
use App\Services\Cinematic\Admin\CinematicPuzzlePicker;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| SS-15..SS-18 — محدّد الأحجية في محرّر مشهد الأحجية: بحث على الخادم ضمن كل الأحجيات الصالحة للربط قبل تحديد العدد
| (كان: أول 300 حسب العنوان ثم فلترة في PHP)، وتكافؤ الاستعلام مع قاعدة الأهلية، والصلاحية، وأن الاختيار لا يحفظ حتى «حفظ المشهد».
*/

beforeEach(fn () => cin_seed());

function ss_puzzle_world(): array
{
    $w = cin_story();
    $scene = cin_authoring()->addScene($w['chapter'], ['type' => 'puzzle', 'title' => 'مشهد أحجية', 'stable_key' => 'puz_scene']);

    return $w + ['scene' => $scene->fresh()];
}

test('SS-15: an eligible puzzle that sorts after the first 300 titles is found by name - the old list (first 300 by title, then filtered) could not show it', function () {
    $w = ss_puzzle_world();
    $category = App\Models\PuzzleCategory::factory()->create(['name' => 'ألغاز المنطق']);
    $rows = [];

    foreach (range(1, 320) as $i) {
        $rows[] = Puzzle::factory()->make(['puzzle_category_id' => $category->id, 'title' => 'أحجية '.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
    }
    Puzzle::withoutEvents(function () use ($rows) {
        foreach ($rows as $p) {
            $p->save();
        }
    });
    $late = Puzzle::factory()->create(['puzzle_category_id' => $category->id, 'title' => 'ي آخر لغز أبجديًا', 'answer_raw' => 'مفتاح']);

    $bridge = app(CinematicPuzzleBridge::class);
    $oldList = Puzzle::query()->where('is_active', true)->orderBy('title')->limit(300)->get()->filter(fn (Puzzle $p) => $bridge->ineligibleReason($p) === null);
    expect($oldList->pluck('id')->all())->not->toContain($late->id);

    $this->actingAs($w['admin']);
    $c = Livewire::withQueryParams(['story' => $w['story']->public_id, 'scene' => $w['scene']->public_id])->test(CinematicBuilderPage::class);
    $r = ss_call($c, 'searchPuzzles', 'آخر لغز', 0, '', []);

    expect(collect($r['items'])->pluck('id')->all())->toBe([(string) $late->id])->and($r['total'])->toBe(1)
        ->and($r['items'][0]['parts'])->toContain('سهل')->toContain('#'.$late->id)->toContain('ألغاز المنطق');

    // بلا نص: عدد صغير فقط (لا تحميل كل الأحجيات)، مرتبة بالعنوان، مع «المزيد».
    $open = ss_call($c, 'searchPuzzles', '', 0, '', []);
    expect($open['items'])->toHaveCount(CinematicPuzzlePicker::PAGE_SIZE)->and($open['total'])->toBe(321)->and($open['next'])->toBe(CinematicPuzzlePicker::PAGE_SIZE);

    // لا إجابة ولا تلميح ولا هاش ولا مكافأة في المخرجات.
    $json = json_encode($r, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('answer')->not->toContain('hint')->not->toContain('gem_reward')->not->toContain('مفتاح');
});

test('SS-16: eligibleQuery() is exactly the eligibility rule - it returns the same puzzles that ineligibleReason() accepts, over every rejection case', function () {
    $good = Puzzle::factory()->create(['title' => 'صالحة', 'answer_raw' => 'مفتاح']);
    $inactive = Puzzle::factory()->create(['title' => 'معطلة', 'is_active' => false]);
    $interactive = Puzzle::factory()->create(['title' => 'تفاعلية', 'game_type' => 'sequence', 'game_config' => ['sequence' => ['a', 'b']]]);
    $community = Puzzle::factory()->create(['title' => 'مجتمع']);
    DB::table('puzzles')->where('id', $community->id)->update(['origin' => 'community']);
    $noAnswer = Puzzle::factory()->create(['title' => 'بلا جواب']);
    DB::table('puzzles')->where('id', $noAnswer->id)->update(['answer_hash' => '']);
    // (answer_hash عمود NOT NULL في القاعدة، فالحالة الفارغة الممكنة هي النص الفارغ فقط.)
    $emptyType = Puzzle::factory()->create(['title' => 'نوع فارغ']);
    DB::table('puzzles')->where('id', $emptyType->id)->update(['game_type' => '']);

    $bridge = app(CinematicPuzzleBridge::class);
    $byRule = Puzzle::query()->get()->filter(fn (Puzzle $p) => $bridge->ineligibleReason($p->fresh()) === null)->pluck('id')->sort()->values()->all();
    $byQuery = $bridge->eligibleQuery()->pluck('puzzles.id')->sort()->values()->all();

    expect($byQuery)->toBe($byRule)->and($byQuery)->toContain($good->id)->toContain($emptyType->id)
        ->and($byQuery)->not->toContain($inactive->id)->not->toContain($interactive->id)->not->toContain($community->id)->not->toContain($noAnswer->id);
});

test('SS-17: puzzle search permission (manage), unknown ids, and the linked puzzle stays visible with its reason when it is no longer eligible', function () {
    $w = ss_puzzle_world();
    $good = Puzzle::factory()->create(['title' => 'اللغز المربوط', 'answer_raw' => 'مفتاح']);
    cin_authoring()->updateScene($w['scene'], ['puzzle_id' => $good->id]);
    $other = Puzzle::factory()->create(['title' => 'لغز غير صالح', 'is_active' => false]);

    $this->actingAs(ss_user(['cinematic-stories.view', 'cinematic-stories.media']));
    Livewire::withQueryParams(['story' => $w['story']->public_id, 'scene' => $w['scene']->public_id])->test(CinematicBuilderPage::class)->call('searchPuzzles', '', 0, '', [])->assertForbidden();

    $this->actingAs($w['admin']);
    DB::table('puzzles')->where('id', $good->id)->update(['is_active' => false]);
    $c = Livewire::withQueryParams(['story' => $w['story']->public_id, 'scene' => $w['scene']->public_id])->test(CinematicBuilderPage::class);

    $r = ss_call($c, 'searchPuzzles', '', 0, (string) $good->id, []);
    expect($r['items'][0]['id'])->toBe((string) $good->id)->and($r['items'][0]['current'])->toBeTrue()->and($r['items'][0]['retired'])->toBeTrue()->and($r['items'][0]['note'])->toBe('الأحجية غير مفعّلة.');

    // معرّف أحجية غير صالحة غير مربوطة لا يُثبَّت ولا يُكشف، ومدخل غير رقمي يُهمل.
    foreach ([(string) $other->id, '999999', 'abc', '1 or 1=1'] as $current) {
        $rr = ss_call($c, 'searchPuzzles', '', 0, $current, []);
        expect(collect($rr['items'])->pluck('id')->all())->not->toContain((string) $other->id);
    }
});

test('SS-18: choosing a puzzle does not save the scene link; «حفظ المشهد» saves exactly the chosen one and it is shown after a refresh', function () {
    $w = ss_puzzle_world();
    $a = Puzzle::factory()->create(['title' => 'لغز ألف', 'answer_raw' => 'مفتاح']);
    $b = Puzzle::factory()->create(['title' => 'لغز باء', 'answer_raw' => 'مفتاح']);
    $this->actingAs($w['admin']);

    $c = Livewire::withQueryParams(['story' => $w['story']->public_id, 'scene' => $w['scene']->public_id])->test(CinematicBuilderPage::class);
    $c->set('sf.puzzle_id', (string) $b->id);
    expect($w['scene']->fresh()->puzzle_id)->toBeNull();

    $c->call('saveScene');
    expect($w['scene']->fresh()->puzzle_id)->toBe($b->id)->and($w['scene']->fresh()->puzzle_id)->not->toBe($a->id);

    Livewire::withQueryParams(['story' => $w['story']->public_id, 'scene' => $w['scene']->public_id])->test(CinematicBuilderPage::class)
        ->assertSet('sf.puzzle_id', (string) $b->id)->assertSee('لغز باء');

    // إزالة الأحجية = حفظ صريح أيضًا.
    $c->set('sf.puzzle_id', '')->call('saveScene');
    expect($w['scene']->fresh()->puzzle_id)->toBeNull();
});
