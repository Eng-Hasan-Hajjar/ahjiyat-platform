<?php
/** One-time, token-gated archive deploy for the AQL puzzle platform. */
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

const TOKEN_HASH = 'bc2b3c27cebd94a699136d07dccbb1aa9fe6d6d25371b531a140580061161812';
$given = $_GET['key'] ?? '';
if (!is_string($given) || !hash_equals(TOKEN_HASH, hash('sha256', $given))) {
    http_response_code(404);
    exit('Not found');
}
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZIP extension is not enabled.');
}
$target = __DIR__ . '/puzzle-gems-platform';
$archive = $target . '/puzzle-gems-release.zip';
$guard = $target . '/.htaccess';
// Never extract into a web-accessible Laravel root without a verified deny rule.
if (!is_dir($target) || !is_file($archive) || !is_file($guard) ||
    strpos((string)file_get_contents($guard), 'Require all denied') === false) {
    http_response_code(409);
    exit('Safety check failed: protected target, ZIP or .htaccess is missing.');
}
$zip = new ZipArchive();
$result = $zip->open($archive);
if ($result !== true) {
    http_response_code(500);
    exit('Cannot open ZIP file. ZIP error: ' . (int)$result);
}
$total = $zip->numFiles;
$startRaw = $_GET['i'] ?? '0';
if (!is_string($startRaw) || !ctype_digit($startRaw)) {
    http_response_code(400);
    exit('Invalid offset.');
}
$start = (int)$startRaw;
if ($start > $total) {
    http_response_code(400);
    exit('Offset out of range.');
}
$stop = min($total, $start + 100);
for ($i = $start; $i < $stop; $i++) {
    $name = $zip->getNameIndex($i);
    if (!is_string($name)) {
        http_response_code(500);
        exit('Cannot read ZIP entry number ' . $i);
    }
    $normalized = preg_replace('~^(?:\./)+~', '', $name);
    if ($normalized === '' || $normalized === '/') {
        continue;
    }
    $segments = explode('/', $normalized);
    $bad = str_starts_with($normalized, '/') || str_contains($normalized, '\\') ||
           preg_match('/^[A-Za-z]:/', $normalized) || in_array('..', $segments, true) ||
           in_array('', array_slice($segments, 0, -1), true);
    if ($bad || $normalized === '.htaccess' || $normalized === '.env') {
        http_response_code(400);
        exit('Unsafe archive entry at index ' . $i . '. Stopping.');
    }
    $opsys = null;
    $attributes = null;
    if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) &&
        $opsys === ZipArchive::OPSYS_UNIX && ((($attributes >> 16) & 0170000) === 0120000)) {
        http_response_code(400);
        exit('Symbolic link entry detected at index ' . $i . '. Stopping.');
    }
    if (!$zip->extractTo($target, $name)) {
        http_response_code(500);
        exit('Extraction failed at entry ' . $i . '. Reload this URL to retry.');
    }
}
$zip->close();
if ($stop === $total) {
    $removed = @unlink(__FILE__);
    echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>اكتمل فك الضغط</title>';
    echo '<body style="font:22px Arial;padding:36px"><h2>✅ اكتمل فك الضغط</h2>';
    echo '<p>تمت معالجة ' . (int)$total . ' عنصراً داخل مجلد المشروع المحمي.</p>';
    if (!$removed) {
        echo '<p style="color:#a00">تنبيه: احذف ملف aql-extract.php من الجذر الآن، فقد تعذر حذفه تلقائياً.</p>';
    } else {
        echo '<p>تم حذف أداة التثبيت المؤقتة تلقائياً للحماية.</p>';
    }
    echo '<p>أرسل صورة هذه الصفحة إلى ChatGPT.</p></body></html>';
    exit;
}
$next = '/aql-extract.php?key=' . rawurlencode($given) . '&i=' . $stop;
header('Refresh: 1; url=' . $next);
echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>فك الضغط</title>';
echo '<body style="font:22px Arial;padding:36px"><h2>جارٍ فك الضغط...</h2>';
echo '<p>' . (int)$stop . ' / ' . (int)$total . ' ملفاً ومجلداً</p>';
echo '<p>ستستمر العملية تلقائياً. لا تغلق هذه الصفحة.</p>';
echo '<p><a href="' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">متابعة يدوياً إن توقفت الصفحة</a></p>';
echo '</body></html>';
