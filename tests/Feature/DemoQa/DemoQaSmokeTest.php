<?php

require_once __DIR__.'/DemoQaFacts.php';

use App\Filament\Pages\AnalyticsCenter;
use App\Filament\Pages\OperationsCenter;
use App\Filament\Resources\AchievementResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CompetitiveEventResource;
use App\Filament\Resources\CurrencyTransactionResource;
use App\Filament\Resources\FraudFlagResource;
use App\Filament\Resources\PuzzleResource;
use App\Filament\Resources\QuestDefinitionResource;
use App\Filament\Resources\SeasonResource;
use App\Filament\Resources\StoreItemResource;
use App\Filament\Resources\StorePurchaseResource;
use App\Filament\Resources\TeamChallengeResource;
use App\Filament\Resources\TeamChampionshipResource;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\UserResource;
use App\Models\Campaign;
use App\Models\CompetitiveEvent;
use App\Models\FriendChallenge;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamChallenge;
use App\Models\TeamChampionship;
use App\Models\User;
use Database\Seeders\DemoQaSeeder;
use Filament\Facades\Filament;

/**
 * اختبار دخان بقاعدة حيّة (يبذر بنفسه): تسجيل دخول حقيقي بكلمة المرور المشتركة ثم كل صفحات المستخدم الرئيسية والديناميكية (لا 500، ومحتوى السيناريوهات ظاهر)،
 * ثم شخصيات أخرى (الخاص، المدعو، المحظور، المجمَّد)، ثم لوحة الإدارة والتحليلات.
 */
function demoSmoke($test, string $url, int $status = 200): \Illuminate\Testing\TestResponse
{
    $response = $test->get($url);
    expect($response->getStatusCode())->toBe($status, "GET {$url} answered ".$response->getStatusCode());

    return $response;
}

test('smoke: a real login with the shared password works, and every main and dynamic user page answers without a server error and shows the seeded scenarios', function () {
    $this->seed(DemoQaSeeder::class);
    $yousef = demoQaUser('yousef');

    $this->post(route('login'), ['email' => 'yousef@ahjiyat.test', 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($yousef);
    $this->post(route('login'), ['email' => 'yousef@ahjiyat.test', 'password' => 'wrong-password']);        // الخاطئة لا تكسر الجلسة
    $this->assertAuthenticatedAs($yousef);

    foreach (['home', 'friends.index', 'friends.challenges.index', 'teams.index', 'teams.challenges.index', 'teams.invitations', 'teams.leaderboard', 'competitions.index', 'competitions.hall-of-fame',
        'team-championships.index', 'notifications.index', 'notifications.preferences', 'wallet.index', 'store.index', 'inventory.index', 'profile.edit', 'profile.customize', 'quests.show', 'campaigns.index',
        'seasons.index', 'leaderboard.index', 'puzzles.index'] as $name) {
        demoSmoke($this, route($name));
    }

    // محتوى السيناريوهات ظاهر.
    demoSmoke($this, route('friends.index'))->assertSee('سارة القيسي')->assertSee('ريم الشامي');
    demoSmoke($this, route('team-championships.index'))->assertSee('كأس الخريف')->assertSee('كأس الشتاء');
    demoSmoke($this, route('competitions.hall-of-fame'))->assertSee('أبطال بطولات الفرق')->assertSee('فرسان الشام');
    demoSmoke($this, route('store.index'))->assertSee('إطار الذهب');
    demoSmoke($this, route('teams.challenges.index'))->assertSee('صقور المعرفة');
    demoSmoke($this, route('notifications.index'))->assertSee('ريم الشامي');
    $this->get(route('teams.mine'))->assertRedirect();

    // صفحات ديناميكية: الملفات، الفرق، الإدارة، التحدّيات، البطولات، المنافسات، الحملة والموسم.
    demoSmoke($this, route('players.show', $yousef))->assertSee('يوسف الحمدان');
    demoSmoke($this, route('players.show', demoQaUser('sara')))->assertSee('سارة القيسي');
    demoSmoke($this, route('players.competitive', $yousef));

    foreach (Team::query()->where('visibility', 'public')->get() as $team) {
        demoSmoke($this, route('teams.show', $team))->assertSee('مجد الفريق');
    }

    $knights = Team::query()->where('name', 'فرسان الشام')->firstOrFail();
    demoSmoke($this, route('teams.show', $knights))->assertSee('بطل «بطولة الفرق: كأس الخريف»')->assertSee('ضد صقور المعرفة');
    demoSmoke($this, route('teams.manage', $knights))->assertSee('خالد النجار');

    foreach (TeamChallenge::query()->get() as $challenge) {
        demoSmoke($this, route('teams.challenges.show', $challenge));
    }

    $pending = TeamChallenge::query()->where('status', 'pending')->firstOrFail();
    $active = TeamChallenge::query()->where('status', 'accepted')->firstOrFail();
    demoSmoke($this, route('teams.challenges.show', $pending))->assertSee('قبول وقفل الروستر');
    demoSmoke($this, route('teams.challenges.show', $active))->assertSee('ابدأ المحاولة');

    foreach (TeamChampionship::query()->get() as $championship) {
        demoSmoke($this, route('team-championships.show', $championship))->assertSee($championship->title);
    }

    foreach (CompetitiveEvent::query()->where('status', '!=', 'draft')->get() as $event) {
        $this->get(route('competitions.show', $event))->assertSuccessful();
    }

    foreach (FriendChallenge::query()->get() as $friendChallenge) {
        demoSmoke($this, route('friends.challenges.show', $friendChallenge));
    }

    demoSmoke($this, route('campaigns.show', Campaign::query()->where('slug', 'aseel-season-01')->firstOrFail()));
    demoSmoke($this, route('seasons.show', Season::query()->firstOrFail()));
});

test('smoke: the other personas see their own scenarios - the private team owner, the invited new player, the member of a pending join request and a frozen user is stopped without a server error', function () {
    $this->seed(DemoQaSeeder::class);
    $stars = Team::query()->where('name', 'نجوم الأحجيات')->firstOrFail();

    $this->actingAs(demoQaUser('noureddine'));
    demoSmoke($this, route('teams.show', $stars))->assertSee('نجوم الأحجيات');
    demoSmoke($this, route('teams.challenges.index'))->assertSee('فرسان الشام');

    $this->actingAs(demoQaUser('kenan'));
    demoSmoke($this, route('teams.invitations'))->assertSee('فرسان الشام');
    demoSmoke($this, route('teams.index'));
    demoSmoke($this, route('campaigns.index'));

    foreach (['sara', 'reem', 'omar', 'layan', 'khaled', 'dana', 'lama', 'firas', 'jana'] as $key) {
        $this->actingAs(demoQaUser($key));
        demoSmoke($this, route('friends.index'));
        demoSmoke($this, route('notifications.index'));
        demoSmoke($this, route('teams.index'));
    }

    // الفريق الخاص: صفحته مفتوحة لكن قائمة أعضائه مخفية عن غير الأعضاء (تصميم E19)، وتظهر لأعضائه.
    $this->actingAs(demoQaUser('lama'));
    demoSmoke($this, route('teams.show', $stars))->assertDontSee('إيمان غانم')->assertDontSee('عدنان زعبي');
    $this->actingAs(demoQaUser('eman'));
    demoSmoke($this, route('teams.show', $stars))->assertSee('عدنان زعبي');

    // المجمَّد لا يصل لأي 500.
    $this->actingAs(demoQaUser('tarek'));
    expect($this->get(route('friends.index'))->getStatusCode())->toBeLessThan(500);
});

test('smoke: the admin panel, the analytics center and the operations center open with the seeded data and no server error', function () {
    $this->seed(DemoQaSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::query()->where('email', 'admin@ahjiyat.app')->firstOrFail());

    foreach ([TeamResource::class, TeamChallengeResource::class, TeamChampionshipResource::class, CompetitiveEventResource::class, UserResource::class, PuzzleResource::class, StoreItemResource::class, StorePurchaseResource::class,
        FraudFlagResource::class, AchievementResource::class, QuestDefinitionResource::class, CurrencyTransactionResource::class, CampaignResource::class, SeasonResource::class] as $resource) {
        demoSmoke($this, $resource::getUrl());
    }

    demoSmoke($this, AnalyticsCenter::getUrl());
    demoSmoke($this, OperationsCenter::getUrl());
    demoSmoke($this, TeamChampionshipResource::getUrl('edit', ['record' => TeamChampionship::query()->where('slug', 'demo-championship-spring-2')->firstOrFail()]));
    demoSmoke($this, TeamChallengeResource::getUrl('view', ['record' => TeamChallenge::query()->firstOrFail()]));
});
