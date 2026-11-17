<?php

use App\Models\CurrencyTransaction;
use App\Models\User;
use App\Services\Economy\CurrencyWalletService;
use App\Services\GemWalletService;
use App\Support\WalletMovement;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/_support.php';

/*
| E46 - «محفظتي» للاعب: فهم المتاح/المعلّق وسبب كل حركة في ثوانٍ، بلا رموز تقنية، بفلتر بسيط، وبلا أي أثر كتابي عند العرض/التصفية.
*/

function e46_dom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return new DOMXPath($dom);
}

function e46_texts(DOMXPath $x, string $query): array
{
    $out = [];

    foreach ($x->query($query) as $node) {
        $out[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }

    return $out;
}

/** لاعب بحركات من كل نوع عبر الخدمات الحقيقية: كسب معلّق، إتاحة، خصم، استرداد، تعديل إداري. */
function e46_player_with_history(): User
{
    $user = User::factory()->create();
    $gem = e46_gem();
    $wallets = app(CurrencyWalletService::class);

    app(GemWalletService::class)->credit($user, 40, 'solved_puzzle:76');                        // earn (معلّق)
    $wallets->releasePending($user, $gem, 25, 'pending_hold_expired');                          // release
    $wallets->debitAvailable($user, $gem, 5, 'hint:12');                                        // debit
    $wallets->refund($user, $gem, 5, 'redemption_rejected:9');                                  // refund (type=admin_adjustment كما يكتبه RedemptionService)
    $wallets->adjust($user, $gem, 3, 'تسوية يدوية من الإدارة');                                 // other

    return $user;
}

test('E46-80: each kind of movement is named and explained, and the release is NOT shown as a signed earning', function () {
    $user = e46_player_with_history();

    $x = e46_dom($this->actingAs($user)->get(route('wallet.index'))->assertOk()->getContent());
    $rows = [];

    foreach ($x->query('//li[@data-tx]') as $li) {
        $q = fn (string $attr) => trim(preg_replace('/\s+/u', ' ', (new DOMXPath($li->ownerDocument))->query(".//*[@{$attr}]", $li)->item(0)->textContent));
        $rows[(new DOMXPath($li->ownerDocument))->evaluate('string(@data-tx-kind)', $li)] = [$q('data-tx-kind-label'), $q('data-tx-amount'), $q('data-tx-effect'), $q('data-tx-label')];
    }

    expect($rows)->toHaveKeys(['earn', 'release', 'debit', 'refund', 'other'])
        ->and($rows['earn'])->toBe(['كسب', '+40', 'أُضيف إلى رصيدك المعلّق، ويصبح متاحًا بعد انتهاء فترة التعليق', 'حلّ أحجية'])
        ->and($rows['release'])->toBe(['إتاحة', '25', 'انتقل من رصيدك المعلّق إلى المتاح (مجموع ما تملكه لم يتغيّر)', 'إتاحة بعد انتهاء فترة التعليق'])
        ->and($rows['debit'])->toBe(['خصم', '−5', 'خُصم من رصيدك المتاح', 'شراء تلميح'])
        ->and($rows['refund'])->toBe(['استرداد', '+5', 'أُعيد إلى رصيدك المتاح', 'استرداد طلب استبدال مرفوض'])
        ->and($rows['other'])->toBe(['تعديل', '+3', 'أُضيف إلى رصيدك المتاح', 'تسوية يدوية من الإدارة']);
});

test('E46-81: meaning never depends on colour alone: every row has an icon, a kind word and an effect sentence', function () {
    $x = e46_dom($this->actingAs(e46_player_with_history())->get(route('wallet.index'))->assertOk()->getContent());

    $rows = $x->query('//li[@data-tx]');
    expect($rows->length)->toBe(5);

    foreach ($rows as $li) {
        $sub = new DOMXPath($li->ownerDocument);
        expect($sub->query('.//*[@data-tx-kind-label]//svg', $li)->length)->toBe(1)
            ->and(trim($sub->query('.//*[@data-tx-kind-label]', $li)->item(0)->textContent))->not->toBe('')
            ->and(trim($sub->query('.//*[@data-tx-effect]', $li)->item(0)->textContent))->not->toBe('');
    }

    $icons = [];
    foreach ($x->query('//li[@data-tx]') as $li) {
        $icons[$li->getAttribute('data-tx-kind')] = (new DOMXPath($li->ownerDocument))->query('.//*[@data-tx-kind-label]//svg//path', $li)->item(0)?->getAttribute('d');
    }

    expect(count(array_unique($icons)))->toBe(5);   // خمس أيقونات مختلفة لخمسة أنواع
});

test('E46-82: no internal key reaches the player: raw reasons, codes and underscore-style tokens are never rendered', function () {
    $user = e46_player_with_history();
    app(CurrencyWalletService::class)->creditAvailable($user, e46_gem(), 7, 'campaign_reward');
    app(CurrencyWalletService::class)->creditAvailable($user, e46_gem(), 7, 'weird_internal_key:991');

    $html = $this->actingAs($user)->get(route('wallet.index'))->assertOk()->getContent();
    $x = e46_dom($html);
    $labels = e46_texts($x, '//*[@data-tx-label]');

    expect($x->query('//*[@data-tx-raw]')->length)->toBe(0)
        ->and($html)->not->toContain('solved_puzzle')->not->toContain('redemption_rejected')->not->toContain('pending_hold_expired')
        ->not->toContain('campaign_reward')->not->toContain('weird_internal_key')->not->toContain('hint:12')
        ->and($labels)->toContain('إضافة إلى الرصيد')               // الرمز التقني ⇒ تسمية عامة بحسب النوع
        ->and($labels)->toContain('تسوية يدوية من الإدارة');        // نص عربي حرّ يبقى كما كان
});

test('E46-83: a player sees only their own transactions, whatever the filter', function () {
    $mine = e46_player_with_history();
    $other = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($other, e46_gem(), 777, 'تسوية خاصة بلاعب آخر');

    foreach ([[], ['kind' => 'other'], ['currency' => e46_gem()->id], ['currency' => e46_gem()->id, 'kind' => 'earn']] as $query) {
        $html = $this->actingAs($mine)->get(route('wallet.index', $query))->assertOk()->getContent();
        expect($html)->not->toContain('تسوية خاصة بلاعب آخر')->not->toContain('777');
    }
});

test('E46-84: the kind filter keeps only that kind; unknown kind/currency values are ignored without errors', function () {
    $user = e46_player_with_history();

    foreach (['earn', 'release', 'debit', 'refund', 'other'] as $kind) {
        $x = e46_dom($this->actingAs($user)->get(route('wallet.index', ['kind' => $kind]))->assertOk()->getContent());
        $kinds = array_map(fn ($li) => $li->getAttribute('data-tx-kind'), iterator_to_array($x->query('//li[@data-tx]')));

        expect($kinds)->toBe([$kind]);
    }

    foreach ([['kind' => 'nope'], ['kind' => ['x']], ['currency' => 'abc'], ['currency' => '999999'], ['currency' => ['1']], ['kind' => "' OR 1=1 --"]] as $bad) {
        $x = e46_dom($this->actingAs($user)->get(route('wallet.index', $bad))->assertOk()->getContent());

        expect($x->query('//li[@data-tx]')->length)->toBe(5);
    }
});

test('E46-85: the currency filter only accepts the player\'s own wallet currencies and isolates them', function () {
    $user = e46_player_with_history();
    $event = e46_event_currency();
    app(CurrencyWalletService::class)->creditPending($user, $event, 30, 'campaign_reward');
    $foreign = e46_event_currency();                                   // عملة ليس للاعب محفظة فيها
    app(CurrencyWalletService::class)->creditPending(User::factory()->create(), $foreign, 11, 'campaign_reward');

    $onlyEvent = e46_dom($this->actingAs($user)->get(route('wallet.index', ['currency' => $event->id]))->assertOk()->getContent());
    $foreignAsked = e46_dom($this->actingAs($user)->get(route('wallet.index', ['currency' => $foreign->id]))->assertOk()->getContent());

    expect($onlyEvent->query('//li[@data-tx]')->length)->toBe(1)
        ->and(e46_texts($onlyEvent, '//li[@data-tx]//*[@data-tx-amount]'))->toBe(['+30'])
        ->and($foreignAsked->query('//li[@data-tx]')->length)->toBe(6);   // عملة غريبة تُتجاهل ⇒ الكل (بلا حركات غيره)
});

test('E46-86: the SQL filter and the PHP classification can never disagree, for every type/reason combination', function () {
    $user = User::factory()->create();
    $gem = e46_gem();
    $types = ['earn_pending', 'release_available', 'redeem', 'expire', 'admin_adjustment', 'refund', 'purchase', 'spend', 'progression_reward', 'something_new'];
    $reasons = ['', 'solved_puzzle:1', 'redemption_rejected:5', 'store_refund:9', 'redemption_request:5', 'free text', 'pending_hold_expired'];

    foreach ($types as $type) {
        foreach ($reasons as $reason) {
            DB::table('currency_transactions')->insert([
                'user_id' => $user->id, 'currency_id' => $gem->id, 'amount' => 1, 'type' => $type, 'reason' => $reason,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $all = CurrencyTransaction::query()->where('user_id', $user->id)->get();
    $total = 0;

    foreach (array_keys(WalletMovement::kinds()) as $kind) {
        $viaSql = WalletMovement::scopeKind(CurrencyTransaction::query()->where('user_id', $user->id), $kind)->pluck('id')->sort()->values()->all();
        $viaPhp = $all->filter(fn ($t) => WalletMovement::kindOf($t->type, $t->reason) === $kind)->pluck('id')->sort()->values()->all();

        expect($viaSql)->toBe($viaPhp);
        $total += count($viaSql);
    }

    expect($total)->toBe($all->count());                                   // كل صف في فئة واحدة بالضبط
});

test('E46-87: opening or filtering the wallet never releases gems, never writes, and never changes the wallet or the redemption conditions', function () {
    $user = User::factory()->create();
    app(GemWalletService::class)->credit($user, 40, 'solved_puzzle:1');
    DB::table('currency_transactions')->update(['created_at' => now()->subDays(30)]);          // انتهت فترة تعليقها منذ زمن
    $before = [DB::table('wallets')->get()->toArray(), DB::table('currency_transactions')->get()->toArray(), (new App\Services\RedemptionService(app(CurrencyWalletService::class), app(App\Services\Economy\CurrencyRegistry::class)))->checkEligibility($user)];

    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    foreach ([[], ['kind' => 'release'], ['kind' => 'earn', 'currency' => e46_gem()->id], ['page' => 2]] as $query) {
        $this->actingAs($user)->get(route('wallet.index', $query))->assertOk();
    }

    // كتابات الوسطاء العامة لأي صفحة موثَّقة (device_sightings, player_progressions...) سابقة لـE46 ولا علاقة لها بالمحفظة؛ المطلوب هنا غياب أي كتابة
    // على جداول الاقتصاد/الاستبدال/سلامة الجواهر.
    $economyTables = '/\b(wallets|currency_transactions|gem_transactions|redemption_requests|gem_integrity_runs|gem_integrity_findings|operational_audit_logs)\b/i';
    $writes = array_filter($statements, fn ($s) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $s) === 1 && preg_match($economyTables, $s) === 1);
    $after = [DB::table('wallets')->get()->toArray(), DB::table('currency_transactions')->get()->toArray(), (new App\Services\RedemptionService(app(CurrencyWalletService::class), app(App\Services\Economy\CurrencyRegistry::class)))->checkEligibility($user)];

    expect(array_values($writes))->toBe([])
        ->and($after)->toEqual($before)
        ->and(e46_wallet($user, e46_gem())->pending_balance)->toBe(40);       // لم يُحرَّر شيء رغم قِدم الحركة
});

test('E46-88: pagination is fixed at 20 per page, the client cannot enlarge it, and the filter survives paging', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $i) {
        app(CurrencyWalletService::class)->creditAvailable($user, e46_gem(), $i, 'تسوية رقم '.$i);
    }

    $first = $this->actingAs($user)->get(route('wallet.index', ['kind' => 'other', 'per_page' => 1000, 'limit' => 1000]))->assertOk()->getContent();
    $x = e46_dom($first);
    $links = array_map(fn ($a) => html_entity_decode($a->getAttribute('href')), iterator_to_array($x->query('//a[contains(@href,"page=2")]')));

    $second = e46_dom($this->actingAs($user)->get(route('wallet.index', ['kind' => 'other', 'page' => 2]))->assertOk()->getContent());

    expect($x->query('//li[@data-tx]')->length)->toBe(20)
        ->and($links)->not->toBe([])
        ->and($links[0])->toContain('kind=other')
        ->and($second->query('//li[@data-tx]')->length)->toBe(5);
});

test('E46-89: no availability date and no hold-days number is promised anywhere on the page', function () {
    $user = e46_player_with_history();
    $holdDays = (int) config('gems.pending_hold_days');

    $text = implode(' ', e46_texts(e46_dom($this->actingAs($user)->get(route('wallet.index'))->assertOk()->getContent()), '//main'));

    expect(preg_match('/\d+\s*(يوم|أيام|ساعة|ساعات)/u', $text))->toBe(0)
        ->and($text)->not->toContain("بعد {$holdDays} ")
        ->and(preg_match('/(سيُتاح|ستُتاح|يتاح في|متاح في)\s*\d{4}-\d{2}-\d{2}/u', $text))->toBe(0);
});

test('E46-90: the ledger is a stacked list that wraps on small screens (no table), and the currency filter appears only with 2+ wallets', function () {
    $single = e46_player_with_history();
    $html = $this->actingAs($single)->get(route('wallet.index'))->assertOk()->getContent();
    $x = e46_dom($html);

    expect($x->query('//main//table')->length)->toBe(0)
        ->and($x->query('//ul[@data-wallet="transactions"]/li')->length)->toBe(5)
        ->and($html)->toContain('break-words')
        ->and($x->query('//select[@name="currency"]')->length)->toBe(0)
        ->and($x->query('//select[@name="kind"]')->length)->toBe(1);

    app(CurrencyWalletService::class)->creditPending($single, e46_event_currency(), 5, 'campaign_reward');
    $two = e46_dom($this->actingAs($single)->get(route('wallet.index'))->assertOk()->getContent());

    expect($two->query('//select[@name="currency"]')->length)->toBe(1)
        ->and($two->query('//select[@name="currency"]/option')->length)->toBe(3);   // الكل + عملتان
});

test('E46-91: a filter with no match explains it and offers a way back; a truly empty wallet keeps the original empty state', function () {
    $user = User::factory()->create();
    app(CurrencyWalletService::class)->creditAvailable($user, e46_gem(), 5, 'تسوية');

    $noMatch = e46_dom($this->actingAs($user)->get(route('wallet.index', ['kind' => 'release']))->assertOk()->getContent());
    expect($noMatch->query('//*[@data-wallet="no-match"]')->length)->toBe(1)
        ->and(e46_texts($noMatch, '//h3'))->not->toContain('لا توجد معاملات بعد');

    $empty = e46_dom($this->actingAs(User::factory()->create())->get(route('wallet.index'))->assertOk()->getContent());
    expect(e46_texts($empty, '//h3'))->toContain('لا توجد معاملات بعد')
        ->and($empty->query('//*[@data-wallet="no-match"]')->length)->toBe(0);
});

test('E46-92: the page keeps its identity: one h1, the same stat cards, no emoji, and a guest is sent to login', function () {
    $user = e46_player_with_history();
    $x = e46_dom($this->actingAs($user)->get(route('wallet.index'))->assertOk()->getContent());

    expect($x->query('//h1')->length)->toBe(1)
        ->and($x->query('//*[@data-wallet="standard"]')->length)->toBe(1)
        ->and(preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', implode(' ', e46_texts($x, '//main'))))->toBe(0);

    auth()->logout();
    $this->get(route('wallet.index'))->assertRedirect();
});
