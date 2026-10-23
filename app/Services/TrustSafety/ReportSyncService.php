<?php

namespace App\Services\TrustSafety;

use App\Models\ChatMessageReport;
use App\Models\FraudFlag;
use App\Models\ModerationCaseSource;
use App\Models\User;
use App\Services\Chat\ChatReportService;
use App\Services\Collections\CollectionModerationService;
use App\Services\Creators\CommunityReportService;
use App\Services\Discussions\DiscussionModerationService;
use App\Services\FraudDetectionService;

/**
 * E38 - مزامنة حالة البلاغ المجالي عند حسم الحالة، **عبر خدمات المجال الرسمية فقط** (لا تحديث خام لجداول البلاغات أبدًا):
 *   E28 CommunityReportService، E35 CollectionModerationService، E36 DiscussionModerationService، E21 ChatReportService، E6 FraudDetectionService.
 * بلاغ حُسم مسبقًا (مثلًا أغلقه الإخفاء نفسه كمعالَج) يُترك كما هو: المزامنة idempotent.
 * ملاحظة الإغلاق ثابتة المحتوى (رقم الحالة فقط): لا نص حر ولا هوية تُسرَّب إلى حقول المجال.
 */
class ReportSyncService
{
    public function __construct(
        protected CommunityReportService $puzzles,
        protected CollectionModerationService $collections,
        protected DiscussionModerationService $discussions,
        protected ChatReportService $chat,
        protected FraudDetectionService $fraud,
    ) {}

    /** الصلاحية التي تفرضها خدمة المجال لحسم هذا النوع. */
    public function requiredPermission(string $sourceType): string
    {
        return match ($sourceType) {
            ModerationCaseSource::TYPE_PUZZLE_REPORT => 'creators.review',
            ModerationCaseSource::TYPE_COLLECTION_REPORT => 'collections.moderate',
            ModerationCaseSource::TYPE_DISCUSSION_REPORT => 'discussions.moderate',
            ModerationCaseSource::TYPE_CHAT_REPORT => 'chat.moderate',
            ModerationCaseSource::TYPE_FRAUD_FLAG => 'fraud.resolve',
            default => throw new ModerationException('نوع مصدر غير مسموح.'),
        };
    }

    public function canSync(User $actor, string $sourceType): bool
    {
        return $actor->can($this->requiredPermission($sourceType));
    }

    /** يحمّل البلاغ الأصلي من نموذجه المسجَّل (لا فئة من العميل). */
    public function load(ModerationCaseSource $source): ?object
    {
        $class = ReportSources::def($source->source_type)['model'];

        return $class::query()->find($source->source_id);
    }

    /**
     * يحسم البلاغ عبر خدمة مجاله. يعيد true إن أُغلق هنا، false إن كان مغلقًا مسبقًا أو محذوفًا.
     *
     * @param  bool  $actioned  true = «عولج بإجراء»، false = «مرفوض/لا مخالفة»
     */
    public function close(ModerationCaseSource $source, User $actor, bool $actioned, string $caseNumber): bool
    {
        $report = $this->load($source);

        if ($report === null || ! ReportSources::isOpen($report, $source->source_type)) {
            return false;
        }

        $note = 'حالة إشراف '.$caseNumber;

        match ($source->source_type) {
            ModerationCaseSource::TYPE_PUZZLE_REPORT => $actioned ? $this->puzzles->action($report, $actor, $note) : $this->puzzles->dismiss($report, $actor, $note),
            ModerationCaseSource::TYPE_COLLECTION_REPORT => $actioned ? $this->collections->action($report, $actor, $note) : $this->collections->dismiss($report, $actor, $note),
            ModerationCaseSource::TYPE_DISCUSSION_REPORT => $actioned ? $this->discussions->action($report, $actor, $note) : $this->discussions->dismiss($report, $actor, $note),
            ModerationCaseSource::TYPE_CHAT_REPORT => $this->chat->review($actor, $report, $actioned ? ChatMessageReport::STATUS_ACTIONED : ChatMessageReport::STATUS_DISMISSED),
            // علامة الاحتيال تُغلق كعلامة فقط: لا أثر على الحساب (قرار E6 الثابت).
            ModerationCaseSource::TYPE_FRAUD_FLAG => $report instanceof FraudFlag ? $this->fraud->resolve($report, $actor, $note) : null,
            default => throw new ModerationException('نوع مصدر غير مسموح.'),
        };

        return true;
    }
}
