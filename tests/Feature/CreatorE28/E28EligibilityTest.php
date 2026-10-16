<?php

use App\Models\CreatorProfile;
use App\Models\OperationalAuditLog;
use App\Models\User;
use App\Policies\CreatorProfilePolicy;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorException;
use App\Services\Creators\CreatorPuzzleService;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class));

const E28_BIO = 'مهتم بصناعة الألغاز الذهنية وأرغب بمشاركتها مع مجتمع المنصة.';

test('Y1-1 (1): a normal verified user can apply to become a creator', function () {
    $user = e16User();

    $this->actingAs($user)->get(route('creator.apply'))->assertOk();
    $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertRedirect(route('creator.apply'))->assertSessionHas('success');

    $profile = CreatorProfile::query()->where('user_id', $user->id)->firstOrFail();
    expect($profile->status)->toBe('pending')->and($profile->bio)->toBe(E28_BIO)->and($profile->slug)->not->toBe('')
        ->and($profile->submitted_at)->not->toBeNull()->and($profile->approved_at)->toBeNull();
});

test('Y1-2 (2): a guest cannot apply', function () {
    $this->get(route('creator.apply'))->assertRedirect(route('login'));
    $this->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertRedirect(route('login'));

    expect(CreatorProfile::query()->count())->toBe(0);
});

test('Y1-3 (3): an unverified user cannot apply (route and service)', function () {
    $user = e16User(['email_verified_at' => null]);

    $response = $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO]);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBe(route('creator.apply'));

    expect(fn () => app(CreatorApplicationService::class)->apply($user, E28_BIO))->toThrow(CreatorException::class)
        ->and(CreatorProfile::query()->count())->toBe(0);
});

test('Y1-4 (4): a frozen user cannot apply and a frozen creator cannot use the studio', function () {
    $frozen = e26_freeze(e16User());
    $this->actingAs($frozen)->post(route('creator.apply.store'), ['bio' => E28_BIO]);
    expect(CreatorProfile::query()->where('user_id', $frozen->id)->exists())->toBeFalse()
        ->and(fn () => app(CreatorApplicationService::class)->apply($frozen, E28_BIO))->toThrow(CreatorException::class);

    $creator = e28_creator();
    $this->actingAs($creator)->get(route('creator.dashboard'))->assertOk();
    e26_freeze($creator);

    $this->actingAs($creator->fresh())->get(route('creator.dashboard'))->assertStatus(302);
    $this->actingAs($creator->fresh())->getJson(route('creator.puzzles.index'))->assertStatus(403);
    expect(fn () => app(CreatorPuzzleService::class)->createDraft($creator->fresh(), e28_data()))->toThrow(CreatorException::class);
});

test('Y1-5 (5): a duplicate pending application is prevented', function () {
    $user = e16User();
    $svc = app(CreatorApplicationService::class);
    $svc->apply($user, E28_BIO);

    expect(fn () => $svc->apply($user->fresh(), E28_BIO))->toThrow(CreatorException::class);
    $this->actingAs($user)->post(route('creator.apply.store'), ['bio' => E28_BIO])->assertSessionHas('error');

    expect(CreatorProfile::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('Y1-6 (6): an authorized reviewer approves the application - audited, notified, studio opens', function () {
    $user = e16User();
    $profile = app(CreatorApplicationService::class)->apply($user, E28_BIO);
    $reviewer = e28_reviewer('administrator');

    app(CreatorApplicationService::class)->approve($profile, $reviewer, 'مرحبًا بك');

    $profile->refresh();
    expect($profile->status)->toBe('approved')->and($profile->approved_at)->not->toBeNull()->and($profile->reviewed_by)->toBe($reviewer->id);
    expect(OperationalAuditLog::query()->where('action', 'creator_approved')->where('subject_id', $profile->id)->where('actor_user_id', $reviewer->id)->exists())->toBeTrue();
    expect($user->notifications()->count())->toBe(1);

    $this->actingAs($user->fresh())->get(route('creator.dashboard'))->assertOk();
});

test('Y1-7 (7): unauthorized actions are denied - a plain player, a moderator without manage, and a viewer-only role', function () {
    $profile = app(CreatorApplicationService::class)->apply(e16User(), E28_BIO);
    $svc = app(CreatorApplicationService::class);

    expect(fn () => $svc->approve($profile, e16User()))->toThrow(HttpException::class)
        ->and(fn () => $svc->reject($profile, e16User(), 'سبب واضح للرفض'))->toThrow(HttpException::class);

    // المشرف يراجع (creators.review) لكنه لا يعلّق (creators.manage).
    $moderator = e28_reviewer('moderator');
    $svc->approve($profile, $moderator);
    expect(fn () => $svc->suspend($profile->fresh(), $moderator, 'مخالفة الإرشادات'))->toThrow(HttpException::class);

    // سياسات لوحة الإدارة: عرض بـcreators.view فقط، ولا كتابة خام لأحد.
    $policy = new CreatorProfilePolicy;
    expect($policy->viewAny(e16User()))->toBeFalse()->and($policy->viewAny($moderator))->toBeTrue()
        ->and($policy->update($moderator, $profile))->toBeFalse()->and($policy->delete(e28_reviewer('administrator'), $profile))->toBeFalse()->and($policy->create($moderator))->toBeFalse();
    expect(Gate::forUser(e16User())->allows('viewAny', CreatorProfile::class))->toBeFalse();
});

test('Y1-8 (8): rejection stores the reason, requires one, notifies, and allows re-applying', function () {
    $user = e16User();
    $svc = app(CreatorApplicationService::class);
    $profile = $svc->apply($user, E28_BIO);
    $reviewer = e28_reviewer();

    expect(fn () => $svc->reject($profile, $reviewer, 'x'))->toThrow(CreatorException::class);

    $svc->reject($profile, $reviewer, 'النبذة غير كافية لتقييم خبرتك.');
    $profile->refresh();
    expect($profile->status)->toBe('rejected')->and($profile->review_note)->toBe('النبذة غير كافية لتقييم خبرتك.')
        ->and(OperationalAuditLog::query()->where('action', 'creator_rejected')->exists())->toBeTrue()
        ->and($user->notifications()->count())->toBe(1);

    $again = $svc->apply($user->fresh(), E28_BIO.' نبذة محسّنة بعد الرفض.');
    expect($again->id)->toBe($profile->id)->and($again->status)->toBe('pending')->and($again->review_note)->toBeNull();
});

test('Y1-9 (9): a suspended creator can neither create nor submit, and the studio redirects', function () {
    $creator = e28_creator();
    $draft = e28_draft($creator);
    $publicId = $draft->work()->value('public_id');
    $svc = app(CreatorPuzzleService::class);

    app(CreatorApplicationService::class)->suspend($creator->creatorProfile()->first(), e28_reviewer('administrator'), 'مخالفة متكررة للإرشادات');
    $creator = $creator->fresh();

    expect(fn () => $svc->createDraft($creator, e28_data()))->toThrow(CreatorException::class)
        ->and(fn () => $svc->submit($creator, $publicId))->toThrow(CreatorException::class)
        ->and(fn () => $svc->updateDraft($creator, $publicId, e28_data()))->toThrow(CreatorException::class);

    $this->actingAs($creator)->get(route('creator.dashboard'))->assertRedirect(route('creator.apply'));
    $this->actingAs($creator)->post(route('creator.puzzles.store'), e28_data())->assertRedirect(route('creator.apply'));
    expect(app(CreatorPuzzleService::class)->paginate($creator->creatorProfile()->first())->total())->toBe(1);
});
