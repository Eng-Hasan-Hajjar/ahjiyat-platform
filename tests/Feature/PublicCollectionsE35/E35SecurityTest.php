<?php

use App\Models\UserCollection;
use App\Models\UserCollectionItem;
use App\Models\UserCollectionReport;
use App\Models\UserCollectionSave;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/_support.php';

/*
| E35 AM11 (88-97): الأمان. إدخال ضار يُرفض عند الإدخال ويُهرَّب عند العرض حتى لو أُدخل خامًا بالقاعدة؛ الحماية من الإسناد الجماعي؛ CSRF على كل تغيير؛ لا تغيير بـGET؛
| IDOR للمجموعات الخاصة والبلاغات؛ مصفوفة الصلاحيات (بالصلاحية لا بالدور).
*/

beforeEach(fn () => e35_seed());

test('E35-088: XSS in the collection title is rejected on input and escaped on every public surface even if inserted raw into the database', function () {
    $owner = e16User();

    $this->actingAs($owner)->post(route('collections.store'), ['title' => '<script>alert("xss")</script>'])->assertSessionHasErrors('title');
    $c = e35_collection($owner, e35_works(3), 'public', 'عنوان سليم');
    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => '<b onmouseover=alert(1)>x</b>'])->assertSessionHasErrors('title');
    expect($c->fresh()->title)->toBe('عنوان سليم');

    // إدخال خام بالقاعدة (تجاوز كل تحقق) ← العرض يجب أن يهرّب في الصفحة العامة والبحث والاكتشاف.
    DB::table('user_collections')->where('id', $c->id)->update(['title' => 'كلمةفريدة <script>alert("xss")</script>']);
    \Illuminate\Support\Facades\Cache::flush();

    $page = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($page)->not->toContain('<script>alert("xss")</script>')->and($page)->toContain('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');

    $search = $this->get(route('search.index', ['q' => 'كلمةفريدة', 'type' => 'collections']))->assertOk()->getContent();
    expect($search)->not->toContain('<script>alert("xss")</script>');

    $discover = $this->get(route('discover.index'))->assertOk()->getContent();
    expect($discover)->not->toContain('<script>alert("xss")</script>');

    $saved = e16User();
    $this->actingAs($saved)->post(route('collections.public.save', $c->public_id));
    expect($this->actingAs($saved)->get(route('collections.saved'))->assertOk()->getContent())->not->toContain('<script>alert("xss")</script>');
});

test('E35-089: XSS in the collection description is rejected on input and escaped on output', function () {
    $owner = e16User();

    $this->actingAs($owner)->post(route('collections.store'), ['title' => 'عنوان سليم', 'description' => '<img src=x onerror=alert(1)>'])->assertSessionHasErrors('description');
    $c = e35_collection($owner, e35_works(3), 'public', 'عنوان سليم', 'وصف سليم');
    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان سليم', 'description' => '<iframe src="https://evil.test"></iframe>'])->assertSessionHasErrors('description');
    expect($c->fresh()->description)->toBe('وصف سليم');

    DB::table('user_collections')->where('id', $c->id)->update(['description' => '<img src=x onerror=alert(1)> وصف']);
    \Illuminate\Support\Facades\Cache::flush();

    $page = $this->get(route('collections.public.show', $c->public_id))->assertOk()->getContent();
    expect($page)->not->toContain('<img src=x onerror=alert(1)>')->and($page)->toContain('&lt;img src=x onerror=alert(1)&gt;');

    $own = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect($own)->not->toContain('<img src=x onerror=alert(1)>');
});

test('E35-090: visibility is protected from mass assignment (model guarded, client field ignored on create and update)', function () {
    $owner = e16User();

    expect(fn () => UserCollection::query()->create(['user_id' => $owner->id, 'title' => 'x', 'visibility' => 'public']))->toThrow(MassAssignmentException::class);
    $c = e35_collection($owner, e35_works(3));
    expect(fn () => $c->fill(['visibility' => 'public']))->toThrow(MassAssignmentException::class);

    $this->actingAs($owner)->post(route('collections.store'), ['title' => 'مجموعة جديدة', 'visibility' => 'public', 'first_published_at' => now()->toDateTimeString()]);
    $created = UserCollection::query()->where('title', 'مجموعة جديدة')->firstOrFail();
    expect($created->visibility)->toBe('private')->and($created->first_published_at)->toBeNull();

    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان معدّل', 'visibility' => 'public'])->assertRedirect();
    expect($c->fresh()->visibility)->toBe('private')->and($c->fresh()->title)->toBe('عنوان معدّل');

    // مسار الظهور نفسه يقبل القيم المسموحة فقط.
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'everyone'])->assertSessionHasErrors('visibility');
    expect($c->fresh()->visibility)->toBe('private');
});

test('E35-091: the client can never set or lift the moderation-hidden flag or reason', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(3), 'public');
    $hiddenAttrs = ['is_moderation_hidden' => 1, 'moderation_reason' => 'من العميل', 'moderation_hidden_at' => now()->toDateTimeString(), 'moderation_hidden_by' => 1];

    expect(fn () => $c->fill($hiddenAttrs))->toThrow(MassAssignmentException::class);

    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان آخر'] + $hiddenAttrs);
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'unlisted'] + $hiddenAttrs);
    $this->actingAs(e16User())->post(route('collections.public.save', $c->public_id), $hiddenAttrs);
    $this->actingAs(e16User())->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'] + $hiddenAttrs);

    $fresh = $c->fresh();
    expect($fresh->is_moderation_hidden)->toBeFalse()->and($fresh->moderation_reason)->toBeNull()->and($fresh->moderation_hidden_at)->toBeNull()->and($fresh->moderation_hidden_by)->toBeNull();

    // وعند الإخفاء الفعلي لا يرفعه المالك بأي حقل ولا بتعديل نصه.
    e35_hide_collection($c);
    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'محاولة رفع الإخفاء', 'is_moderation_hidden' => 0]);
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'public', 'is_moderation_hidden' => 0, 'acknowledge' => '1']);
    expect($c->fresh()->is_moderation_hidden)->toBeTrue();
    $this->get(route('collections.public.show', $c->public_id))->assertNotFound();
});

test('E35-092: the client can never set saves_count (derived from the save rows only)', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(3), 'public');
    $saver = e16User();

    expect(fn () => $c->fill(['saves_count' => 999]))->toThrow(MassAssignmentException::class);

    $this->actingAs($owner)->post(route('collections.store'), ['title' => 'مجموعة بعدّاد', 'saves_count' => 500]);
    expect((int) UserCollection::query()->where('title', 'مجموعة بعدّاد')->value('saves_count'))->toBe(0);

    $this->actingAs($owner)->put(route('collections.update', $c->public_id), ['title' => 'عنوان آخر', 'saves_count' => 999]);
    $this->actingAs($owner)->patch(route('collections.visibility', $c->public_id), ['visibility' => 'unlisted', 'saves_count' => 999]);
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id), ['saves_count' => 999]);
    $this->actingAs(e16User())->post(route('collections.public.report', $c->public_id), ['reason' => 'spam', 'saves_count' => 999]);

    expect((int) $c->fresh()->saves_count)->toBe(1)->and(UserCollectionSave::query()->where('user_collection_id', $c->id)->count())->toBe(1);
});

test('E35-093: CSRF protects every E35 mutation (419 without a token, nothing changes)', function () {
    $owner = e16User();
    $works = e35_works(3);
    $c = e35_collection($owner, $works, 'public');
    $saver = e16User();
    $this->actingAs($saver)->post(route('collections.public.save', $c->public_id));   // لاختبار unsave بلا رمز
    $before = ['order' => e35_order($c), 'items' => UserCollectionItem::query()->count(), 'saves' => UserCollectionSave::query()->count(), 'reports' => UserCollectionReport::query()->count()];

    $original = $this->app['env'];
    $this->app['env'] = 'local';

    try {
        $this->actingAs($owner);
        $this->patch(route('collections.visibility', $c->public_id), ['visibility' => 'private'])->assertStatus(419);
        $this->patch(route('collections.reorder', $c->public_id), ['order' => array_reverse($before['order'])])->assertStatus(419);
        $this->delete(route('collections.items.destroy', [$c->public_id, $works[0]->public_id]))->assertStatus(419);
        $this->put(route('collections.update', $c->public_id), ['title' => 'بلا رمز'])->assertStatus(419);
        $this->delete(route('collections.destroy', $c->public_id))->assertStatus(419);

        $this->actingAs($saver);
        $this->delete(route('collections.public.unsave', $c->public_id))->assertStatus(419);

        $other = e16User();
        $this->actingAs($other);
        $this->post(route('collections.public.save', $c->public_id))->assertStatus(419);
        $this->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'])->assertStatus(419);
    } finally {
        $this->app['env'] = $original;
    }

    $fresh = $c->fresh();
    expect($fresh->visibility)->toBe('public')->and($fresh->title)->not->toBe('بلا رمز')
        ->and(e35_order($c))->toBe($before['order'])
        ->and(UserCollectionItem::query()->count())->toBe($before['items'])
        ->and(UserCollectionSave::query()->count())->toBe($before['saves'])
        ->and(UserCollectionReport::query()->count())->toBe($before['reports']);
});

test('E35-094: no GET route mutates state (method audit + GET on mutation URLs is 405 + reading never writes)', function () {
    $mutating = ['collections.store', 'collections.update', 'collections.destroy', 'collections.items.destroy', 'collections.visibility', 'collections.reorder', 'collections.public.save', 'collections.public.unsave', 'collections.public.report', 'community.puzzles.collections.add'];
    $readOnly = ['collections.index', 'collections.create', 'collections.saved', 'collections.show', 'collections.edit', 'collections.public.show'];

    foreach ($mutating as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()->and($route->methods())->not->toContain('GET')->and($route->methods())->not->toContain('HEAD');
    }

    // كل مسار GET تحت المجموعات/curated معروف وقراءة فقط.
    foreach (Route::getRoutes() as $route) {
        $isCollections = str_starts_with($route->uri(), 'collections') || str_starts_with($route->uri(), 'curated');

        if ($isCollections && in_array('GET', $route->methods(), true)) {
            expect($readOnly)->toContain($route->getName());
        }
    }

    $owner = e16User();
    $c = e35_collection($owner, e35_works(3), 'public');
    $user = e16User();
    $this->actingAs($user);
    $this->get('/curated/'.$c->public_id.'/save')->assertStatus(405);
    $this->get('/curated/'.$c->public_id.'/report')->assertStatus(405);
    $this->actingAs($owner);
    $this->get('/collections/'.$c->public_id.'/visibility')->assertStatus(405);
    $this->get('/collections/'.$c->public_id.'/reorder')->assertStatus(405);

    // القراءة لا تكتب: صفحات القراءة المتكررة لا تغيّر أي صف.
    $snapshot = fn () => [
        DB::table('user_collections')->orderBy('id')->get()->toJson(),
        DB::table('user_collection_items')->count(), DB::table('user_collection_saves')->count(), DB::table('user_collection_reports')->count(),
    ];
    $before = $snapshot();

    foreach (range(1, 3) as $_) {
        auth()->logout();
        $this->get(route('collections.public.show', $c->public_id))->assertOk();
        $this->actingAs($user)->get(route('collections.public.show', $c->public_id))->assertOk();
        $this->actingAs($user)->get(route('collections.saved'))->assertOk();
        $this->get(route('discover.index'))->assertOk();
    }

    expect($snapshot())->toBe($before);
});

test('E35-095: private, unlisted and public collections are owner-scoped for management (IDOR: another user always gets 404, nothing changes)', function () {
    $owner = e16User();
    $other = e16User();

    foreach (['private', 'unlisted', 'public'] as $visibility) {
        $works = e35_works(3);
        $c = e35_collection($owner, $works, $visibility, 'مجموعة '.$visibility);
        $order = e35_order($c);
        $this->actingAs($other);

        $this->get(route('collections.show', $c->public_id))->assertNotFound();
        $this->get(route('collections.edit', $c->public_id))->assertNotFound();
        $this->put(route('collections.update', $c->public_id), ['title' => 'اختراق'])->assertNotFound();
        $this->patch(route('collections.visibility', $c->public_id), ['visibility' => 'private'])->assertNotFound();
        $this->patch(route('collections.reorder', $c->public_id), ['order' => array_reverse($order)])->assertNotFound();
        $this->delete(route('collections.items.destroy', [$c->public_id, $works[0]->public_id]))->assertNotFound();
        $this->delete(route('collections.destroy', $c->public_id))->assertNotFound();

        $fresh = $c->fresh();
        expect($fresh)->not->toBeNull()->and($fresh->title)->toBe('مجموعة '.$visibility)->and($fresh->visibility)->toBe($visibility)->and(e35_order($c))->toBe($order);
    }

    // المجموعة الخاصة لا تُقرأ ولا تُحفظ ولا يُبلَّغ عنها من غير المالك (ولا من الزائر).
    $private = e35_collection($owner, e35_works(3), 'private', 'خاصة تمامًا');
    $this->actingAs($other)->get(route('collections.public.show', $private->public_id))->assertNotFound();
    $this->actingAs($other)->post(route('collections.public.save', $private->public_id))->assertNotFound();
    $this->actingAs($other)->post(route('collections.public.report', $private->public_id), ['reason' => 'spam'])->assertNotFound();
    auth()->logout();
    $this->get(route('collections.public.show', $private->public_id))->assertNotFound();
    expect(UserCollectionSave::query()->count())->toBe(0)->and(UserCollectionReport::query()->count())->toBe(0);
});

test('E35-096: report IDOR - reporting a private collection is 404; the "open report" state is only ever shown to its own reporter; reporter identity is not exposed to the owner or others', function () {
    $owner = e16User();
    $c = e35_collection($owner, e35_works(3), 'public');
    $private = e35_collection($owner, e35_works(3), 'private');
    $reporter = e16User(['name' => 'مبلّغ فريد 7731']);
    $other = e16User();

    $this->actingAs($reporter)->post(route('collections.public.report', $private->public_id), ['reason' => 'spam'])->assertNotFound();
    expect(UserCollectionReport::query()->count())->toBe(0);

    $this->actingAs($reporter)->post(route('collections.public.report', $c->public_id), ['reason' => 'spam'])->assertRedirect();
    expect(UserCollectionReport::query()->count())->toBe(1);

    $this->actingAs($reporter)->get(route('collections.public.show', $c->public_id))->assertOk()->assertSee('لديك بلاغ مفتوح');
    $this->actingAs($other)->get(route('collections.public.show', $c->public_id))->assertOk()->assertDontSee('لديك بلاغ مفتوح');
    auth()->logout();
    $this->get(route('collections.public.show', $c->public_id))->assertOk()->assertDontSee('لديك بلاغ مفتوح')->assertDontSee('مبلّغ فريد 7731');

    $ownerView = $this->actingAs($owner)->get(route('collections.show', $c->public_id))->assertOk()->getContent();
    expect($ownerView)->not->toContain('مبلّغ فريد 7731')->and($ownerView)->not->toContain($reporter->email);

    // لوحة الإشراف: لا وصول بلا صلاحية، والمشرف يرى البلاغ.
    $report = UserCollectionReport::query()->firstOrFail();
    $this->actingAs($reporter)->get('/admin/user-collection-reports')->assertForbidden();
    $this->actingAs($reporter)->get('/admin/user-collection-reports/'.$report->getKey())->assertForbidden();
    $this->actingAs(e35_moderator())->get('/admin/user-collection-reports/'.$report->getKey())->assertOk();
});

test('E35-097: admin permissions matrix (by permission name; moderator moderates, content-manager runs shelves, administrator both, plain user nothing)', function () {
    $plain = e16User();
    $moderator = e35_moderator();
    $manager = e35_manager();
    $admin = e35_admin();

    expect($moderator->can('collections.moderate'))->toBeTrue()->and($moderator->can('collections.view'))->toBeTrue()->and($moderator->can('editorial.manage'))->toBeFalse()
        ->and($manager->can('editorial.manage'))->toBeTrue()->and($manager->can('editorial.view'))->toBeTrue()->and($manager->can('collections.moderate'))->toBeFalse()
        ->and($admin->can('collections.moderate'))->toBeTrue()->and($admin->can('editorial.manage'))->toBeTrue()
        ->and($plain->can('collections.view'))->toBeFalse()->and($plain->can('editorial.view'))->toBeFalse();

    $urls = [
        '/admin/user-collection-reports' => ['plain' => 403, 'moderator' => 200, 'manager' => 200, 'admin' => 200],
        '/admin/user-collections' => ['plain' => 403, 'moderator' => 200, 'manager' => 200, 'admin' => 200],
        '/admin/curation-analytics' => ['plain' => 403, 'moderator' => 200, 'manager' => 200, 'admin' => 200],
        '/admin/editorial-shelves' => ['plain' => 403, 'moderator' => 403, 'manager' => 200, 'admin' => 200],
        '/admin/editorial-shelves/create' => ['plain' => 403, 'moderator' => 403, 'manager' => 200, 'admin' => 200],
    ];
    $users = ['plain' => $plain, 'moderator' => $moderator, 'manager' => $manager, 'admin' => $admin];

    foreach ($urls as $url => $expected) {
        foreach ($users as $who => $user) {
            // جلسة نظيفة لكل مستخدم: AuthenticateSession في Filament يقارن بصمة كلمة المرور المخزَّنة فيُخرج المستخدم الثاني داخل الاختبار نفسه (قطعة اختبار لا إنتاج).
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->actingAs($user)->get($url)->assertStatus($expected[$who]);
        }
    }

    // الكتابة عبر الخدمة تعيد فحص الصلاحية (لا تكفي رؤية الصفحة): المشرف لا يدير الأرفف، ومدير المحتوى لا يشرف على المجموعات.
    $c = e35_public(null, 3);
    expect(fn () => app(\App\Services\Collections\CollectionModerationService::class)->hide($c->fresh(), $manager, 'سبب كافٍ للاختبار'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($c->fresh()->is_moderation_hidden)->toBeFalse();
});
