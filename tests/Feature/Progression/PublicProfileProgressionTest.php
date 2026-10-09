<?php

use App\Models\LevelDefinition;
use App\Models\User;
use App\Services\Progression\XpService;

beforeEach(function () {
    LevelDefinition::factory()->first()->create();
    LevelDefinition::factory()->create(['level_number' => 2, 'xp_required_total' => 100, 'name' => 'مستوى 2']);
});

test('E12 req 213/276: a public profile shows the current level number', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    app(XpService::class)->grantXp($player, 100, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $this->get(route('players.show', $player))->assertOk()->assertSee('المستوى 2');
});

/**
 * النص المرئي للصفحة فقط (بلا script/style/svg ولا السمات): الرابط العام للاعب يحوي معرّف ULID عشوائيًا قد يحمل أي رقمين متتاليين (≈3% لـ "77")،
 * فالبحث بالرقم في HTML الخام كان يفشل عشوائيًا. الخصوصية المقصودة: لا مبلغ XP ولا سبب معاملته يظهران **للزائر**.
 */
function publicProfileVisibleText(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    foreach (['script', 'style', 'svg', 'noscript', 'template'] as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    // عقد النص تُضمّ بفراغ بينها: لا يلتصق نص عنصرين متجاورين (ولا يتشكّل رقم وهمي من حدودهما).
    $parts = [];
    foreach ((new DOMXPath($dom))->query('//text()') as $textNode) {
        $parts[] = $textNode->nodeValue;
    }

    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

test('E12 req 133/278: a public profile never leaks any XP transaction reason or amount', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);
    // مبلغ مميَّز (لا يطابق أي رقم آخر بالصفحة) + سبب مميَّز؛ ونتحقق من النص المرئي وبصيغتَي الكتابة (7391 / 7,391).
    app(XpService::class)->grantXp($player, 7391, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'سبب-سري-للاختبار');

    $response = $this->get(route('players.show', $player))->assertOk()->assertDontSee('سبب-سري-للاختبار');
    $visible = publicProfileVisibleText($response->getContent());

    expect($visible)->not->toBe('')
        ->and($visible)->toContain('المستوى')                                      // الصفحة رُسمت فعلًا (لا نص فارغ يمرّر الاختبار)
        ->and($visible)->not->toContain('7391')->not->toContain('7,391')->not->toContain('٧٣٩١')
        ->and($visible)->not->toContain('سبب-سري-للاختبار');
});

test('the visible-text helper ignores URLs, attributes and SVG data (the original source of the flaky ULID false positive)', function () {
    $html = '<html><body><a href="/players/01HZZ77ABCDEF77/competitive" title="77">ملف</a><svg><path d="M7 7 77 77"/></svg><script>var x = 77;</script><p>المستوى 1</p></body></html>';

    expect(publicProfileVisibleText($html))->toBe('ملف المستوى 1')->not->toContain('77');
});

test('a public profile shows the count of unlocked achievements, not any internal keys', function () {
    $achievement = \App\Models\Achievement::factory()->puzzlesSolvedTotal(1)->create(['internal_key' => 'super_secret_internal_key']);
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PUBLIC]);

    $puzzle = \App\Models\Puzzle::factory()->create(['answer_raw' => 'صح']);
    app(\App\Services\PuzzleAttemptService::class)->attempt($player, $puzzle, 'صح');
    app(\App\Services\Progression\AchievementService::class)->evaluateForEvent('puzzle_solved', $player);

    $response = $this->get(route('players.show', $player));

    $response->assertOk()
        ->assertSee('1', false)
        ->assertDontSee('super_secret_internal_key');
});

test('privacy semantics from E11 are unchanged - a private profile still returns 404 to a stranger', function () {
    $player = User::factory()->create(['profile_visibility' => User::VISIBILITY_PRIVATE]);
    app(XpService::class)->grantXp($player, 50, \App\Models\XpTransaction::TYPE_PUZZLE_SOLVE, 'test');

    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('players.show', $player))->assertNotFound();
});