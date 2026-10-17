<?php

use App\Filament\Resources\CreatorSeriesRevisionResource;
use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ListCreatorSeriesRevisions;
use App\Filament\Resources\CreatorSeriesRevisionResource\Pages\ViewCreatorSeriesRevision;
use App\Models\CreatorSeriesRevision;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E30 (B) - لوحة مراجعة السلاسل في Filament: الدخول بالصلاحية، الاعتماد/طلب التعديل/الرفض بالأسباب، إخفاء/استعادة السلسلة، إخفاء/استعادة عنصر (أحجية)
| ولا يُقبل عنصر ليس من السلسلة، وصفحة التفاصيل تعرض ظهور كل عنصر ولا تعرض أي إجابة. كل قرار مدقَّق ومُشعِر.
*/

beforeEach(function () {
    e30s_seed();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('AM15-143: the series review queue opens for a moderator and stays closed to players and to roles without creators.view', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator, null, ['title' => 'سلسلة تنتظر المراجعة']);

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful()->assertSee('سلسلة تنتظر المراجعة');
    auth()->logout();

    $this->actingAs(e16User());
    expect(CreatorSeriesRevisionResource::canViewAny())->toBeFalse();
    $this->get('/admin')->assertForbidden();
});

test('AM15-144: drafts are private to the creator and never reach the review queue', function () {
    $creator = e28_creator();
    e30s_draft($creator, null, ['title' => 'مسودة خاصة بالمبدع']);
    e30s_pending($creator, null, ['title' => 'مرسلة للمراجعة']);

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSee('مرسلة للمراجعة')->assertDontSee('مسودة خاصة بالمبدع');
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'draft')->assertDontSee('مسودة خاصة بالمبدع');
});

test('AM15-145: a moderator approves; reasons are mandatory for changes/reject; a view-only role cannot act', function () {
    $creator = e28_creator();
    $toApprove = e30s_pending($creator, null, ['title' => 'للاعتماد']);
    $toReject = e30s_pending($creator, null, ['title' => 'للرفض']);
    $toChange = e30s_pending($creator, null, ['title' => 'لطلب التعديل']);

    $viewOnly = e16User();
    $viewOnly->givePermissionTo('creators.view');
    $this->actingAs($viewOnly);
    Livewire::test(ListCreatorSeriesRevisions::class)->assertSuccessful()
        ->assertTableActionHidden('approve', $toApprove)->assertTableActionHidden('reject', $toApprove)->assertTableActionHidden('changes', $toApprove);
    auth()->logout();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('reject', $toReject, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('changes', $toChange, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    expect($toReject->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_PENDING_REVIEW);

    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('approve', $toApprove)->assertHasNoTableActionErrors();
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('reject', $toReject, ['note' => 'سبب الرفض مكتوب وواضح.'])->assertHasNoTableActionErrors();
    Livewire::test(ListCreatorSeriesRevisions::class)->callTableAction('changes', $toChange, ['note' => 'عدّل ترتيب العناصر.'])->assertHasNoTableActionErrors();

    expect($toApprove->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_APPROVED)
        ->and(e30s_visible($toApprove->series()->first()))->toBeTrue()
        ->and($toReject->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_REJECTED)
        ->and($toChange->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_CHANGES_REQUESTED)
        ->and(DB::table('operational_audit_logs')->whereIn('action', ['creator_series_approved', 'creator_series_rejected', 'creator_series_changes_requested'])->count())->toBe(3);
});

test('AM15-146: hiding a published series from the panel removes it everywhere and restoring brings it back; both are audited', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, null, ['title' => 'سلسلة للإخفاء الإشرافي']);
    $revision = $series->publishedRevision()->value('id');

    $this->get(route('series.show', $series->public_id))->assertOk();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide', $revision, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide', $revision, ['note' => 'مخالفة لسياسة المحتوى.'])->assertHasNoTableActionErrors();
    auth()->logout();

    $this->get(route('series.show', $series->public_id))->assertNotFound();
    expect(e30s_visible($series))->toBeFalse();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')->callTableAction('restore', $revision)->assertHasNoTableActionErrors();
    auth()->logout();

    $this->get(route('series.show', $series->public_id))->assertOk();
    expect(DB::table('operational_audit_logs')->whereIn('action', ['creator_series_hidden', 'creator_series_restored'])->count())->toBe(2);
});

test('AM15-147: hiding one item (puzzle) from the panel hides the series by the live rule and restoring it returns the series', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $revision = $series->publishedRevision()->value('id');

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('hide_item', $revision, ['work' => $works[1]->public_id, 'note' => 'الأحجية مخالفة.'])->assertHasNoTableActionErrors();
    auth()->logout();

    expect($works[1]->refresh()->is_visible)->toBeFalse()->and(e30s_visible($series))->toBeFalse();
    $this->get(route('series.show', $series->public_id))->assertNotFound();

    $this->actingAs(e28_reviewer('moderator'));
    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
        ->callTableAction('restore_item', $revision, ['work' => $works[1]->public_id])->assertHasNoTableActionErrors();
    auth()->logout();

    expect($works[1]->refresh()->is_visible)->toBeTrue()->and(e30s_visible($series))->toBeTrue();
});

test('AM15-148: the item actions refuse a puzzle that is not an item of the series (forged form values change nothing)', function () {
    $creator = e28_creator();
    $series = e30s_published($creator, e30s_works($creator, 3));
    $revision = $series->publishedRevision()->value('id');
    $foreign = e28_published(e28_creator(), 'text', ['title' => 'عمل مبدع آخر']);
    $outsider = e28_published($creator, 'text', ['title' => 'عمل لنفس المبدع خارج السلسلة']);

    $this->actingAs(e28_reviewer('moderator'));

    foreach ([$foreign, $outsider] as $work) {
        Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')
            ->callTableAction('hide_item', $revision, ['work' => $work->public_id, 'note' => 'محاولة إخفاء عمل ليس من السلسلة.']);

        expect($work->refresh()->is_visible)->toBeTrue();
    }

    expect(e30s_visible($series))->toBeTrue();
});

test('AM15-149: the detail page shows each item with its visibility and the live blockers, and never shows an answer', function () {
    $creator = e28_creator();
    $works = e30s_works($creator, 3);
    $series = e30s_published($creator, $works);
    $revision = $series->publishedRevision()->value('id');
    e30s_hide_work($works[2]);

    $this->actingAs(e28_reviewer('moderator'));
    $html = Livewire::test(ViewCreatorSeriesRevision::class, ['record' => $revision])->assertSuccessful()->html();

    expect($html)->toContain($works[0]->refresh()->published_title)->and($html)->toContain('غير ظاهرة')->and($html)->toContain('ظاهرة');
    e28_assert_no_secrets($html, ['القلم']);

    Livewire::test(ListCreatorSeriesRevisions::class)->filterTable('status', 'approved')->assertSee('عنصر غير ظاهر');
});

test('AM15-150: a player without the permission cannot reach the review pages or call the actions', function () {
    $creator = e28_creator();
    $revision = e30s_pending($creator);

    $this->actingAs(e16User());

    $this->get('/admin/creator-series-revisions')->assertForbidden();
    $this->get('/admin/creator-series-revisions/'.$revision->getKey())->assertForbidden();
    expect($revision->refresh()->status)->toBe(CreatorSeriesRevision::STATUS_PENDING_REVIEW);
});
