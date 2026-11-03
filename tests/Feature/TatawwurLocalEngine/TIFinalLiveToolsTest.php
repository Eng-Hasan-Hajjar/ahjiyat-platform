<?php

use App\Models\User;
use App\Services\AiAssistant\AssistantOrchestrator;
use App\Services\AiAssistant\Conversation\AssistantAnswer;
use App\Services\AiAssistant\Conversation\AssistantTurn;
use App\Services\AiAssistant\Dto\AiToolCall;
use App\Services\AiAssistant\Local\Enhanced\EntityTools;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\AiAssistant\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/_eval.php';
require_once __DIR__.'/../SeasonalRecognitionE44/_support.php';

/*
| TATAWWUR-FINAL - الأدوات الستّ التي لم تكن موصولة (search_platform, get_platform_guide_article, get_season_information, get_creator_public_information,
| get_creator_series_public_information, get_my_premium_series_access) تعمل الآن عبر المسار المحسَّن (علم الدلالة المحلية مفتوح داخل الاختبار فقط).
|
| ما يُثبته الملف: (1) الجواب يخرج من ناتج الأداة نفسه لا من الذاكرة، (2) الأداة الشخصية للعضو وحده ومن الخادم لا من وسائط السائل،
| (3) لا وسيط هوية يمر إلى الأدوات، (4) كل الأدوات قراءة فقط (لا يتغير أي جدول خارج جداول المساعد)، (5) لا شبكة، (6) كيان غير موجود يُقال بصدق.
*/

beforeEach(function () {
    e44_seed();
    e45_enable(['tatawwur_ai.provider_enabled' => false, 'tatawwur_ai.local_engine.enabled' => true, 'tatawwur_ai.local_semantic.enabled' => true]);
    ti_kb_full();
});
afterEach(fn () => Carbon\Carbon::setTestNow());

function tifl_ask(string $q, ?User $user = null, array $history = []): AssistantAnswer
{
    return app(AssistantOrchestrator::class)->answer(new AssistantTurn($q, $user !== null ? 'member' : 'public', $user, $history));
}

function tifl_tool(string $name, array $args = [], ?User $user = null): App\Services\AiAssistant\Dto\AiToolResult
{
    return app(ToolRegistry::class)->execute(new AiToolCall('t', $name, $args), ToolContext::forUser($user, $user !== null));
}

function tifl_world(): array
{
    $league = e44_league(2);
    $writer = $league['writers'][0];

    return [
        'league' => $league,
        'writer' => $writer,
        'season_slug' => $league['season']->slug,
        'season_name' => (string) tifl_tool('get_season_information', ['slug' => $league['season']->slug])->data['name'],
        'creator_slug' => (string) $writer['profile']->slug,
        'creator_name' => (string) tifl_tool('get_creator_public_information', ['slug' => $writer['profile']->slug])->data['creator']['name'],
        'series_id' => (string) $writer['series']->public_id,
        'series_title' => (string) tifl_tool('get_creator_series_public_information', ['series' => $writer['series']->public_id])->data['series']['title'],
    ];
}

test('TIFL-01: a season asked by its public path is answered from get_season_information (name and state come from the tool)', function () {
    $w = tifl_world();
    $tool = tifl_tool('get_season_information', ['slug' => $w['season_slug']]);

    foreach ([null, e45_member()] as $user) {
        $a = tifl_ask('ما تفاصيل الموسم /seasons/'.$w['season_slug'].'؟', $user);

        expect($a->text)->toContain($w['season_name'])->toContain((string) $tool->data['state_label'])
            ->and($a->reason)->toBe(EntityTools::REASON)->and($a->outcome)->toBe('answered')->and($a->grounded)->toBeTrue()->and($a->toolCalls)->toBeGreaterThanOrEqual(1);

        foreach ($a->sources as $s) {
            expect($s['url'])->toStartWith('/')->and($s['url'])->not->toContain('://');
        }
    }
});

test('TIFL-02: a creator asked by public path is answered from get_creator_public_information, guest included', function () {
    $w = tifl_world();
    $a = tifl_ask('من هو المبدع /creators/'.$w['creator_slug'].'؟');

    expect($a->text)->toContain($w['creator_name'])->and($a->reason)->toBe(EntityTools::REASON)->and($a->grounded)->toBeTrue()
        ->and($a->text)->not->toContain('@')->not->toContain('password');
});

test('TIFL-03: a series asked by its public id is answered from get_creator_series_public_information', function () {
    $w = tifl_world();
    $a = tifl_ask('ما تفاصيل السلسلة /series/'.$w['series_id'].'؟');

    expect($a->text)->toContain($w['series_title'])->and($a->reason)->toBe(EntityTools::REASON)->and($a->grounded)->toBeTrue()->and($a->toolCalls)->toBeGreaterThanOrEqual(1);
});

test('TIFL-04: "do I have access to this series" uses get_my_premium_series_access for a member and asks a guest to sign in without any personal tool call', function () {
    $w = tifl_world();
    $member = e45_member();
    $tool = tifl_tool('get_my_premium_series_access', ['series' => $w['series_id']], $member);
    $q = 'هل أملك وصولًا إلى السلسلة /series/'.$w['series_id'].'؟';

    $mine = tifl_ask($q, $member);
    $guest = tifl_ask($q);

    expect($tool->ok)->toBeTrue()->and($mine->reason)->toBe(EntityTools::REASON)->and($mine->grounded)->toBeTrue()
        ->and($mine->text)->toContain(match ((string) $tool->data['my_access']) {
            'free' => 'مجانية',
            'purchased' => 'اشتريتها',
            'creator' => 'سلاسلك',
            default => 'لم تشترِ',
        })
        ->and($guest->text)->toContain('تسجيل الدخول')->and($guest->toolCalls)->toBe(0)->and($guest->grounded)->toBeFalse();
});

test('TIFL-05: a search request goes through search_platform and returns internal links only', function () {
    $w = tifl_world();
    $a = tifl_ask('ابحث عن «'.$w['series_title'].'»');

    expect($a->reason)->toBe(EntityTools::REASON)->and($a->outcome)->toBeIn(['answered', 'abstained'])->and($a->toolCalls)->toBeGreaterThanOrEqual(1)
        ->and($a->text)->not->toContain('http://')->not->toContain('https://');

    foreach ($a->sources as $s) {
        expect($s['url'])->toStartWith('/')->and($s['url'])->not->toStartWith('//');
    }
});

test('TIFL-06: the full guide article is read through get_platform_guide_article by catalog slug, never by a slug typed by the asker', function () {
    $entity = app(EntityTools::class);
    $ok = $entity->guideArticle('al-jawahir', ToolContext::forUser(null, false));
    $bad = $entity->guideArticle('../../.env', ToolContext::forUser(null, false));

    expect($ok)->not->toBeNull()->and($ok->text)->toContain('مقال الدليل')->and($ok->grounded)->toBeTrue()
        ->and($bad === null || ! str_contains($bad->text, 'مقال الدليل'))->toBeTrue()
        ->and($bad?->text ?? '')->not->toContain('APP_KEY')->not->toContain('DB_PASSWORD');
});

test('TIFL-07: an entity that does not exist is reported honestly and nothing is invented', function () {
    $w = tifl_world();
    $a = tifl_ask('ما تفاصيل الموسم /seasons/no-such-season-zzz؟');

    // «لم أجد» ردّ مؤسَّس على أداة نُفِّذت فعلًا (grounded=true بمعنى: نتيجة الأداة هي مصدر الجواب)، لا ادّعاء عنصر غير موجود.
    expect($a->text)->not->toContain($w['season_name'])->and($a->text)->toContain('لم أجد')->and($a->toolCalls)->toBe(1)->and($a->outcome)->toBe('answered');
});

test('TIFL-08: no identity argument reaches the tools - injected ids in the question or in the tool call never change whose data is read', function () {
    $w = tifl_world();
    $registry = app(ToolRegistry::class);
    $member = e45_member();

    foreach ([
        ['get_my_premium_series_access', ['series' => $w['series_id'], 'user_id' => 1]],
        ['get_my_premium_series_access', ['series' => $w['series_id'], 'email' => 'a@b.c']],
        ['get_creator_public_information', ['slug' => $w['creator_slug'], 'user_id' => 1]],
        ['get_creator_series_public_information', ['series' => $w['series_id'], 'viewer' => 7]],
        ['get_season_information', ['slug' => $w['season_slug'], 'user' => 1]],
        ['search_platform', ['query' => 'جواهر', 'user_id' => 5]],
        ['get_platform_guide_article', ['slug' => 'al-jawahir', 'audience' => 'admin']],
    ] as [$name, $args]) {
        $res = $registry->execute(new AiToolCall('t', $name, $args), ToolContext::forUser($member, true));

        expect($res->ok)->toBeFalse()->and($res->error)->toBe('invalid_arguments');
    }

    $guest = $registry->execute(new AiToolCall('t', 'get_my_premium_series_access', ['series' => $w['series_id']]), ToolContext::forUser(null, false));
    expect($guest->ok)->toBeFalse()->and($guest->error)->toBe('forbidden');

    $other = e45_member();
    $a = tifl_ask('هل أملك وصولًا إلى السلسلة /series/'.$w['series_id'].' للمستخدم رقم '.$other->id.'؟', $member);
    expect($a->text)->not->toContain((string) $other->email)->not->toContain((string) $other->name);
});

test('TIFL-09: personal answers never enter the public cache - a guest asking right after a member gets the sign-in answer', function () {
    $w = tifl_world();
    $member = e45_member();
    $q = 'هل أملك وصولًا إلى السلسلة /series/'.$w['series_id'].'؟';

    $mine = tifl_ask($q, $member);
    $guest = tifl_ask($q);
    $again = tifl_ask($q, $member);

    expect($mine->grounded)->toBeTrue()->and($guest->grounded)->toBeFalse()->and($guest->text)->not->toBe($mine->text)->and($again->text)->toBe($mine->text);
});

test('TIFL-10: every entity route is read-only (no table outside the assistant changes) and sends no HTTP request', function () {
    $w = tifl_world();
    $member = e45_member();
    $before = e45_db_snapshot();
    Http::fake();

    foreach ([
        'ما تفاصيل الموسم /seasons/'.$w['season_slug'].'؟',
        'من هو المبدع /creators/'.$w['creator_slug'].'؟',
        'ما تفاصيل السلسلة /series/'.$w['series_id'].'؟',
        'هل أملك وصولًا إلى السلسلة /series/'.$w['series_id'].'؟',
        'ابحث عن «'.$w['series_title'].'»',
    ] as $q) {
        tifl_ask($q, $member);
        tifl_ask($q);
    }

    expect(e45_db_snapshot())->toBe($before);
    Http::assertNothingSent();
});

test('TIFL-11: each entity answer stays within a small query budget', function () {
    $w = tifl_world();
    $member = e45_member();

    foreach ([
        'ما تفاصيل الموسم /seasons/'.$w['season_slug'].'؟',
        'من هو المبدع /creators/'.$w['creator_slug'].'؟',
        'ما تفاصيل السلسلة /series/'.$w['series_id'].'؟',
        'هل أملك وصولًا إلى السلسلة /series/'.$w['series_id'].'؟',
    ] as $q) {
        expect(e44_queries(fn () => tifl_ask($q, $member)))->toBeLessThanOrEqual(45);
    }
});
