<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignStepController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\ProfileCustomizationController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PuzzleController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\SeasonController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/puzzles', [PuzzleController::class, 'index'])->name('puzzles.index');
Route::get('/puzzles/category/{category:slug}', [PuzzleController::class, 'index'])->name('puzzles.category');
Route::get('/puzzles/{puzzle}', [PuzzleController::class, 'show'])->name('puzzles.show');

Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

Route::get('/store', [StoreController::class, 'index'])->name('store.index');
Route::get('/store/items/{item:slug}', [StoreController::class, 'show'])->name('store.items.show');

Route::get('/challenges', [ChallengeController::class, 'index'])->name('challenges.index');

// E17: المنافسات العامة (قراءة فقط) - قائمة وصفحة حدث مع الترتيب
Route::get('/competitions', [\App\Http\Controllers\CompetitionController::class, 'index'])->name('competitions.index');
// E19: الفرق. الصفحات الحرفية (create/mine/invitations/leaderboard) تسبق {team:slug} حتى لا يلتقطها المسار الديناميكي (والمعرّفات محجوزة بـconfig/teams.php).
Route::get('/teams', [\App\Http\Controllers\TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/leaderboard', [\App\Http\Controllers\TeamController::class, 'leaderboard'])->name('teams.leaderboard');
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/create', [\App\Http\Controllers\TeamController::class, 'create'])->name('teams.create');
    Route::get('/teams/mine', [\App\Http\Controllers\TeamController::class, 'mine'])->name('teams.mine');
    Route::get('/teams/invitations', [\App\Http\Controllers\TeamMembershipController::class, 'invitations'])->name('teams.invitations');
});
// E20: تحدّيات الفرق (حرفية قبل {team:slug}) وبطولات الفرق. الصفحات الحرفية بين الفرق: challenges محجوز كمعرّف.
Route::middleware(['auth', 'verified', 'account.active'])->group(function () {
    Route::get('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'index'])->name('teams.challenges.index');
    Route::get('/teams/challenges/create', [\App\Http\Controllers\TeamChallengeController::class, 'create'])->name('teams.challenges.create');
});
Route::get('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'show'])->name('teams.challenges.show');
Route::get('/team-championships', [\App\Http\Controllers\TeamChampionshipController::class, 'index'])->name('team-championships.index');
Route::get('/team-championships/{championship:slug}', [\App\Http\Controllers\TeamChampionshipController::class, 'show'])->name('team-championships.show');
Route::get('/teams/{team:slug}', [\App\Http\Controllers\TeamController::class, 'show'])->name('teams.show');

// E18: قاعة الأمجاد (حرفية قبل {event:slug} حتى لا يلتقطها المسار الديناميكي)
Route::get('/competitions/hall-of-fame', [\App\Http\Controllers\CompetitionHallOfFameController::class, 'index'])->name('competitions.hall-of-fame');
Route::get('/competitions/{event:slug}', [\App\Http\Controllers\CompetitionController::class, 'show'])->name('competitions.show');
Route::get('/challenges/{challenge}', [ChallengeController::class, 'show'])->name('challenges.show');

Route::get('/terms', [PageController::class, 'terms'])->name('pages.terms');
Route::get('/privacy', [PageController::class, 'privacy'])->name('pages.privacy');

Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
Route::get('/campaigns/{campaign:slug}', [CampaignController::class, 'show'])->name('campaigns.show');

Route::get('/seasons', [SeasonController::class, 'index'])->name('seasons.index');
Route::get('/seasons/{season:slug}', [SeasonController::class, 'show'])->name('seasons.show');

Route::get('/players/{user:public_id}', [PlayerProfileController::class, 'show'])->name('players.show');
Route::get('/players/{user:public_id}/competitive', [\App\Http\Controllers\PlayerCompetitiveController::class, 'show'])->name('players.competitive');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:registration');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:email-verification'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:email-verification')->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::middleware(['verified', 'account.active'])->group(function () {
        // E19: إجراءات الفرق (POST/PATCH/DELETE فقط عدا صفحة الإدارة). التفويض من الدور الفعلي بالقاعدة، لا من الطلب.
        // {user:public_id} ليس ابنًا للفريق بعلاقة (Team::users) فيُعطَّل الربط المحصور هناك والخدمة تتحقق من العضوية؛ أما {invitation}/{joinRequest} فمحصوران بعلاقتي الفريق: 404 تلقائي لمورد فريق آخر.
        Route::post('/teams/challenges', [\App\Http\Controllers\TeamChallengeController::class, 'store'])->middleware('throttle:team-challenge-create')->name('teams.challenges.store');
        Route::post('/teams/challenges/{challenge:public_id}/accept', [\App\Http\Controllers\TeamChallengeController::class, 'accept'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.accept');
        Route::post('/teams/challenges/{challenge:public_id}/decline', [\App\Http\Controllers\TeamChallengeController::class, 'decline'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.decline');
        Route::delete('/teams/challenges/{challenge:public_id}', [\App\Http\Controllers\TeamChallengeController::class, 'cancel'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.cancel');
        Route::put('/teams/challenges/{challenge:public_id}/roster', [\App\Http\Controllers\TeamChallengeController::class, 'roster'])->middleware('throttle:team-challenge-actions')->name('teams.challenges.roster');
        Route::post('/teams/challenges/{challenge:public_id}/start', [\App\Http\Controllers\TeamChallengePlayController::class, 'start'])->middleware('throttle:competitive-play')->name('teams.challenges.start');
        Route::post('/teams/challenges/{challenge:public_id}/submit', [\App\Http\Controllers\TeamChallengePlayController::class, 'submit'])->middleware('throttle:competitive-play')->name('teams.challenges.submit');
        Route::post('/teams', [\App\Http\Controllers\TeamController::class, 'store'])->middleware('throttle:team-create')->name('teams.store');
        Route::post('/teams/invitations/{invitation:public_id}/accept', [\App\Http\Controllers\TeamMembershipController::class, 'accept'])->middleware('throttle:team-actions')->name('teams.invitations.accept');
        Route::post('/teams/invitations/{invitation:public_id}/decline', [\App\Http\Controllers\TeamMembershipController::class, 'decline'])->middleware('throttle:team-actions')->name('teams.invitations.decline');
        Route::post('/teams/{team:slug}/join', [\App\Http\Controllers\TeamMembershipController::class, 'join'])->middleware('throttle:team-join')->name('teams.join');
        Route::post('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'requestJoin'])->middleware('throttle:team-join')->name('teams.requests.store');
        Route::delete('/teams/{team:slug}/requests', [\App\Http\Controllers\TeamMembershipController::class, 'cancelRequest'])->middleware('throttle:team-actions')->name('teams.requests.cancel');
        Route::delete('/teams/{team:slug}/membership', [\App\Http\Controllers\TeamMembershipController::class, 'leave'])->middleware('throttle:team-actions')->name('teams.leave');
        Route::get('/teams/{team:slug}/manage', [\App\Http\Controllers\TeamManagementController::class, 'manage'])->name('teams.manage');
        Route::patch('/teams/{team:slug}', [\App\Http\Controllers\TeamManagementController::class, 'update'])->middleware('throttle:team-actions')->name('teams.update');
        Route::post('/teams/{team:slug}/deactivate', [\App\Http\Controllers\TeamManagementController::class, 'deactivate'])->middleware('throttle:team-actions')->name('teams.deactivate');
        Route::post('/teams/{team:slug}/invitations/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'invite'])->middleware('throttle:team-invite')->withoutScopedBindings()->name('teams.invitations.store');
        Route::delete('/teams/{team:slug}/invitations/{invitation:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'cancelInvitation'])->middleware('throttle:team-actions')->name('teams.invitations.cancel');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/accept', [\App\Http\Controllers\TeamManagementController::class, 'acceptRequest'])->middleware('throttle:team-actions')->name('teams.requests.accept');
        Route::post('/teams/{team:slug}/requests/{joinRequest:public_id}/decline', [\App\Http\Controllers\TeamManagementController::class, 'declineRequest'])->middleware('throttle:team-actions')->name('teams.requests.decline');
        Route::delete('/teams/{team:slug}/members/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'removeMember'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.remove');
        Route::patch('/teams/{team:slug}/members/{user:public_id}/role', [\App\Http\Controllers\TeamManagementController::class, 'changeRole'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.members.role');
        Route::post('/teams/{team:slug}/transfer/{user:public_id}', [\App\Http\Controllers\TeamManagementController::class, 'transfer'])->middleware('throttle:team-actions')->withoutScopedBindings()->name('teams.transfer');

        Route::post('/puzzles/{puzzle}/attempt', [PuzzleController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('puzzles.attempt');
        Route::post('/puzzles/{puzzle}/hint', [PuzzleController::class, 'hint'])->name('puzzles.hint');

        Route::post('/puzzles/{puzzle}/session', [GameSessionController::class, 'store'])
            ->middleware('throttle:game-session-start')->name('game-sessions.start');
        Route::post('/game-sessions/{session}/reveal', [GameSessionController::class, 'reveal'])
            ->middleware('throttle:game-session-reveal')->name('game-sessions.reveal');

        Route::post('/challenges/{challenge}/join', [ChallengeController::class, 'join'])->name('challenges.join');

        Route::get('/campaigns/{campaign:slug}/steps/{step}', [CampaignStepController::class, 'show'])
            ->name('campaigns.steps.show');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/complete', [CampaignStepController::class, 'complete'])
            ->name('campaigns.steps.complete');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/reflect', [CampaignStepController::class, 'reflect'])
            ->name('campaigns.steps.reflect');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/attempt', [CampaignStepController::class, 'attempt'])
            ->middleware('throttle:puzzle-attempt')->name('campaigns.steps.attempt');
        Route::post('/campaigns/{campaign:slug}/steps/{step}/session', [CampaignStepController::class, 'startSession'])
            ->middleware('throttle:game-session-start')->name('campaigns.steps.session');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

        Route::get('/redemption', [RedemptionController::class, 'index'])->name('redemption.index');
        Route::get('/redemption/create', [RedemptionController::class, 'create'])->name('redemption.create');
        Route::post('/redemption', [RedemptionController::class, 'store'])
            ->middleware('throttle:redemption')->name('redemption.store');

        Route::post('/store/items/{item}/purchase', [StoreController::class, 'purchase'])
            ->middleware('throttle:store-purchase')->name('store.items.purchase');

        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        Route::get('/profile/customize', [ProfileCustomizationController::class, 'edit'])->name('profile.customize');
        Route::post('/profile/cosmetics/{item}/equip', [ProfileCustomizationController::class, 'equip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.equip');
        Route::delete('/profile/cosmetics/{slot}/unequip', [ProfileCustomizationController::class, 'unequip'])
            ->middleware('throttle:cosmetic-equip')->name('profile.cosmetics.unequip');
        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
            ->name('profile.visibility.update');

        Route::patch('/profile/visibility', [ProfileCustomizationController::class, 'updateVisibility'])
            ->name('profile.visibility.update');

        // E12: صفحة التقدُّم - قراءة فقط، لا Mutation عبر أي مسار هنا.
        Route::get('/progress', [\App\Http\Controllers\PlayerProgressionController::class, 'show'])->name('progress.show');
        Route::get('/quests', [\App\Http\Controllers\PlayerQuestsController::class, 'show'])->name('quests.show');

        // E15: مركز الإشعارات. GET = عرض فقط. كل تعديل POST/PUT/DELETE بـCSRF وبملكية صارمة داخل المتحكّم.
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences'])->name('notifications.preferences');
        Route::put('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{id}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
        Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->whereUuid('id')->name('notifications.destroy');

        // E16: الأصدقاء والحظر. GET = عرض فقط. كل تعديل POST/PATCH/DELETE بـCSRF؛ الطرف الآخر بـpublic_id من المسار، والطرف الحالي
        // دائمًا المستخدم المصادَق (لا user_id من النموذج). إرسال الطلبات بتحديد معدّل ثنائي.
        Route::prefix('friends')->name('friends.')->group(function () {
            Route::get('/', [\App\Http\Controllers\FriendsController::class, 'index'])->name('index');
            Route::get('/search', [\App\Http\Controllers\PlayerSearchController::class, 'index'])->middleware('throttle:player-search')->name('search');
            Route::patch('/settings', [\App\Http\Controllers\FriendsController::class, 'updateSettings'])->middleware('throttle:friend-actions')->name('settings');

            Route::post('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'store'])->middleware('throttle:friend-requests')->name('requests.store');
            Route::post('/requests/{user:public_id}/accept', [\App\Http\Controllers\FriendRequestController::class, 'accept'])->middleware('throttle:friend-actions')->name('requests.accept');
            Route::post('/requests/{user:public_id}/decline', [\App\Http\Controllers\FriendRequestController::class, 'decline'])->middleware('throttle:friend-actions')->name('requests.decline');
            Route::delete('/requests/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'cancel'])->middleware('throttle:friend-actions')->name('requests.cancel');
            Route::delete('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'destroy'])->middleware('throttle:friend-actions')->name('blocks.destroy');
            Route::post('/blocks/{user:public_id}', [\App\Http\Controllers\FriendBlockController::class, 'store'])->middleware('throttle:friend-actions')->name('blocks.store');
            Route::delete('/{user:public_id}', [\App\Http\Controllers\FriendRequestController::class, 'removeFriend'])->middleware('throttle:friend-actions')->name('remove');
        });

        // E17-A: تحدّيات الأصدقاء. الخصم بـpublic_id من المسار والتحدي بـpublic_id (ULID)، والمصادَق هو الطرف دائمًا. GET = عرض فقط.
        Route::prefix('friends/challenges')->name('friends.challenges.')->group(function () {
            $c = \App\Http\Controllers\FriendChallengeController::class;

            Route::get('/', [$c, 'index'])->name('index');
            Route::get('/new/{user:public_id}', [$c, 'create'])->name('create');
            Route::post('/new/{user:public_id}', [$c, 'store'])->middleware('throttle:friend-challenges')->name('store');
            Route::get('/{challenge:public_id}', [$c, 'show'])->name('show');
            Route::post('/{challenge:public_id}/accept', [$c, 'accept'])->middleware('throttle:friend-actions')->name('accept');
            Route::post('/{challenge:public_id}/decline', [$c, 'decline'])->middleware('throttle:friend-actions')->name('decline');
            Route::delete('/{challenge:public_id}', [$c, 'cancel'])->middleware('throttle:friend-actions')->name('cancel');
            Route::post('/{challenge:public_id}/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::post('/{challenge:public_id}/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });

        // E17-B/C: تسجيل ولعب المنافسات. الحدث بـslug من المسار؛ لا score/winner/rank من العميل.
        Route::prefix('competitions/{event:slug}')->name('competitions.')->group(function () {
            $c = \App\Http\Controllers\CompetitionPlayController::class;

            Route::post('/register', [$c, 'register'])->middleware('throttle:competitive-register')->name('register');
            Route::delete('/register', [$c, 'leave'])->middleware('throttle:competitive-register')->name('leave');
            Route::post('/start', [$c, 'start'])->middleware('throttle:competitive-play')->name('start');
            Route::get('/play', [$c, 'play'])->name('play');
            Route::post('/submit', [$c, 'submit'])->middleware('throttle:competitive-play')->name('submit');
        });


    });





});
// E14 (بند 605): تحويل نقر إعلان - عام، معرِّف Creative فقط، لا رابط من الاستعلام.
Route::get('/ads/click/{creative}', \App\Http\Controllers\AdClickController::class)
    ->middleware('throttle:ad-click')->name('ads.click');
