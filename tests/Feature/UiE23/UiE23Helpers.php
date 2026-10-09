<?php

require_once __DIR__.'/../UiE22/UiTestHelpers.php';
require_once __DIR__.'/../Social/SocialTestHelpers.php';
require_once __DIR__.'/../Teams/TeamTestHelpers.php';
require_once __DIR__.'/../Competitive/CompetitiveTestHelpers.php';

/**
 * مساعدات E23 (عرض فقط): قراءة ملفات CSS/JS/Blade كنصوص ثابتة (عقود الطبقات والرموز) + أدوات DOM صغيرة.
 * لا اعتماد على ملفات البناء (public/build) فالاختبارات تعمل قبل npm run build وبعده.
 */
if (! function_exists('e23Css')) {
    function e23Css(string $file = 'app.css'): string
    {
        return file_get_contents(resource_path('css/'.$file));
    }

    /** محتوى أول قاعدة CSS بمحدِّد مطابق تمامًا (بلا تداخل أقواس)؛ نص فارغ إن لم توجد. */
    function e23Rule(string $css, string $selector): string
    {
        return preg_match('/(?:^|\n)[ \t]*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/u', $css, $m) ? $m[1] : '';
    }

    function e23Norm(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** مساحة عمل Blade/JS كنص (مسار نسبي لـresources). */
    function e23Source(string $relative): string
    {
        return file_get_contents(resource_path($relative));
    }

    /** النص المرئي للصفحة فقط: بلا script/style/svg/template ولا سمات (لا مطابقة أرقام داخل روابط ULID). */
    function e23VisibleText(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        foreach (['script', 'style', 'svg', 'noscript', 'template'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $parts = [];

        foreach ((new DOMXPath($dom))->query('//text()') as $node) {
            $parts[] = $node->nodeValue;
        }

        return e23Norm(implode(' ', $parts));
    }

    /** @return list<string> مشكلات الوصولية الأساسية للصفحة (فارغة = سليمة). */
    function e23A11yProblems(string $html): array
    {
        $x = uiXpath($html);
        $problems = [];

        if ($x->query('/html[@lang="ar" and @dir="rtl"]')->length !== 1) {
            $problems[] = 'html lang/dir';
        }

        if ($x->query('//main[@id="main-content"]')->length !== 1) {
            $problems[] = 'main#main-content';
        }

        $first = $x->query('(//a | //button | //input | //select | //textarea)[1]')->item(0);

        if (! $first || $first->getAttribute('href') !== '#main-content') {
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

    /** صديقان مع رسالة غير مقروءة للمشاهد ($viewer) من $friend. @return array{0: \App\Models\User, 1: \App\Models\User} */
    function e23FriendsWithUnread($test): array
    {
        $viewer = e16User(['name' => 'مشاهد']);
        $friend = e16User(['name' => 'صديق']);
        e16Befriend($viewer, $friend);
        chatDm($test, $friend, $viewer, 'رسالة غير مقروءة');

        return [$viewer, $friend];
    }
}
