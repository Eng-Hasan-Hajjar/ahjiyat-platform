<?php

require_once __DIR__.'/SocialTestHelpers.php';

use App\Models\Friendship;
use App\Models\User;
use App\Services\Social\BlockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ============================ الأمن ============================

test('31/32: every mutation route requires authentication - guests are sent to login and nothing changes', function () {
    $b = e16User();
    $mutations = [
        ['post', route('friends.requests.store', $b)], ['post', route('friends.requests.accept', $b)], ['post', route('friends.requests.decline', $b)],
        ['delete', route('friends.requests.cancel', $b)], ['delete', route('friends.remove', $b)],
        ['post', route('friends.blocks.store', $b)], ['delete', route('friends.blocks.destroy', $b)], ['patch', route('friends.settings')],
    ];

    foreach ($mutations as [$method, $url]) {
        $this->{$method}($url)->assertRedirect(route('login'));
    }

    foreach ([route('friends.index'), route('friends.search')] as $page) {
        $this->get($page)->assertRedirect(route('login'));
    }

    expect(Friendship::count())->toBe(0)->and(DB::table('user_blocks')->count())->toBe(0);
});

test('mutation routes also require a verified email', function () {
    $unverified = User::factory()->create(['email_verified_at' => null]);
    $b = e16User();

    $this->actingAs($unverified)->post(route('friends.requests.store', $b))->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->get(route('friends.index'))->assertRedirect(route('verification.notice'));
    expect(Friendship::count())->toBe(0);
});

test('no GET mutation: only the two read pages are GET; mutating URLs answer 405 to GET', function () {
    $a = e16User();
    $b = e16User();
    $friendRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'friends.'));

    $getRoutes = $friendRoutes->filter(fn ($r) => in_array('GET', $r->methods(), true))->map->getName()->sort()->values()->all();
    expect($getRoutes)->toBe(['friends.index', 'friends.search']);

    foreach (['/friends/requests/'.$b->public_id, '/friends/requests/'.$b->public_id.'/accept', '/friends/blocks/'.$b->public_id, '/friends/'.$b->public_id] as $url) {
        $this->actingAs($a)->get($url)->assertStatus(405);
    }
});

test('CSRF: every mutation route sits in the web group and every form carries a token', function () {
    foreach (['friends.requests.store', 'friends.requests.accept', 'friends.requests.decline', 'friends.requests.cancel', 'friends.remove', 'friends.blocks.store', 'friends.blocks.destroy', 'friends.settings'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('web');
    }

    [$a, $b] = [e16User(), e16User()];
    $html = $this->actingAs($a)->get(route('players.show', $b))->assertOk()->getContent();
    expect(substr_count($html, 'name="_token"'))->toBeGreaterThanOrEqual(2); // إضافة صديق + (نموذج الحظر + نموذج الخروج)
});

test('30: cross-user IDOR over HTTP - no user can accept, decline, cancel or remove a relation they are not part of', function () {
    [$a, $b, $c, $d] = [e16User(), e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($c, $d);   // طلب بين طرفين آخرين
    e16Befriend($a, $b);

    $this->actingAs($a)->post(route('friends.requests.accept', $c))->assertSessionHas('error'); // لا طلب بيني وبين C
    $this->actingAs($a)->post(route('friends.requests.accept', $d))->assertSessionHas('error');
    $this->actingAs($a)->post(route('friends.requests.decline', $d))->assertSessionHas('error');
    $this->actingAs($a)->delete(route('friends.requests.cancel', $d))->assertSessionHas('error');
    $this->actingAs($a)->delete(route('friends.remove', $c))->assertSessionHas('error');

    expect(Friendship::query()->forPair($c->id, $d->id)->sole()->status)->toBe('pending')->and(e16Rows($a, $b)->sole()->status)->toBe('accepted');
});

// ============================ الخصوصية والبحث ============================

test('33: search never reveals email, phone, wallet or any private field - only the display name and a link', function () {
    $viewer = e16User();
    e16User(['name' => 'Samir Findable', 'email' => 'secret.person@leak.test']);

    $html = $this->actingAs($viewer)->get(route('friends.search', ['q' => 'Findable']))->assertOk()->getContent();

    expect($html)->toContain('Samir Findable')
        ->and($html)->not->toContain('secret.person@leak.test')->and($html)->not->toContain('leak.test')
        ->and($html)->not->toContain('محفظة')->and(strtolower($html))->not->toContain('password');
});

test('search: minimum length, pagination, and exclusions (self, private profile, frozen, unverified, blocked either way)', function () {
    $viewer = e16User(['name' => 'Viewer Zed']);
    foreach (range(1, 30) as $i) {
        e16User(['name' => sprintf('Zed %02d', $i)]);
    }
    e16User(['name' => 'Zed Private', 'profile_visibility' => User::VISIBILITY_PRIVATE]);
    e16User(['name' => 'Zed Frozen', 'is_frozen' => true]);
    e16User(['name' => 'Zed Unverified', 'email_verified_at' => null]);
    $blockedByMe = e16User(['name' => 'Zed BlockedByMe']);
    $blockedMe = e16User(['name' => 'Zed BlockedMe']);
    app(BlockService::class)->block($viewer, $blockedByMe);
    app(BlockService::class)->block($blockedMe, $viewer);

    $this->actingAs($viewer)->get(route('friends.search', ['q' => 'z']))->assertOk()->assertSee('حرفان على الأقل', false)->assertDontSee('Zed 01');

    $page1 = $this->actingAs($viewer)->get(route('friends.search', ['q' => 'zed']))->assertOk();
    $names = $page1->viewData('results')->getCollection()->pluck('name');

    expect($names)->toHaveCount(12)->and($page1->viewData('results')->total())->toBe(30); // كل ما سوى المستبعَدين
    // النفس تُفحَص بمجموعة النتائج (اسمها يظهر بشريط التنقل)، وبقية المستبعَدين بالصفحة كلها.
    expect($page1->viewData('results')->getCollection()->pluck('name')->all())->not->toContain('Viewer Zed');
    foreach (['Zed Private', 'Zed Frozen', 'Zed Unverified', 'Zed BlockedByMe', 'Zed BlockedMe'] as $excluded) {
        $page1->assertDontSee($excluded);
    }

    $this->actingAs($viewer)->get(route('friends.search', ['q' => 'zed', 'page' => 3]))->assertOk()->assertSee('Zed 25');
});

test('search treats LIKE wildcards literally: % and _ match only themselves (data chosen so escaping changes the result)', function () {
    $viewer = e16User();
    e16User(['name' => 'Fifty100%']);
    e16User(['name' => 'Zero Zone 0']);     // يحوي 0 لكن ليس "0%"
    e16User(['name' => 'Under_score']);
    e16User(['name' => 'UnderXscore']);     // يطابق r_s لو كانت _ رمزًا بديلًا

    $names = fn (string $q) => $this->actingAs($viewer)->get(route('friends.search', ['q' => $q]))->viewData('results')->pluck('name')->all();

    expect($names('0%'))->toBe(['Fifty100%'])->and($names('r_s'))->toBe(['Under_score']);
});

test('34: a blocked user is not a target for interaction - hidden in search, refused on send, and no add button on the blocker profile', function () {
    [$a, $b] = [e16User(['name' => 'Alice Blocker']), e16User(['name' => 'Bob Blocked'])];
    app(BlockService::class)->block($a, $b);

    $this->actingAs($b)->get(route('friends.search', ['q' => 'Alice']))->assertDontSee('Alice Blocker');
    $this->actingAs($b)->post(route('friends.requests.store', $a))->assertSessionHas('error');

    $profile = $this->actingAs($b)->get(route('players.show', $a))->assertOk();
    $profile->assertDontSee('إضافة صديق')->assertDontSee('محظور'); // لا نكشف الحظر للمحظور

    expect(Friendship::count())->toBe(0);
});

// ============================ صفحة الأصدقاء ============================

test('friends page: sections, empty states, only my own relations, no private fields', function () {
    $me = e16User();

    $empty = $this->actingAs($me)->get(route('friends.index'))->assertOk();
    $empty->assertSee('لا توجد طلبات واردة.')->assertSee('لم ترسل أي طلب بعد.')->assertSee('لا أصدقاء بعد.')->assertSee('لم تحظر أحدًا.');

    [$friend, $incoming, $outgoing, $blocked, $stranger, $strangerFriend] = [
        e16User(['name' => 'Friend One', 'email' => 'friend.one@leak.test']), e16User(['name' => 'Incoming Two']), e16User(['name' => 'Outgoing Three']),
        e16User(['name' => 'Blocked Four']), e16User(['name' => 'Stranger Five']), e16User(['name' => 'StrangerFriend Six']),
    ];
    e16Befriend($me, $friend);
    e16Svc()->sendRequest($incoming, $me);
    e16Svc()->sendRequest($me, $outgoing);
    app(BlockService::class)->block($me, $blocked);
    e16Befriend($stranger, $strangerFriend);

    $page = $this->actingAs($me)->get(route('friends.index'))->assertOk();
    $page->assertSee('Friend One')->assertSee('Incoming Two')->assertSee('Outgoing Three')->assertSee('Blocked Four')
        ->assertSee('قبول الطلب')->assertSee('إلغاء الطلب')->assertSee('إزالة الصديق')->assertSee('إلغاء الحظر')
        ->assertDontSee('Stranger Five')->assertDontSee('StrangerFriend Six')->assertDontSee('leak.test');
});

test('friends list is paginated (20 per page) and private to its owner', function () {
    $me = e16User();
    foreach (range(1, 25) as $i) {
        e16Befriend($me, e16User(['name' => sprintf('Pal %02d', $i)]));
    }

    $p1 = $this->actingAs($me)->get(route('friends.index'))->assertOk();
    $p2 = $this->actingAs($me)->get(route('friends.index', ['page' => 2]))->assertOk();

    expect($p1->viewData('friends')->count())->toBe(20)->and($p2->viewData('friends')->count())->toBe(5);
    $p2->assertSee('Pal 25');

    // قائمة أصدقاء المستخدم لا تظهر على ملفه العام لأي زائر: عدد فقط.
    $viewer = e16User();
    $profile = $this->actingAs($viewer)->get(route('players.show', $me))->assertOk();
    $profile->assertSee('الأصدقاء 25')->assertDontSee('Pal 01');
});

test('the navbar shows the friends link to verified users only', function () {
    $this->get(route('home'))->assertDontSee(route('friends.index'), false);
    $this->actingAs(e16User())->get(route('home'))->assertSee(route('friends.index'), false);
});

// ============================ أزرار الملف العام حسب الحالة ============================

test('profile actions follow the relation state and never show actions the user cannot perform', function () {
    [$me, $none, $out, $in, $friend, $blocked, $blockedMe] = [e16User(), e16User(), e16User(), e16User(), e16User(), e16User(), e16User()];
    e16Svc()->sendRequest($me, $out);
    e16Svc()->sendRequest($in, $me);
    e16Befriend($me, $friend);
    app(BlockService::class)->block($me, $blocked);
    app(BlockService::class)->block($blockedMe, $me);

    $see = fn (User $p) => $this->actingAs($me)->get(route('players.show', $p))->assertOk();

    $see($none)->assertSee('إضافة صديق')->assertSee('تأكيد الحظر');
    $see($out)->assertSee('تم إرسال الطلب')->assertSee('إلغاء الطلب')->assertDontSee('إضافة صديق');
    $see($in)->assertSee('قبول الطلب')->assertSee('رفض')->assertDontSee('إضافة صديق');
    $see($friend)->assertSee('أصدقاء')->assertSee('إزالة الصديق')->assertDontSee('إضافة صديق');
    $see($blocked)->assertSee('محظور')->assertSee('إلغاء الحظر')->assertDontSee('إضافة صديق');
    $see($blockedMe)->assertDontSee('إضافة صديق')->assertDontSee('محظور');

    // صاحب الملف نفسه: لا إجراءات. زائر غير مسجَّل: لا إجراءات.
    $this->actingAs($me)->get(route('players.show', $me))->assertOk()->assertDontSee('إضافة صديق')->assertDontSee('تأكيد الحظر');
    auth()->logout();
    $this->get(route('players.show', $none))->assertOk()->assertDontSee('إضافة صديق');
});

test('the block control asks for confirmation (Alpine panel) and explains the effect before submitting', function () {
    [$a, $b] = [e16User(), e16User(['name' => 'Target Name'])];

    $this->actingAs($a)->get(route('players.show', $b))->assertOk()
        ->assertSee('x-data="{ confirming: false }"', false)->assertSee('هل تريد حظر', false)->assertSee('تأكيد الحظر')->assertSee('تراجع');
});

// ============================ تدقيق ثابت: خصوصية وأمن ونظافة ============================

test('static audit: no debug leftovers, no raw HTML, no private fields and no request-supplied relation identifiers in the social code', function () {
    $files = array_merge(
        glob(app_path('Services/Social/*.php')), glob(app_path('Http/Controllers/Friend*.php')), [app_path('Http/Controllers/PlayerSearchController.php')],
        glob(app_path('Listeners/SendFriend*.php')), glob(app_path('Events/Friend*.php')), glob(app_path('Models/Friendship.php')), glob(app_path('Models/UserBlock.php')),
        glob(resource_path('views/friends/*.blade.php')), [resource_path('views/components/friend-row.blade.php')],
    );
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = file_get_contents($file);
        $stripped = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', $code);

        foreach (['dd(', 'dump(', 'TODO', 'FIXME', 'console.log', '{!!', 'var_dump'] as $bad) {
            expect(str_contains($stripped, $bad))->toBeFalse(basename($file)." must not contain {$bad}");
        }

        // لا بريد/هاتف/محفظة/كلمة مرور بأي واجهة أو خدمة اجتماعية (التعليقات مستثناة).
        // (عمود email_verified_at مشروع: استبعاد غير الموثَّقين من البحث، وليس عنوان البريد.)
        foreach (['email', 'phone', 'wallet', 'password', 'remember_token'] as $private) {
            expect(preg_match('/'.$private.'(?!_verified_at)/i', $stripped))->toBe(0, basename($file)." must not touch {$private}");
        }

        // لا معرّف علاقة/مستخدم قادم من الطلب: الطرف الحالي دائمًا $request->user().
        expect(preg_match('/\$request->(input|get|post|query|only|all)\(\s*[\'"]?(user_id|requester_id|addressee_id|blocker_id|blocked_id|id)[\'"]?/', $stripped))->toBe(0, basename($file));
    }

    $fillable = (new User)->getFillable();
    expect($fillable)->not->toContain('friend_requests_enabled')->and((new Friendship)->getFillable())->not->toContain('pair_key');
});
