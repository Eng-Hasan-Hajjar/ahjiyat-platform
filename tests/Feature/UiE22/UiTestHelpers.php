<?php

require_once __DIR__.'/../Chat/ChatTestHelpers.php';

/** مساعدات E22: تحليل HTML بـDOM/XPath (لا مطابقة نصوص هشّة) وأدوات صغيرة مشتركة. */
if (! function_exists('uiXpath')) {
    function uiXpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /** @return list<string> قيم سمة لعقد تطابق الاستعلام */
    function uiAttrs(DOMXPath $x, string $query, string $attr): array
    {
        $out = [];

        foreach ($x->query($query) as $node) {
            $out[] = $node->getAttribute($attr);
        }

        return $out;
    }

    /** عناصر تفاعلية بلا اسم متاح (لا نص مرئي ولا aria-label/labelledby ولا title). */
    function uiUnnamedControls(DOMXPath $x, string $scope): array
    {
        $bad = [];

        foreach ($x->query($scope.'//*[self::a or self::button]') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));

            if ($text === '' && $node->getAttribute('aria-label') === '' && $node->getAttribute('aria-labelledby') === '' && $node->getAttribute('title') === '') {
                $bad[] = $node->getAttribute('href') ?: $node->getAttribute('class');
            }
        }

        return $bad;
    }

    function uiPrimaryLabels(string $html): array
    {
        return uiAttrs(uiXpath($html), '//nav[@aria-label="التنقل الرئيسي"]/a', 'aria-label');
    }

    function uiView(string $relative): string
    {
        return file_get_contents(resource_path('views/'.$relative));
    }
}
