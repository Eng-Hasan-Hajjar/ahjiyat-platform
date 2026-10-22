<?php

namespace App\Filament\Concerns;

use App\Models\DiscussionComment;
use App\Models\DiscussionCommentReport;
use App\Models\DiscussionThread;
use App\Services\Discussions\DiscussionSubjectRegistry;
use WeakMap;

/**
 * E36 - وصف موضوع النقاش (عنوان/رابط/إتاحة) لشاشات الإشراف **بدفعة واحدة لكل صفحة جدول** (لا N+1): أول طلب لصفحة السجلات يصف كل نقاشاتها بدفعة لكل نوع
 * (DiscussionSubjectRegistry::describeThreads) ويخزّن النتيجة مفتاحها كائن السجلات نفسه (WeakMap): تُحرَّر مع انتهاء الصفحة فلا تسريب بين طلبات/اختبارات.
 * الكيان الذي لم يعد عامًّا (مجموعة صارت خاصة، عمل مخفي) يظهر للمشرف بنص محايد بلا عنوان ولا رابط (خصوصية المالك).
 */
trait DescribesDiscussionSubjects
{
    /** @return array{title: string, url: ?string, available: bool} */
    protected static function subjectOf($livewire, ?DiscussionThread $thread): array
    {
        static $maps = null;
        $maps ??= new WeakMap;
        $unavailable = ['title' => '— (الكيان غير متاح للعامة)', 'url' => null, 'available' => false];

        if ($thread === null) {
            return $unavailable;
        }

        $records = $livewire->getTableRecords();

        if (! isset($maps[$records])) {
            $threads = collect($records)->map(function ($record) {
                if ($record instanceof DiscussionThread) {
                    return $record;
                }

                if ($record instanceof DiscussionComment) {
                    return $record->thread;
                }

                return $record instanceof DiscussionCommentReport ? $record->comment?->thread : null;
            })->filter()->unique('id')->values();

            $maps[$records] = app(DiscussionSubjectRegistry::class)->describeThreads($threads);
        }

        return $maps[$records][(int) $thread->id] ?? $unavailable;
    }
}
