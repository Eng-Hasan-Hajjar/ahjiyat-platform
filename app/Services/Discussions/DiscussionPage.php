<?php

namespace App\Services\Discussions;

use Illuminate\Http\Request;

/**
 * E36 - غلاف قسم النقاش المضمَّن في الصفحات العامة الثلاث (أحجية مجتمع / سلسلة / مجموعة عامة).
 *
 * **عزل الفشل**: أي استثناء في قراءة النقاش يُسجَّل ويُعاد null فتُرسَم الصفحة العامة كاملة بدونه (النقاش ميزة إضافية لا تُسقط صفحة المحتوى).
 * null أيضًا حين تُعطَّل الميزة (DISCUSSIONS_ENABLED=false) أو لا موضوع (مجموعة غير عامة). لا كتابة هنا إطلاقًا (GET فقط).
 */
class DiscussionPage
{
    public function __construct(protected DiscussionReader $reader) {}

    /** @return array<string, mixed>|null */
    public function for(Request $request, ?DiscussionSubject $subject): ?array
    {
        if ($subject === null || ! (bool) config('discussions.enabled', true)) {
            return null;
        }

        try {
            return $this->reader->forSubject(
                $subject,
                $request->user(),
                is_string($request->query('dsort')) ? $request->query('dsort') : null,
                max(1, (int) $request->query('dpage', 1)),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
