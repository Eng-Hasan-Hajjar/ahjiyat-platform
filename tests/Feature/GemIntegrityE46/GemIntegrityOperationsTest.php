<?php

use App\Filament\Pages\GemIntegrityPage;
use App\Filament\Pages\OperationsCenter;
use App\Models\GemIntegrityFinding;
use App\Models\GemIntegrityRun;
use App\Models\User;
use App\Services\Economy\Integrity\GemIntegrityRunner;
use App\Services\GemWalletService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/_support.php';

/*
| E46 - بطاقة «سلامة الجواهر» في مركز العمليات وصفحة التفاصيل: الصلاحيات خادميًا، «لم يُفحص بعد» لا صفر، لا فحص عند الزيارة،
| لا أزرار تعديل رصيد، والتفاصيل لمن يملك الصلاحية فقط.
*/

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->gem = e46_gem();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('administrator');
});

function e46_seed_run(): GemIntegrityRun
{
    $good = User::factory()->create();
    $bad = User::factory()->create();
    $opening = User::factory()->create();
    app(GemWalletService::class)->credit($good, 10, 'solved_puzzle:1');
    app(GemWalletService::class)->credit($bad, 20, 'solved_puzzle:2');
    e46_age_everything();
    e46_tamper($bad, e46_gem(), ['pending_balance' => 5]);
    e46_tamper($opening, e46_gem(), ['available_balance' => 300, 'lifetime_earned' => 300]);

    return app(GemIntegrityRunner::class)->run(e46_scope(null, [e46_gem()->id]));
}

// ───────────── البطاقة في مركز العمليات ─────────────

test('E46-40: with no completed scan the card says «لم يُفحص بعد» and shows no zero counters', function () {
    $html = $this->actingAs($this->admin)->get(OperationsCenter::getUrl())->assertOk()->getContent();

    expect($html)->toContain('data-gem-integrity-card')->toContain('لم يُفحص بعد')->toContain('data-gem-integrity-state="never"')
        ->not->toContain('data-gem-proven')->not->toContain('data-gem-undetermined');
});

test('E46-41: after a scan the card shows the saved counters and a link to the authorised details page', function () {
    $run = e46_seed_run();

    $html = $this->actingAs($this->admin)->get(OperationsCenter::getUrl())->assertOk()->getContent();
    $x = new DOMXPath(tap(new DOMDocument, fn ($d) => @$d->loadHTML('<?xml encoding="utf-8"?>'.$html)));
    $text = fn (string $attr) => trim($x->query("//*[@{$attr}]")->item(0)?->textContent ?? '');

    expect([$text('data-gem-proven'), $text('data-gem-undetermined'), $text('data-gem-matched')])
        ->toBe([(string) $run->proven_differences, (string) $run->undetermined, (string) $run->matched])
        ->and([$run->proven_differences, $run->undetermined])->toBe([1, 1])
        ->and($x->query('//a[@data-gem-integrity-link]')->item(0)->getAttribute('href'))->toBe(GemIntegrityPage::getUrl())
        ->and($html)->not->toContain('data-gem-integrity-state="never"');
});

test('E46-42: visiting the Operations Center never runs a scan, writes nothing, and does not read wallets or the ledger', function () {
    e46_seed_run();
    $runs = GemIntegrityRun::count();
    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    $this->actingAs($this->admin)->get(OperationsCenter::getUrl())->assertOk();

    $writes = array_filter($statements, fn ($s) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $s) === 1);
    $touching = array_filter($statements, fn ($s) => preg_match('/(currency_transactions|from [`"]wallets[`"])/i', $s) === 1);

    expect(GemIntegrityRun::count())->toBe($runs)
        ->and(array_values(array_filter($writes, fn ($s) => str_contains($s, 'gem_integrity'))))->toBe([])
        ->and(array_values($touching))->toBe([]);
});

test('E46-43: the card is hidden (null, not zero) for staff who can open the center but lack economy.transactions.view', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    expect($moderator->can('operations.dashboard_view'))->toBeTrue()->and($moderator->can('economy.transactions.view'))->toBeFalse();

    $this->actingAs($moderator);
    $page = Livewire::test(OperationsCenter::class)->instance();

    expect($page->getGemIntegrityCard())->toBeNull();

    $html = $this->actingAs($moderator)->get(OperationsCenter::getUrl())->assertOk()->getContent();
    expect($html)->not->toContain('data-gem-integrity-card')->not->toContain('سلامة الجواهر');
});

// ───────────── صفحة التفاصيل: حماية خادمية ─────────────

// كل دور في اختبار مستقل (طلب واحد لكل تطبيق): بعد abort داخل mount() يبقى ربط redirect الخاص بـLivewire في حاوية الاختبار نفسها،
// فيفشل الطلب التالي داخل الاختبار ذاته بخطأ نوع لا علاقة له بالصلاحيات. في الإنتاج كل طلب بحاوية جديدة.
test('E46-44: the details page is protected server-side: a direct URL gives 403 to a role without economy.transactions.view', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->can('economy.transactions.view'))->toBeFalse();

    $this->actingAs($user)->get(GemIntegrityPage::getUrl())->assertForbidden();
})->with(['moderator', 'support', 'player']);

test('E46-44b: the authorised administrator gets 200 on the same direct URL', function () {
    expect($this->admin->can('economy.transactions.view'))->toBeTrue();

    $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk();
});

test('E46-45: a guest cannot reach the details page', function () {
    $response = $this->get(GemIntegrityPage::getUrl());

    expect($response->status())->toBeIn([302, 401, 403]);
    expect($response->status() === 302 ? $response->headers->get('Location') : '/login')->toContain('login');
});

// ───────────── صفحة التفاصيل: المحتوى ─────────────

test('E46-46: the details page says «لم يُفحص بعد» before any scan and lists proven/undetermined cases afterwards', function () {
    $never = $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk()->getContent();
    expect($never)->toContain('data-gem-integrity-state="never"')->toContain('لم يُفحص بعد')->not->toContain('data-gem-findings');

    $run = e46_seed_run();

    $html = $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('data-gem-findings')->toContain('فرق مثبت')->toContain('لا يمكن الجزم')
        ->toContain('رصيد بلا أي حركة في الدفتر')
        ->and(GemIntegrityFinding::where('run_id', $run->id)->count())->toBe(2);
});

test('E46-47: the verdict filter narrows the list and ignores unknown values; pagination moves by 25', function () {
    $run = e46_seed_run();

    foreach (range(1, 30) as $i) {
        GemIntegrityFinding::query()->create([
            'run_id' => $run->id, 'wallet_id' => 1000 + $i, 'user_id' => $this->admin->id, 'currency_id' => $this->gem->id,
            'verdict' => 'undetermined', 'stored_available' => $i, 'derived_available' => 0, 'stored_pending' => 0, 'derived_pending' => 0,
            'ledger_rows' => 0, 'reasons' => ['no_ledger'],
        ]);
    }

    $this->actingAs($this->admin);
    $page = Livewire::test(GemIntegrityPage::class);

    $page->set('verdict', 'proven_difference');
    expect($page->instance()->findings())->toHaveCount(1)->and($page->instance()->total())->toBe(1);

    $page->set('verdict', 'undetermined')->assertSet('pageNumber', 1);
    expect($page->instance()->total())->toBe(31)->and($page->instance()->findings())->toHaveCount(25);

    $page->call('nextPage')->assertSet('pageNumber', 2);
    expect($page->instance()->findings())->toHaveCount(6);

    $page->call('nextPage')->assertSet('pageNumber', 2);          // لا يتجاوز آخر صفحة
    $page->call('previousPage')->call('previousPage')->assertSet('pageNumber', 1);

    $page->set('verdict', 'drop table users');                      // قيمة غير مسموحة ⇒ كأنها «الكل»، بلا خطأ
    expect($page->instance()->total())->toBe(32);
});

test('E46-48: the details page offers no balance-editing control: no form, no adjust/fix action, only paging buttons', function () {
    e46_seed_run();

    $html = $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk()->getContent();
    preg_match_all('/wire:click="([^"]+)"/', $html, $clicks);

    // أزرار الصفحة التي أنشأتها E46 فقط (لا نطلب خلو الصفحة كلها من عناصر Filament العامة كشريط التنقل)
    $mine = array_values(array_unique(array_intersect($clicks[1], ['previousPage', 'nextPage'])));
    // الدوال المعرَّفة في الصفحة نفسها فقط (دوال Livewire الأساسية كـsetId لا تخصّنا)
    $declared = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        array_filter((new ReflectionClass(GemIntegrityPage::class))->getMethods(), fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === GemIntegrityPage::class),
    );
    $writers = array_filter($declared, fn ($m) => preg_match('/(adjust|fix|repair|apply|correct|reconcile|credit|debit|release|refund|balance|save|delete|update(?!dVerdict))/i', $m) === 1);

    // نص الشرح يقول صراحةً «لا يوجد إصلاح تلقائي»؛ المطلوب غياب أي **عنصر تحكم** (زر/رابط/نموذج) بهذا المعنى
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $controls = [];
    foreach ((new DOMXPath($dom))->query('//main//button | //main//a | //main//form | //main//input') as $node) {
        $controls[] = trim($node->textContent.' '.$node->getAttribute('value').' '.$node->getAttribute('wire:click'));
    }
    $forbidden = array_filter($controls, fn ($c) => preg_match('/(إصلاح|تعديل|تسوية|اعتماد|تصحيح|تحديث الرصيد|fix|adjust|repair)/iu', $c) === 1);

    expect($mine)->toEqual(['previousPage', 'nextPage'])->and(array_values($writers))->toBe([])
        ->and(array_values($forbidden))->toBe([]);
});

test('E46-49: opening the details page does not run a scan or touch wallets/ledger', function () {
    e46_seed_run();
    $before = [GemIntegrityRun::count(), DB::table('wallets')->get()->toArray(), DB::table('currency_transactions')->get()->toArray()];

    $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk();

    expect(GemIntegrityRun::count())->toBe($before[0])
        ->and(DB::table('wallets')->get()->toArray())->toEqual($before[1])
        ->and(DB::table('currency_transactions')->get()->toArray())->toEqual($before[2]);
});

test('E46-50: a failed latest attempt is flagged without leaking the internal error, and never replaces the trusted result', function () {
    $trusted = e46_seed_run();
    GemIntegrityRun::query()->create([
        'status' => 'failed', 'scope_kind' => 'all_currencies', 'chunk' => 500, 'grace_seconds' => 120,
        'error' => 'PDOException: SQLSTATE secret-looking-detail', 'started_at' => now(), 'finished_at' => now(),
    ]);

    $html = $this->actingAs($this->admin)->get(GemIntegrityPage::getUrl())->assertOk()->getContent();

    expect($html)->toContain('data-gem-integrity-failed')->not->toContain('secret-looking-detail')->not->toContain('PDOException')
        ->and(GemIntegrityRun::lastTrusted()->id)->toBe($trusted->id);
});
