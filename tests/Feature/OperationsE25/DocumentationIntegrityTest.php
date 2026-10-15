<?php

require_once __DIR__.'/_support.php';

/**
 * E25 - سلامة الوثائق التشغيلية: الملفات المطلوبة موجودة، الأقسام الجوهرية موجودة، والروابط الداخلية (ملفات + عناوين) صحيحة،
 * ولا قيمة سرية حقيقية داخلها. الوثائق جزء من الضمان: دليل استعادة بروابط مكسورة لا ينفع وقت الحادثة.
 */
const E25_DOCS = [
    'docs/environment-variables.md',
    'docs/release-engineering.md',
    'docs/backup-disaster-recovery.md',
    'docs/operations-runbook.md',
    'docs/incident-response.md',
    'docs/production-checklist.md',
    'docs/first-production-deploy.md',
    'docs/security-hardening.md',
];

/** نفس قاعدة GitHub لاشتقاق معرّف العنوان: أحرف صغيرة، حذف الترقيم عدا _ و-، مسافات ⟵ شرطات. */
function e25_slug(string $heading): string
{
    $heading = mb_strtolower(trim(str_replace('`', '', $heading)));
    $out = '';

    foreach (mb_str_split($heading) as $char) {
        if (preg_match('/[\p{L}\p{N}\p{M}_\- ]/u', $char) === 1) {
            $out .= $char;
        }
    }

    return str_replace(' ', '-', $out);
}

function e25_anchors(string $markdown): array
{
    $seen = [];
    $anchors = [];
    $inFence = false;

    foreach (preg_split('/\r\n|\r|\n/', $markdown) as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $inFence = ! $inFence;

            continue;
        }

        if (! $inFence && preg_match('/^#{1,6}\s+(.*)$/u', $line, $m) === 1) {
            $slug = e25_slug($m[1]);
            $n = $seen[$slug] ?? 0;
            $seen[$slug] = $n + 1;
            $anchors[] = $n === 0 ? $slug : "{$slug}-{$n}";
        }
    }

    return $anchors;
}

/** @return list<array{from: string, target: string}> روابط markdown الداخلية (غير http) داخل نص. */
function e25_links(string $markdown, string $from): array
{
    preg_match_all('/\]\(([^)\s]+)\)/u', $markdown, $m);

    return collect($m[1])->reject(fn ($t) => str_starts_with($t, 'http') || str_starts_with($t, 'mailto:'))->map(fn ($t) => ['from' => $from, 'target' => $t])->all();
}

test('E25-docs: every required operations document exists and is non-trivial', function () {
    foreach (E25_DOCS as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue($doc)
            ->and(strlen(e25_repo_file($doc)))->toBeGreaterThan(1500, "{$doc} looks empty");
    }

    expect(is_file(base_path('tools/smoke/README.md')))->toBeTrue();
});

test('E25-docs: internal links and anchors in the E25 documents all resolve', function () {
    $broken = [];
    $links = [];

    foreach (array_merge(E25_DOCS, ['tools/smoke/README.md']) as $doc) {
        $links = array_merge($links, e25_links(e25_repo_file($doc), $doc));
    }

    // ملاحظات E25 أعلى الوثائق القديمة (أسطر تبدأ بـ«>»)
    foreach (['docs/production-deployment.md', 'docs/backup-restore.md', 'docs/rollback.md', 'docs/pre-launch-checklist.md', 'docs/smoke-tests.md'] as $doc) {
        $notes = collect(preg_split('/\r\n|\r|\n/', e25_repo_file($doc)))->filter(fn ($l) => str_starts_with($l, '>'))->implode("\n");
        $links = array_merge($links, e25_links($notes, $doc));
    }

    foreach ($links as ['from' => $from, 'target' => $target]) {
        [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
        $file = $path === '' ? $from : str_replace('\\', '/', (string) realpath(dirname(base_path($from)).'/'.$path));

        if ($path !== '' && ($file === '' || ! is_file($file))) {
            $broken[] = "{$from} → {$target} (ملف غير موجود)";

            continue;
        }

        if ($fragment !== null && str_ends_with($file, '.md')) {
            $content = $path === '' ? e25_repo_file($from) : (string) file_get_contents($file);

            if (! in_array(rawurldecode($fragment), e25_anchors($content), true)) {
                $broken[] = "{$from} → {$target} (عنوان غير موجود)";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('E25-docs: the backup document defines RPO/RTO, retention, offsite, encryption, restore runbook, guard, drill and DB disaster scenarios', function () {
    $doc = e25_repo_file('docs/backup-disaster-recovery.md');

    foreach (['RPO', 'RTO', 'الاحتفاظ', 'Offsite', 'التشفير', 'دليل الاستعادة', 'حارس الاستعادة', 'تمرين الاستعادة', 'تلف القاعدة', 'حذف عرضي', 'ترحيل سيئ', 'امتلاء التخزين', 'القاعدة غير متاحة', 'APP_KEY'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: the incident-response document defines three severities, the six phases and a blameless postmortem', function () {
    $doc = e25_repo_file('docs/incident-response.md');

    foreach (['SEV-1', 'SEV-2', 'SEV-3', 'Detect', 'Acknowledge', 'Contain', 'Recover', 'Verify', 'Postmortem', 'بلا لوم'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: the checklist and first-deploy runbook cover DNS, TLS, env, DB, Redis, queue, scheduler, Reverb, backups and rollback', function () {
    $checklist = e25_repo_file('docs/production-checklist.md');
    $first = e25_repo_file('docs/first-production-deploy.md');

    foreach (['DNS', 'TLS', 'APP_DEBUG=false', 'APP_KEY', 'Redis', 'queue:work', 'schedule:run', 'Reverb', 'تمرين استعادة', 'التراجع', 'SESSION_SECURE_COOKIE=true'] as $needle) {
        expect($checklist)->toContain($needle);
    }

    foreach (['rbac:migrate-legacy-roles', 'AdminUserSeeder', 'ADMIN_BOOTSTRAP_PASSWORD', 'release:smoke', 'production:check', 'php artisan optimize'] as $needle) {
        expect($first)->toContain($needle);
    }
});

test('E25-docs: the environment-variable contract names every variable the E25 code reads, classified', function () {
    $doc = e25_repo_file('docs/environment-variables.md');

    foreach (['REQUIRED', 'SECRET', 'OPTIONAL', 'INFRASTRUCTURE-SPECIFIC', 'APP_RELEASE', 'OPS_SCHEDULER_STALE_SECONDS', 'OPS_READY_FAILS_ON_SCHEDULER', 'OPS_ALLOW_DESTRUCTIVE_COMMANDS', 'REVERB_ALLOWED_ORIGINS', 'ADMIN_BOOTSTRAP_PASSWORD', 'TRUSTED_PROXIES', 'PERF_READINESS_TOKEN', 'MAINTENANCE_SECRET'] as $needle) {
        expect($doc)->toContain($needle);
    }
});

test('E25-docs: documents embed no real secret and show only placeholders for sensitive variables', function () {
    foreach (E25_DOCS as $doc) {
        $content = e25_repo_file($doc);

        // أسطر تعيين بقيمة حقيقية الشكل لمفاتيح حساسة (غير placeholder)
        preg_match_all('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|KEY))=([^\s#`]+)/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as [$all, $name, $value]) {
            expect(preg_match('/^(<[^>]+>|\$\{?[A-Z_]+\}?|\$\(.*\)|"?\$[A-Z_]+"?|age1[a-z0-9]*|\.{3}|…|null|"")$/i', trim($value, '"\'')))->toBe(1, "{$doc}: {$name} has a non-placeholder value");
        }
    }
});

test('E25-docs: the anchors the other documents rely on exist in the security hardening document', function () {
    $anchors = e25_anchors(e25_repo_file('docs/security-hardening.md'));

    expect($anchors)->toContain('تدوير-الأسرار-p4')->toContain('app_key-تحذير-خاص');
});
