<?php

namespace App\Services\Discussions;

use App\Models\DiscussionComment;
use App\Models\DiscussionThread;

/**
 * E36 - سياق تعليق **ظاهر لهذا المشاهد**: التعليق، نقاشه، موضوعه (ظاهر الآن)، وجذره (هو نفسه إن كان جذرًا). لا يُنشأ إلا من DiscussionThreadService::contextFor
 * بعد التحقق من الموضوع والحظر (المسار الوحيد لأي فعل على تعليق: تعديل/حذف/إعجاب/بلاغ/رد/رابط ثابت).
 */
final class DiscussionContext
{
    public function __construct(
        public readonly DiscussionComment $comment,
        public readonly DiscussionThread $thread,
        public readonly DiscussionSubject $subject,
        public readonly DiscussionComment $root,
    ) {}
}
