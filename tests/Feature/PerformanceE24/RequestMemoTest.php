<?php

use App\Support\RequestMemo;
use Illuminate\Support\Facades\Route;

/**
 * E24 - RequestMemo: ذاكرة طلب GET واحد فقط. لا تسرّب بين الطلبات ولا بين المستخدمين، ولا تعمل على الكتابة ولا خارج الطلب.
 */
beforeEach(function () {
    Route::middleware('web')->get('/_e24/memo', function () {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'v'.$calls;
        };

        return response()->json([RequestMemo::remember('k', $resolve), RequestMemo::remember('k', $resolve), $calls]);
    });

    Route::middleware('web')->post('/_e24/memo', function () {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'v'.$calls;
        };

        return response()->json([RequestMemo::remember('k', $resolve), RequestMemo::remember('k', $resolve), $calls]);
    })->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

    Route::middleware('web')->get('/_e24/memo-user', function () {
        $user = auth()->user();

        return response()->json(['who' => RequestMemo::remember('who.'.$user->getKey(), fn () => $user->name)]);
    });

    Route::middleware('web')->get('/_e24/memo-forget', function () {
        $n = 0;
        RequestMemo::remember('grp.a', function () use (&$n) { return ++$n; });
        RequestMemo::remember('grp.b', function () use (&$n) { return ++$n; });
        RequestMemo::forget('grp.');
        RequestMemo::remember('grp.a', function () use (&$n) { return ++$n; });

        return response()->json(['n' => $n]);
    });
});

test('E24: inside a GET request the resolver runs once', function () {
    $this->getJson('/_e24/memo')->assertOk()->assertExactJson(['v1', 'v1', 1]);
});

test('E24: the next request starts with an empty memo (nothing leaks between requests)', function () {
    $this->getJson('/_e24/memo')->assertExactJson(['v1', 'v1', 1]);
    $this->getJson('/_e24/memo')->assertExactJson(['v1', 'v1', 1]);
});

test('E24: a write request (POST) is never memoized - every read hits the source', function () {
    $this->postJson('/_e24/memo')->assertOk()->assertExactJson(['v1', 'v2', 2]);
});

test('E24: outside an HTTP route (services called directly, console, queue) nothing is stored', function () {
    $n = 0;
    $a = RequestMemo::remember('x', function () use (&$n) { return ++$n; });
    $b = RequestMemo::remember('x', function () use (&$n) { return ++$n; });

    expect($a)->toBe(1)->and($b)->toBe(2);
});

test('E24: forget(prefix) drops only the matching keys', function () {
    $this->getJson('/_e24/memo-forget')->assertOk()->assertExactJson(['n' => 3]);
});

test('E24: user-scoped keys do not leak between two users served one after the other', function () {
    $a = \App\Models\User::factory()->create(['name' => 'أولى']);
    $b = \App\Models\User::factory()->create(['name' => 'ثانية']);

    $this->actingAs($a)->getJson('/_e24/memo-user')->assertExactJson(['who' => 'أولى']);
    $this->actingAs($b)->getJson('/_e24/memo-user')->assertExactJson(['who' => 'ثانية']);
});

test('E24: after a GET has been served, services called directly are NOT memoized from that finished request (stale-request guard)', function () {
    $this->getJson('/_e24/memo')->assertExactJson(['v1', 'v1', 1]);

    // app('request') ما زال مرتبطًا بآخر GET ذي مسار، لكن نافذة الذاكرة أُغلقت بانتهاء المعالجة.
    $n = 0;
    $a = RequestMemo::remember('x', function () use (&$n) { return ++$n; });
    $b = RequestMemo::remember('x', function () use (&$n) { return ++$n; });

    expect($a)->toBe(1)->and($b)->toBe(2);
});

test('E24: team membership read during a GET is not served stale to a later direct service call', function () {
    require_once __DIR__.'/../Teams/TeamTestHelpers.php';

    $team = e19Team();
    $member = e19Member($team);
    $outsider = e16User();

    $this->actingAs($member)->get(route('teams.show', $team))->assertOk();
    // بعد الطلب: انضمام جديد يجب أن يُرى فورًا بقراءة الخدمة مباشرةً (كان يعيد عضوية قديمة من ذاكرة الطلب المنتهي).
    expect(app(\App\Services\Teams\TeamMembershipService::class)->membershipOf($outsider))->toBeNull();

    e19Member($team, $outsider);
    expect(app(\App\Services\Teams\TeamMembershipService::class)->membershipOf($outsider))->not->toBeNull();
});

test('E24: a nested request inside a request does not close the outer memo window', function () {
    $outer = \Illuminate\Http\Request::create('/outer', 'GET');
    $inner = \Illuminate\Http\Request::create('/inner', 'GET');

    RequestMemo::begin($outer);
    RequestMemo::begin($inner);
    RequestMemo::end($inner);
    RequestMemo::end($outer);

    // لا شيء نشط الآن (لا مسار على الطلبين أصلًا) ولا تسرّب حالة بين الاختبارات.
    $n = 0;
    RequestMemo::remember('y', function () use (&$n) { return ++$n; });
    RequestMemo::remember('y', function () use (&$n) { return ++$n; });
    expect($n)->toBe(2);
});
