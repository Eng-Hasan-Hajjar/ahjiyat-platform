<?php

use App\Models\Campaign;
use App\Models\Puzzle;
use App\Models\ReferralCode;
use App\Models\Season;
use App\Models\UserReferral;
use App\Services\Referrals\ReferralShareStats;
use App\Services\Referrals\ShareLinkBuilder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/_support.php';

beforeEach(fn () => e33_seed());

test('AF8-52: share URLs come only from named routes on the app host, kinds are a closed list and hostile text stays inert', function () {
    $b = app(ShareLinkBuilder::class);
    $host = parse_url(route('home'), PHP_URL_HOST);

    $s = $b->build('creator', 'ahmad', 'عنوان', null, null);
    expect($s['url'])->toBe(route('creators.show', 'ahmad'))->and($s['url_ref'])->toBeNull()->and($s['text'])->toBe('عنوان');

    foreach (['external', 'redirect', 'url', '../admin', 'PUZZLE', ''] as $kind) {
        expect(fn () => $b->build($kind, 'http://evil.test', 't'))->toThrow(NotFoundHttpException::class);
    }

    $hostile = $b->build('creator', 'https://evil.test/x', 't');
    expect(parse_url($hostile['url'], PHP_URL_HOST))->toBe($host)->and($hostile['url'])->toStartWith(route('home'));

    foreach ($b->build('platform', null, 'انضم إلينا')['links']['plain'] as $link) {
        expect($link)->toStartWith('https://')->and(parse_url($link, PHP_URL_HOST))->toBeIn(['wa.me', 't.me']);
    }

    $long = $b->build('platform', null, str_repeat('ع', 500), str_repeat('م', 900));
    expect(mb_strlen($long['title']))->toBe(120)->and(mb_strlen($long['text']))->toBe(200);

    // العنوان العدائي لا يصل إلى الصفحة كوسم نشط ولا يكسر سمات JS
    $html = Blade::render('<x-share-button kind="platform" :title="$t" />', ['t' => '</script><script>alert(1)</script>"\'><img src=x onerror=alert(2)>']);
    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x')->not->toContain('onerror=alert(2)>');
});

test('AF8-53: the personal invite variant exists only for a verified, active member with a code, and building a link never creates a code', function () {
    $b = app(ShareLinkBuilder::class);

    $guest = $b->build('platform', null, 't');
    expect($guest['url_ref'])->toBeNull()->and($guest['links']['ref'])->toBeNull();

    $noCode = e33_user();
    $before = ReferralCode::query()->count();
    expect($b->build('platform', null, 't', null, $noCode)['url_ref'])->toBeNull()
        ->and(ReferralCode::query()->count())->toBe($before);

    $member = e33_user();
    $code = e33_code($member);
    $s = $b->build('platform', null, 't', null, $member);
    expect($s['url'])->not->toContain('?')->and($s['url_ref'])->toBe(route('home').'?ref='.$code)
        ->and($s['links']['ref']['whatsapp'])->toContain(rawurlencode('?ref='.$code))
        ->and($s['links']['plain']['whatsapp'])->not->toContain('ref');

    $unverified = e33_user(['email_verified_at' => null]);
    e33_code($member); // الرمز يبقى لمالكه فقط
    expect($b->build('platform', null, 't', null, $unverified)['url_ref'])->toBeNull();

    DB::table('users')->where('id', $member->id)->update(['is_frozen' => true]);
    expect($b->build('platform', null, 't', null, $member->fresh())['url_ref'])->toBeNull();
});

test('AF8-54: the component is first-party only, works without Web Share and exposes the toggle and tracking only to members', function () {
    $guestHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />');
    expect($guestHtml)
        ->toContain('data-share-component')->toContain('data-share-copy')->toContain('navigator.share')->toContain('execCommand')
        ->toContain('rel="noopener noreferrer"')->toContain('https://wa.me/')->toContain('https://t.me/share/url')
        ->not->toContain('data-share-ref-toggle')->not->toContain('referrals\\/share')->not->toContain('referrals/share')
        ->not->toContain('<script')->not->toContain('<iframe')->not->toContain('<img');

    // لا يظهر أي مضيف طرف ثالث غير هدفَي المشاركة الصريحين (www.w3.org = اسم فضاء SVG داخل الأيقونة، لا يُجلَب)
    preg_match_all('#https?://([a-z0-9.-]+)#i', $guestHtml, $m);
    expect(collect($m[1])->unique()->diff(['wa.me', 't.me', 'www.w3.org', parse_url(route('home'), PHP_URL_HOST)])->values()->all())->toBe([]);

    $member = e33_user();
    $code = e33_code($member);
    $this->actingAs($member);
    $memberHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />', []);
    // Blade::render لا يعرف المستخدم الحالي إلا عبر الحارس، وهو مضبوط أعلاه
    expect($memberHtml)->toContain('data-share-ref-toggle')->toContain('referrals\\/share')->toContain('?ref='.$code);

    // مستخدم مسجَّل بلا رمز: لا مفتاح دعوة، فقط إشارة لصفحة الدعوات
    $other = e33_user();
    $this->actingAs($other);
    $otherHtml = Blade::render('<x-share-button kind="platform" title="أحجيات" />');
    expect($otherHtml)->not->toContain('data-share-ref-toggle')->toContain(route('referrals.index'));
});

test('AF8-55: share tracking is member only, validated against closed lists, rate limited, and grants nothing', function () {
    $member = e33_user();
    $code = e33_code($member);
    $row = ReferralCode::query()->where('code', $code)->firstOrFail();

    $this->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertUnauthorized();

    $snapshot = fn () => [
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(),
        (int) DB::table('wallets')->sum('available_balance') + (int) DB::table('wallets')->sum('pending_balance'),
        UserReferral::query()->count(),
    ];
    $before = $snapshot();

    foreach ([
        ['kind' => 'puzzle', 'target' => 'copy'], ['kind' => 'community_puzzle', 'target' => 'whatsapp', 'with_ref' => true], ['kind' => 'season', 'target' => 'native'],
    ] as $payload) {
        $this->actingAs($member)->postJson(route('referrals.share'), $payload)->assertNoContent();
    }

    foreach ([
        ['kind' => 'https://evil.test', 'target' => 'copy'], ['kind' => 'puzzle', 'target' => 'facebook'], ['kind' => 'puzzle'], ['target' => 'copy'],
        ['kind' => ['a'], 'target' => 'copy'], ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => 'maybe'],
    ] as $bad) {
        $this->actingAs($member)->postJson(route('referrals.share'), $bad)->assertStatus(422);
    }

    $stats = app(ReferralShareStats::class)->lastDays(7);
    expect($stats['total'])->toBe(3)->and($stats['by_kind'])->toBe(['puzzle' => 1, 'community_puzzle' => 1, 'season' => 1])
        ->and($stats['by_target'])->toBe(['native' => 1, 'copy' => 1, 'whatsapp' => 1])
        ->and($row->fresh()->shares_count)->toBe(1)
        ->and($snapshot())->toBe($before);

    // عضو بلا رمز: with_ref لا يُنشئ رمزًا ولا يفشل
    $nocode = e33_user();
    $this->actingAs($nocode)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy', 'with_ref' => true])->assertNoContent();
    expect(ReferralCode::query()->where('user_id', $nocode->id)->exists())->toBeFalse();

    config(['referrals.limits.share_per_minute' => 3]);
    $limited = e33_user();
    e33_code($limited);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($limited)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertNoContent();
    }

    $this->actingAs($limited)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'copy'])->assertStatus(429);
});

test('AF8-56: shareable pages declare a clean canonical and OG tags that ignore ref, campaign and tracking parameters', function () {
    $puzzle = Puzzle::factory()->create(['is_active' => true]);
    $campaign = Campaign::factory()->create(['is_active' => true]);
    $season = Season::factory()->create(['campaign_id' => $campaign->id, 'is_published' => true]);
    $owner = e33_user();
    $code = e33_code($owner);

    $pages = [
        [route('puzzles.show', $puzzle), route('puzzles.show', $puzzle)],
        [route('seasons.show', $season), route('seasons.show', $season)],
        [route('campaigns.show', $campaign), route('campaigns.show', $campaign)],
        [route('supporters.index'), route('supporters.index')],
    ];

    foreach ($pages as [$url, $canonical]) {
        $html = $this->get($url.'?ref='.$code.'&c=whatever&utm_source=x&fbclid=y')->assertOk()->getContent();

        expect($html)->toContain('<link rel="canonical" href="'.$canonical.'">')->toContain('<meta property="og:url" content="'.$canonical.'">')
            ->toContain('property="og:site_name"')->toContain('property="og:locale"')->toContain('name="description"');

        preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m);
        expect($m[1])->not->toContain('?')->not->toContain('ref=')->not->toContain($code);
    }

    // الصفحة العامة تحمل مكوّن المشاركة ولا تنعكس القيمة المرسلة في ?ref داخلها
    $evil = '"><script>alert(1)</script>';
    $body = $this->get(route('puzzles.show', $puzzle).'?ref='.urlencode($evil))->assertOk()->getContent();
    expect($body)->toContain('data-share-component')->not->toContain($evil)->not->toContain('<script>alert(1)</script>');
});

test('AF8-57: opening or sharing a link never changes content, economy or attribution of a signed-in visitor', function () {
    $puzzle = Puzzle::factory()->create(['is_active' => true]);
    $owner = e33_user();
    $code = e33_code($owner);
    $visitor = e33_user();

    $before = [
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(), UserReferral::query()->count(),
        (int) DB::table('wallets')->sum('available_balance'),
    ];

    $plain = $this->actingAs($visitor)->get(route('puzzles.show', $puzzle))->assertOk();
    $withRef = $this->actingAs($visitor)->get(route('puzzles.show', $puzzle).'?ref='.$code)->assertOk();
    $withRef->assertSessionMissing('referral_intent');

    // نُسقِط ما يختلف بين أي طلبين متتاليين بطبيعته: رموز CSRF/nonce، وحقن أصول Livewire (أول استجابة فقط في العملية)، والتعليقات والمسافات
    $strip = function (string $html) {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $html = preg_replace('#(name="csrf-token" content=")[^"]+|(nonce=")[^"]+|(_token" value=")[^"]+#', '$1$2$3', $html);

        return trim(preg_replace('#\s+#', ' ', $html));
    };
    expect($strip($withRef->getContent()))->toBe($strip($plain->getContent()))
        ->and(ReferralCode::query()->where('code', $code)->value('clicks_count'))->toBe(0); // الزائر المصادَق لا يُحتسب نقرة إحالة

    $this->actingAs($visitor)->postJson(route('referrals.share'), ['kind' => 'puzzle', 'target' => 'whatsapp', 'with_ref' => true])->assertNoContent();

    expect([
        DB::table('currency_transactions')->count(), DB::table('xp_transactions')->count(), UserReferral::query()->count(),
        (int) DB::table('wallets')->sum('available_balance'),
    ])->toBe($before);

    // المحيل نفسه: مشاركته لا تغيّر ترتيبه ولا رصيده ولا تُنشئ إحالة
    $this->actingAs($owner)->postJson(route('referrals.share'), ['kind' => 'platform', 'target' => 'copy', 'with_ref' => true])->assertNoContent();
    expect(UserReferral::query()->where('referrer_user_id', $owner->id)->count())->toBe(0);
});
