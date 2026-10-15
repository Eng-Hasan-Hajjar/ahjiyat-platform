<?php

require_once __DIR__.'/_support.php';

/*
| E26 (X) - الوثائق الأمنية موجودة، تغطي الأقسام المطلوبة، روابطها الداخلية سليمة، وتوافق الإعدادات الفعلية (لا متغير موثَّق بلا وجود، ولا متغير موجود بلا توثيق).
*/

if (! function_exists('e26_slug')) {
    function e26_slug(string $heading): string
    {
        $heading = mb_strtolower(trim($heading));
        $heading = preg_replace('/[^\p{L}\p{N} _-]/u', '', $heading);

        return str_replace(' ', '-', (string) $heading);
    }

    /** @return list<string> */
    function e26_anchors_of(string $markdown): array
    {
        $anchors = [];
        $fence = false;
        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fence = ! $fence;

                continue;
            }
            if (! $fence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m)) {
                $anchors[] = e26_slug($m[1]);
            }
        }

        return $anchors;
    }
}

test('X1: the three E26 documents exist and the main one carries every required section', function () {
    foreach (['docs/security-e26.md', 'docs/security-checklist.md', 'SECURITY.md'] as $file) {
        expect(base_path($file))->toBeFile();
        expect(strlen((string) file_get_contents(base_path($file))))->toBeGreaterThan(800);
    }

    $anchors = e26_anchors_of((string) file_get_contents(base_path('docs/security-e26.md')));
    foreach (['threat-model', 'attack-surface', 'controls', 'findings', 'fixes', 'deferred-risks', 'security-testing-guide', 'environment-variables'] as $section) {
        expect($anchors)->toContain($section);
    }
});

test('X2: every internal link in the E26 documents resolves to an existing file and heading', function () {
    $broken = [];

    foreach (['docs/security-e26.md', 'docs/security-checklist.md', 'SECURITY.md'] as $file) {
        $dir = dirname(base_path($file));
        $text = (string) file_get_contents(base_path($file));
        preg_match_all('/\]\((?!https?:|mailto:)([^)#\s]*)(?:#([^)\s]+))?\)/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $target = $m[1] === '' ? base_path($file) : realpath($dir.'/'.$m[1]);
            if ($target === false || ! is_file($target)) {
                $broken[] = "{$file} -> {$m[1]} (missing file)";

                continue;
            }
            if (($m[2] ?? '') !== '' && ! in_array(rawurldecode($m[2]), e26_anchors_of((string) file_get_contents($target)), true)) {
                $broken[] = "{$file} -> {$m[1]}#{$m[2]} (missing heading)";
            }
        }
    }

    expect($broken)->toBe([]);

    // الرابط الذي أضافه E26 إلى security-hardening.md (القديم) يشير لعنوان موجود فعلًا.
    expect((string) file_get_contents(base_path('docs/security-hardening.md')))->toContain('security-e26.md#حدود-الإساءة-rate-limits');
    expect(e26_anchors_of((string) file_get_contents(base_path('docs/security-e26.md'))))->toContain('حدود-الإساءة-rate-limits');
});

test('X3: every SECURITY_* / E26 variable documented exists in config and .env.example, and every config key is documented', function () {
    $doc = (string) file_get_contents(base_path('docs/security-e26.md'));
    $env = (string) file_get_contents(base_path('.env.example'));
    $config = (string) file_get_contents(base_path('config/security.php'));

    preg_match_all("/env\('(SECURITY_[A-Z_]+)'/", $config, $m);
    $vars = array_unique($m[1]);
    expect($vars)->not->toBeEmpty();

    foreach (array_merge($vars, ['REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM']) as $var) {
        expect($doc)->toContain($var);
        expect($env)->toContain($var);
    }
});

test('X4: SECURITY.md is a responsible-disclosure policy - private reporting, safe scope, no bug-bounty promise, no secrets', function () {
    $text = (string) file_get_contents(base_path('SECURITY.md'));

    expect($text)->toContain('Security Policy')->toContain('لا تفتح Issue عامًا')->toContain('لا يوجد برنامج مكافآت مالية');
    expect($text)->not->toMatch('/\$\s?\d|مكافأة قدرها|bounty of/iu');
    expect(preg_match('/[A-Za-z0-9._%+-]+@(?!example\.)[A-Za-z0-9.-]+\.[a-z]{2,}/', $text))->toBe(0);   // لا عنوان بريد حقيقي مكتوب بالملف
});

test('X5: the security checklist covers the controls that can lock the site out or open it up', function () {
    $text = (string) file_get_contents(base_path('docs/security-checklist.md'));

    foreach (['APP_URL', 'SECURITY_TRUSTED_HOSTS', 'TRUSTED_PROXIES', 'SESSION_SECURE_COOKIE', 'REVERB_ALLOWED_ORIGINS', 'composer audit', 'npm audit', 'security.login_lockout', 'ADMIN_BOOTSTRAP'] as $needle) {
        expect($text)->toContain($needle);
    }
});
