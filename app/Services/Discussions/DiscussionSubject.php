<?php

namespace App\Services\Discussions;

/**
 * E36 - وصف موضوع نقاش **ظاهر الآن** كما حلّه DiscussionSubjectRegistry: نوعه، معرّفه الداخلي والعام، مالك المحتوى (user id)، عنوان آمن (نص عادي مهرَّب عند العرض)،
 * ورابط الصفحة العامة القانوني (مسار مسمّى فقط). غير قابل للتعديل: يُمرَّر بين الخدمات دون إعادة حلّ.
 */
final class DiscussionSubject
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $ownerId,
        public readonly string $title,
        public readonly string $url,
    ) {}
}
