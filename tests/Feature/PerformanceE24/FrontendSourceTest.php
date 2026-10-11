<?php

/**
 * E24 - حماية بنيوية للواجهة (فحص مصدر): اتصال Reverb لا يُفتح عند تحميل كل صفحة، بل عند أول اشتراك من غرفة دردشة.
 * قبل التعديل كان `new Echo(...)` على مستوى الوحدة فيفتح كل تبويب (حتى للزوار) اتصال WebSocket دائمًا.
 */
/** يزيل التعليقات كي لا يطابق الفحص نصًا داخل شرح. */
function e24_strip_js_comments(string $js): string
{
    return preg_replace(['#/\*.*?\*/#s', '#(?<!:)//[^\n]*#'], '', $js);
}

test('E24: the Reverb connection is created lazily - never at module load', function () {
    $echo = e24_strip_js_comments(file_get_contents(resource_path('js/echo.js')));

    $lazy = strpos($echo, 'window.getEcho');
    $create = strpos($echo, 'new Echo(');

    expect($lazy)->not->toBeFalse()
        ->and($create)->not->toBeFalse()
        ->and($create)->toBeGreaterThan($lazy)             // الإنشاء داخل getEcho
        ->and(substr_count($echo, 'new Echo('))->toBe(1)
        ->and($echo)->not->toMatch('/window\.Echo\s*=\s*new\s+Echo/');
});

test('E24: the chat room asks for the connection only when it has a channel to subscribe to', function () {
    $chat = e24_strip_js_comments(file_get_contents(resource_path('js/chat.js')));

    $guard = strpos($chat, '!this.cfg.channel || this.subscribedTo === this.cfg.channel');
    $connect = strpos($chat, 'window.getEcho()');

    expect($guard)->not->toBeFalse()
        ->and($connect)->not->toBeFalse()
        ->and($connect)->toBeGreaterThan($guard);           // لا اتصال قبل التحقق من وجود قناة
});
