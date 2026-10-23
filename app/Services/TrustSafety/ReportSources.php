<?php

namespace App\Services\TrustSafety;

use App\Models\ChatMessageReport;
use App\Models\CommunityPuzzleReport;
use App\Models\DiscussionCommentReport;
use App\Models\FraudFlag;
use App\Models\ModerationCaseSource;
use App\Models\UserCollectionReport;

/**
 * E38 - سجل مغلق لمصادر الصندوق الموحَّد (قائمة بيضاء). **لا اسم فئة ولا جدول ولا عمود يأتي من العميل أبدًا**: كل مرجع بلاغ يمرّ من هنا بمفتاح نصي مغلق.
 * كل مصدر يصف: نموذجه وجدوله ومفتاح موضوعه وأعمدة المبلِّغ/السبب/الحالة (SQL ثابتة هنا لا غير) لتوحيدها بـUNION ALL دون نسخ شيء.
 */
final class ReportSources
{
    /** @return array<string, array<string,mixed>> */
    public static function all(): array
    {
        return [
            ModerationCaseSource::TYPE_PUZZLE_REPORT => [
                'model' => CommunityPuzzleReport::class, 'table' => 'community_puzzle_reports', 'label' => 'بلاغ عن أحجية مجتمع',
                'subject_type' => 'community_puzzle', 'subject_col' => 'creator_puzzle_id', 'reporter_col' => 'reporter_id',
                'reason_col' => 'reason', 'status_expr' => 'r.status', 'severity_expr' => 'NULL', 'system' => false,
            ],
            ModerationCaseSource::TYPE_COLLECTION_REPORT => [
                'model' => UserCollectionReport::class, 'table' => 'user_collection_reports', 'label' => 'بلاغ عن مجموعة',
                'subject_type' => 'user_collection', 'subject_col' => 'user_collection_id', 'reporter_col' => 'reporter_id',
                'reason_col' => 'reason', 'status_expr' => 'r.status', 'severity_expr' => 'NULL', 'system' => false,
            ],
            ModerationCaseSource::TYPE_DISCUSSION_REPORT => [
                'model' => DiscussionCommentReport::class, 'table' => 'discussion_comment_reports', 'label' => 'بلاغ عن تعليق',
                'subject_type' => 'discussion_comment', 'subject_col' => 'discussion_comment_id', 'reporter_col' => 'reporter_id',
                'reason_col' => 'reason', 'status_expr' => 'r.status', 'severity_expr' => 'NULL', 'system' => false,
            ],
            ModerationCaseSource::TYPE_CHAT_REPORT => [
                'model' => ChatMessageReport::class, 'table' => 'chat_message_reports', 'label' => 'بلاغ عن رسالة دردشة',
                'subject_type' => 'chat_message', 'subject_col' => 'chat_message_id', 'reporter_col' => 'reporter_id',
                'reason_col' => 'category', 'status_expr' => "CASE WHEN r.status = 'pending' THEN 'open' ELSE r.status END", 'severity_expr' => 'NULL', 'system' => false,
            ],
            ModerationCaseSource::TYPE_FRAUD_FLAG => [
                'model' => FraudFlag::class, 'table' => 'fraud_flags', 'label' => 'إشارة نظام (احتيال محتمل)',
                'subject_type' => 'user', 'subject_col' => 'user_id', 'reporter_col' => null,
                'reason_col' => 'reason', 'status_expr' => "CASE WHEN r.resolved = 1 THEN 'resolved' ELSE 'open' END", 'severity_expr' => 'r.severity', 'system' => true,
            ],
        ];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return ModerationCaseSource::TYPES;
    }

    public static function has(?string $type): bool
    {
        return is_string($type) && in_array($type, ModerationCaseSource::TYPES, true);
    }

    /** @return array<string,mixed> */
    public static function def(string $type): array
    {
        $all = self::all();

        if (! isset($all[$type])) {
            throw new ModerationException('نوع مصدر غير مسموح.');
        }

        return $all[$type];
    }

    public static function label(string $type): string
    {
        return (string) (self::all()[$type]['label'] ?? $type);
    }

    public static function isSystemSignal(string $type): bool
    {
        return (bool) (self::all()[$type]['system'] ?? false);
    }

    /** مفتاح الإغلاق النصي لحالة المصدر المفتوح حسب النوع (القيم المقارنة بـ"open" بعد التوحيد). */
    public static function isOpen(object $report, string $type): bool
    {
        return match ($type) {
            ModerationCaseSource::TYPE_CHAT_REPORT => $report->status === ChatMessageReport::STATUS_PENDING,
            ModerationCaseSource::TYPE_FRAUD_FLAG => ! $report->resolved,
            default => $report->status === 'open',
        };
    }

    public static function statusLabel(string $normalized): string
    {
        return [
            'open' => 'مفتوح', 'dismissed' => 'مرفوض', 'actioned' => 'عولج بإجراء', 'reviewed' => 'تمت مراجعته', 'resolved' => 'محلول',
        ][$normalized] ?? $normalized;
    }
}
