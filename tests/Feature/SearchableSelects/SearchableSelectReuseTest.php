<?php

use App\Filament\Pages\CinematicStoriesPage;
use App\Filament\Pages\ModerationCasePage;
use App\Filament\Resources\ReferralCampaignResource\Pages\CreateReferralCampaign;
use App\Filament\Support\SearchableOptions;
use App\Models\Campaign;
use App\Models\Cinematic\CinematicStory;
use App\Models\CreatorSeries;
use App\Models\Season;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';
require_once __DIR__.'/../TrustSafetyE38/_support.php';
require_once __DIR__.'/../ReferralE33/_support.php';

/*
| SS-19..SS-33 — إعادة الاستخدام خارج السلاسل السينمائية (E48):
|   - مساعد Filament SearchableOptions فوق قائمة طويلة (حملات/مواسم)،
|   - صفحة حقيقية من Filament (حملة الإحالة: «موسم مرتبط»)،
|   - مستهلكان للمكوّن المشترك <x-searchable-select> في صفحات غير المنشئ: «إعادة تعيين الحالة» (الإشراف) و«السلسلة المرتبطة» (القصص).
| SQLite وبلا متصفح؛ الفحص البصري/لوحة المفاتيح في المتصفح الحقيقي يجري خارج Pest (انظر دليل التسليم).
*/

beforeEach(function () {
    cin_seed();
    e38_seed();
});

function ss_get(): Get
{
    return new Get(TextInput::make('x'));
}

/** يزرع $count حملة أحدث من أي حملة سابقة بعناوين لا تحوي كلمات البحث. */
function ss_campaigns(int $count): void
{
    $rows = [];

    foreach (range(1, $count) as $i) {
        $rows[] = ['slug' => 'filler-'.$i.'-'.Str::lower(Str::random(5)), 'title' => 'حملة حشو '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'description' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('campaigns')->insert($chunk);
    }
}

test('SS-19: the Filament helper finds a record older than the newest 100 and the initial list is small - a limit(100)->pluck() list could never show it', function () {
    $old = Campaign::factory()->create(['title' => 'Zebra_Old Campaign']);
    ss_campaigns(150);

    $opts = SearchableOptions::make(fn () => Campaign::query(), 'title')->newestFirst();

    $initial = $opts->initial(ss_get());

    expect($initial)->toHaveCount(SearchableOptions::INITIAL)->and($initial)->not->toHaveKey($old->id);

    // الأقدم رقم 151 من الأحدث: خارج أحدث 100 وخارج أول 20.
    expect($opts->results('zebra_old', ss_get()))->toBe([$old->id => 'Zebra_Old Campaign'])
        ->and($opts->results('ZEBRA', ss_get()))->toHaveKey($old->id)
        ->and($opts->results('old camp', ss_get()))->toHaveKey($old->id)
        ->and($opts->results('Campaign Zebra', ss_get()))->toHaveKey($old->id);
});

test('SS-20: Arabic titles are found, tokens are AND, and underscore or percent in a search are literal characters', function () {
    $ar = Campaign::factory()->create(['title' => 'حملة رمضان الكبرى']);
    $a = Campaign::factory()->create(['title' => 'PLM_S01 final']);
    $b = Campaign::factory()->create(['title' => 'PLMxS01 final']);
    ss_campaigns(120);

    $opts = SearchableOptions::make(fn () => Campaign::query(), 'title');

    expect($opts->results('رمضان', ss_get()))->toBe([$ar->id => 'حملة رمضان الكبرى'])
        ->and($opts->results('رمضان الكبرى', ss_get()))->toHaveKey($ar->id)
        ->and($opts->results('رمضان PLM', ss_get()))->toBe([])
        ->and(array_keys($opts->results('PLM_S01', ss_get())))->toBe([$a->id])
        ->and($opts->results('100%', ss_get()))->toBe([]);
});

test('SS-21: the caller scope stays in force for the initial list and for search results, and results are capped', function () {
    $hidden = Campaign::factory()->create(['title' => 'Hidden needle', 'is_active' => false]);
    $shown = Campaign::factory()->create(['title' => 'Shown needle', 'is_active' => true]);
    ss_campaigns(60);

    $opts = SearchableOptions::make(fn () => Campaign::query()->where('is_active', true), 'title');

    expect($opts->results('needle', ss_get()))->toBe([$shown->id => 'Shown needle'])
        ->and($opts->initial(ss_get()))->not->toHaveKey($hidden->id)
        ->and($opts->results('حملة', ss_get()))->toHaveCount(SearchableOptions::RESULTS);
});

test('SS-22: a saved value outside the scope or beyond the initial list keeps its readable name; an unknown id never throws', function () {
    $retired = Campaign::factory()->create(['title' => 'حملة مؤرشفة قديمة', 'is_active' => false]);
    $second = Campaign::factory()->create(['title' => 'Second saved', 'is_active' => true]);
    ss_campaigns(80);

    $opts = SearchableOptions::make(fn () => Campaign::query()->where('is_active', true), 'title')->newestFirst();

    expect($opts->labelFor($retired->id, ss_get()))->toBe('حملة مؤرشفة قديمة')
        ->and($opts->labelFor(99999999, ss_get()))->toBe('#99999999')
        ->and($opts->labelFor('', ss_get()))->toBeNull()
        ->and($opts->labelsFor([$retired->id, $second->id, 424242], ss_get()))->toBe([$retired->id => 'حملة مؤرشفة قديمة', $second->id => 'Second saved', 424242 => '#424242']);
});

test('SS-23: apply() keeps what the caller configured - required, multiple, default, live - and only adds server search', function () {
    $opts = SearchableOptions::make(fn () => Campaign::query(), 'title');

    $single = $opts->apply(Select::make('c')->required());
    $multi = $opts->apply(Select::make('cs')->multiple()->default([1, 2])->live());

    expect($single->isRequired())->toBeTrue()->and($single->isSearchable())->toBeTrue()->and($single->isNative())->toBeFalse()
        ->and($single->hasDynamicSearchResults())->toBeTrue()->and($single->hasDynamicOptions())->toBeTrue()
        ->and($single->getOptionsLimit())->toBe(SearchableOptions::RESULTS)
        ->and($multi->isMultiple())->toBeTrue()->and($multi->isLive())->toBeTrue()->and($multi->getDefaultState())->toBe([1, 2])
        ->and((new Select('plain'))->isRequired())->toBeFalse();
});

test('SS-24: a real Filament page (new referral campaign) shows a short list, finds an old season by its campaign title, and keeps the saved name', function () {
    e33_seed();
    $oldCampaign = Campaign::factory()->create(['title' => 'Zebra old season']);
    $oldSeason = Season::factory()->create(['campaign_id' => $oldCampaign->id]);

    foreach (range(1, 70) as $i) {
        Season::factory()->create(['campaign_id' => Campaign::factory()->create(['title' => 'موسم '.$i])->id]);
    }

    $this->actingAs(e33_admin());
    $lw = Livewire::test(CreateReferralCampaign::class)->assertSuccessful();
    $field = $lw->instance()->form->getComponent('data.season_id');

    expect($field)->not->toBeNull()
        ->and($field->getOptions())->toHaveCount(SearchableOptions::INITIAL)->and($field->getOptions())->not->toHaveKey($oldSeason->id)
        ->and($field->getSearchResults('zebra'))->toBe([$oldSeason->id => 'Zebra old season'])
        ->and($field->getSearchResults('ZEBRA OLD'))->toHaveKey($oldSeason->id)
        ->and($field->isRequired())->toBeFalse();

    $field->state($oldSeason->id);
    expect($field->getOptionLabel())->toBe('Zebra old season');
});

test('SS-25: the page that edits a case no longer ships moderators as <option> elements, and the shared component is wired', function () {
    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);

    $html = Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful()->html();

    expect($html)->toContain('data-assignee-picker')->toContain('data-ss-root')->toContain('searchAssignees')->not->toContain('<option value="'.$admin->id.'"');
});

function ss_moderators(int $count): void
{
    foreach (range(1, $count) as $i) {
        $u = e16User(['name' => 'Mod '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        $u->givePermissionTo('trust-safety.manage-cases');
    }
}

test('SS-26: the moderator search reaches a manager the old limit(100) by name never showed, only managers are returned, and the shape leaks nothing', function () {
    ss_moderators(110);
    $late = e16User(['name' => 'ياسمين المشرفة']);
    $late->givePermissionTo('trust-safety.manage-cases');
    $player = e16User(['name' => 'ياسمين اللاعبة']);

    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);
    $lw = Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful();

    $hit = ss_call($lw, 'searchAssignees', 'ياسمين');

    expect($hit['items'])->toHaveCount(1)->and($hit['items'][0]['id'])->toBe((string) $late->id)->and($hit['items'][0]['name'])->toBe('ياسمين المشرفة')
        ->and(array_keys($hit['items'][0]))->toBe(['id', 'name', 'parts', 'dist', 'retired', 'current', 'note'])
        ->and(json_encode($hit))->not->toContain($player->email)->not->toContain($late->email)->not->toContain((string) $player->name);

    $first = ss_call($lw, 'searchAssignees', '');
    $second = ss_call($lw, 'searchAssignees', '', $first['next']);

    expect($first['items'])->toHaveCount(10)->and($first['total'])->toBeGreaterThan(110)->and($first['next'])->toBe(10)
        ->and(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')))->toBe([]);
});

test('SS-27: duplicate moderator names are told apart, and only a supervisor may search', function () {
    $a = e16User(['name' => 'Same Name']);
    $b = e16User(['name' => 'Same Name']);
    $a->givePermissionTo('trust-safety.manage-cases');
    $b->givePermissionTo('trust-safety.manage-cases');

    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);
    $hit = ss_call(Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful(), 'searchAssignees', 'same name');

    expect($hit['items'])->toHaveCount(2)->and(collect($hit['items'])->pluck('dist')->flatten()->unique()->count())->toBe(2)
        ->and(collect($hit['items'])->pluck('dist')->flatten()->implode(' '))->toContain((string) $a->id)->toContain((string) $b->id);

    // مشرف حالات بلا صلاحية الإشراف الأعلى: لا بحث (كإجراء التعيين نفسه).
    $this->actingAs(e38_mod());
    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful()->call('searchAssignees', 'same')->assertForbidden();
});

test('SS-28: choosing a moderator does not assign anyone until «إعادة التعيين», then the right user is assigned; a non-manager id is refused', function () {
    $target = e16User(['name' => 'Target Moderator']);
    $target->givePermissionTo('trust-safety.manage-cases');
    $player = e16User(['name' => 'Plain Player']);

    [$case, $admin] = e38_puzzle_case();
    $this->actingAs($admin);
    $before = $case->fresh()->assigned_to;

    $lw = Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->assertSuccessful()->set('assigneeId', (string) $target->id);

    expect($case->fresh()->assigned_to)->toBe($before);

    $lw->call('assign');
    expect($case->fresh()->assigned_to)->toBe($target->id);

    // معرّف مستخدم عادي (يعبث به العميل): يُرفض ولا يتغيّر التعيين.
    Livewire::test(ModerationCasePage::class, ['record' => $case->public_id])->set('assigneeId', (string) $player->id)->call('assign');
    expect($case->fresh()->assigned_to)->toBe($target->id);
});

/** يزرع $count سلسلة بعناوين حشو (أحدث من أي سلسلة زُرعت قبلها). */
function ss_series(int $count, string $prefix = 'سلسلة حشو '): void
{
    $owner = DB::table('creator_profiles')->value('id') ?? (function () {
        $user = cin_user();

        return DB::table('creator_profiles')->insertGetId(['user_id' => $user->id, 'slug' => 'ss-owner-'.Str::lower(Str::random(5)), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
    })();

    $rows = [];

    foreach (range(1, $count) as $i) {
        $rows[] = ['public_id' => (string) Str::ulid(), 'creator_profile_id' => $owner, 'slug' => 'ss-'.Str::lower(Str::random(10)).$i, 'published_title' => $prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('creator_series')->insert($chunk);
    }
}

function ss_series_one(string $title): CreatorSeries
{
    ss_series(0);
    $owner = DB::table('creator_profiles')->value('id');
    $id = DB::table('creator_series')->insertGetId(['public_id' => (string) Str::ulid(), 'creator_profile_id' => $owner, 'slug' => 'ss-'.Str::lower(Str::random(10)), 'published_title' => $title, 'created_at' => now(), 'updated_at' => now()]);

    return CreatorSeries::query()->findOrFail($id);
}

test('SS-29: the series picker finds a series older than the newest 200, by title or by public id, and duplicates are told apart', function () {
    $old = ss_series_one('Zebra Series Old');
    $dupA = ss_series_one('Twin Series');
    $dupB = ss_series_one('Twin Series');
    ss_series(230);

    $this->actingAs(cin_admin());
    $lw = Livewire::test(CinematicStoriesPage::class)->assertSuccessful();

    $byTitle = ss_call($lw, 'searchSeries', 'zebra series');
    $byId = ss_call($lw, 'searchSeries', substr($old->public_id, 0, 12));
    $twins = ss_call($lw, 'searchSeries', 'twin series');
    $first = ss_call($lw, 'searchSeries', '');

    expect(array_column($byTitle['items'], 'id'))->toBe([(string) $old->id])
        ->and(array_column($byId['items'], 'id'))->toContain((string) $old->id)
        ->and($twins['items'])->toHaveCount(2)->and(collect($twins['items'])->pluck('dist')->flatten()->unique()->count())->toBe(2)
        ->and($first['items'])->toHaveCount(10)->and($first['total'])->toBeGreaterThan(230)->and($first['next'])->toBe(10)
        ->and(array_keys($byTitle['items'][0]))->toBe(['id', 'name', 'parts', 'dist', 'retired', 'current', 'note']);
});

test('SS-30: a premium story is created with the chosen series only after «إنشاء», and a viewer without manage permission cannot search', function () {
    $series = ss_series_one('Chosen Series');
    ss_series(40);
    $this->actingAs(cin_admin());
    $stories = CinematicStory::query()->count();

    $lw = Livewire::test(CinematicStoriesPage::class)->assertSuccessful()
        ->set('form.title', 'قصة مميزة من البحث')->set('form.access_mode', 'premium')->set('form.creator_series_id', (string) $series->id);

    expect(CinematicStory::query()->count())->toBe($stories);

    $lw->call('create');

    $story = CinematicStory::query()->where('title', 'قصة مميزة من البحث')->first();
    expect($story)->not->toBeNull()->and($story->creator_series_id)->toBe($series->id);

    $this->actingAs(ss_user(['cinematic-stories.view']));
    Livewire::test(CinematicStoriesPage::class)->assertSuccessful()->call('searchSeries', 'chosen')->assertForbidden();
});

test('SS-31: the page renders the shared component for the premium series and no longer embeds the series as <option> elements', function () {
    $series = ss_series_one('Embedded Check Series');
    ss_series(30);
    $this->actingAs(cin_admin());

    $html = Livewire::test(CinematicStoriesPage::class)->assertSuccessful()->set('showCreate', true)->set('form.access_mode', 'premium')->html();

    expect($html)->toContain('data-ss-root')->toContain('searchSeries')->not->toContain('<option value="'.$series->id.'"')->not->toContain('Embedded Check Series');
});
