<?php

require_once __DIR__.'/UiTestHelpers.php';
require_once __DIR__.'/../Social/SocialTestHelpers.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

dataset('guest pages', ['/', '/puzzles', '/seasons', '/competitions', '/competitions/hall-of-fame', '/teams', '/teams/leaderboard', '/team-championships', '/leaderboard', '/store', '/login', '/register', '/terms', '/privacy']);
dataset('player pages', ['/', '/wallet', '/inventory', '/redemption', '/teams', '/teams/leaderboard', '/teams/invitations', '/team-championships', '/store', '/friends', '/friends/search', '/notifications', '/notifications/preferences', '/messages', '/profile', '/profile/customize', '/competitions', '/competitions/hall-of-fame']);

/** @return list<string> مشكلات الوصولية بالصفحة (فارغة = سليمة). */
function uiA11yProblems(string $html): array
{
    $x = uiXpath($html);
    $problems = [];

    if ($x->query('/html[@lang="ar" and @dir="rtl"]')->length !== 1) {
        $problems[] = 'html lang/dir';
    }

    if ($x->query('//main[@id="main-content"]')->length !== 1) {
        $problems[] = 'main#main-content';
    }

    $skip = $x->query('(//a | //button | //input | //select | //textarea)[1]')->item(0);

    if (! $skip || $skip->getAttribute('href') !== '#main-content') {
        $problems[] = 'skip link is not the first focusable element';
    }

    if ($x->query('//h1')->length !== 1) {
        $problems[] = 'h1 count = '.$x->query('//h1')->length;
    }

    foreach (uiUnnamedControls($x, '/html/body') as $bad) {
        $problems[] = 'unnamed control: '.$bad;
    }

    foreach ($x->query('//img[not(@alt)]') as $img) {
        $problems[] = 'img without alt: '.$img->getAttribute('src');
    }

    foreach ($x->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="checkbox" or @type="radio")] | //select | //textarea') as $field) {
        $id = $field->getAttribute('id');
        $named = $field->getAttribute('aria-label') !== '' || $field->getAttribute('aria-labelledby') !== '' || $field->getAttribute('title') !== ''
            || ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
            || $x->query('ancestor::label', $field)->length > 0;

        if (! $named) {
            $problems[] = 'field without a label: '.($field->getAttribute('name') ?: $field->nodeName);
        }
    }

    return $problems;
}

test('AX1: every public page is accessible - language, landmark, skip link, one h1, named controls', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();

    expect(uiA11yProblems($html))->toBe([], "guest $url");
})->with('guest pages');

test('AX2: every player page is accessible - language, landmark, skip link, one h1, named controls', function (string $url) {
    $html = $this->actingAs(e16User())->get($url)->assertOk()->getContent();

    expect(uiA11yProblems($html))->toBe([], "player $url");
})->with('player pages');

test('AX3: the skip link is visually hidden until focused and the main landmark is focusable programmatically', function () {
    $x = uiXpath($this->get('/')->assertOk()->getContent());
    $link = $x->query('//a[@data-skip-link]')->item(0);
    $main = $x->query('//main[@id="main-content"]')->item(0);

    expect($link->getAttribute('class'))->toContain('sr-only')->toContain('focus:not-sr-only')
        ->and($link->getAttribute('href'))->toBe('#main-content')
        ->and($main->getAttribute('tabindex'))->toBe('-1');
});
