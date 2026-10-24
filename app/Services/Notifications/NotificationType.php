<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق لأنواع الإشعارات المنفَّذة فعليًا (4). كل نوع يحدد فئته وأولويته وأيقونته ووجهته الداخلية
 * الوحيدة المسموحة ونصوصه - لا يأتي أي منها من الحدث أو من الإدارة أو من قاعدة البيانات.
 *
 * مصدر كل نوع (Event source → type):
 *  - AchievementUnlocked (حدث مجال جديد، يُطلَق بعد commit فتح الإنجاز) → achievement_unlocked
 *  - جدولة ساعية (ReEngagementService) على player_streaks → streak_at_risk
 *  - جدولة ساعية على player_streaks + مهام يومية قائمة → daily_quests_available
 *  - Illuminate\Auth\Events\PasswordReset (حدث موجود أصلًا) → security_password_reset
 *  - SeasonStarted (حدث مجال، SeasonLifecycleService: الموسم صار مباشرًا لأول مرة) → season_started
 *  - CampaignBecameAvailable (حدث مجال، CampaignLifecycleService: الحملة أُتيحت لأول مرة) → campaign_available
 *  - StageUnlockedForUser (حدث مجال لكل مستخدم، StageUnlockService بعد إكمال خطوة حقيقية) → stage_unlocked
 *  - CampaignCompletedForUser (حدث مجال لكل مستخدم، CampaignCompletionService بعد إكمال خطوة حقيقية) → campaign_completed،
 *    أو season_completed إن كانت الحملة مرتبطة بموسم منشور (القرار داخل المستمع: إشعار واحد فقط للإكمال نفسه)
 *  - LifecycleReminderService (مجدول ساعيًا، قراءة فقط) → season_ending_soon / campaign_ending_soon
 *  - FriendChallenge{Created,Accepted,Completed} (أحداث مجال بعد commit، FriendChallengeService) → friend_challenge_received/accepted/result_ready
 *  - CompetitiveRewardGranted (بعد منح فعلي ناجح بخدمة التوزيع) → competitive_reward_granted
 *  - TeamChallengeCreated/Accepted/Completed, TeamChampionshipStarted (الأمر الدوري)/Finalized (E20) → team_challenge_*, team_championship_* (التحدّيات: social؛ البطولات: competitive)
 *  - TeamInvitationCreated/Accepted, TeamJoinRequestCreated/Accepted, TeamMemberRemoved (أحداث مجال بعد commit بخدمات الفرق، E19) → team_* (فئة social؛ بلا إشعار للرفض)
 *  - CompetitiveLifecycleService (مجدول ساعيًا) → competitive_event_started/ending_soon؛ CompetitiveEventFinalized → competitive_event_result_ready
 *  - FriendRequestCreated / FriendshipAccepted (أحداث مجال بعد commit، FriendshipService) → friend_request_received / friend_request_accepted
 *  - اعتماد نشر (أول نشر أو نسخة جديدة) لمبدع له متابعون (E29، CreatorPublicationAnnouncer بعد الـcommit ← دفعات بالطابور) → creator_new_publication (فئة social؛ بلا إشعار على التقييم/المفضلة/اللعب)
 *  - E30 رعاية: ContributionService بعد الـcommit → support_confirmed / supporter_tier_changed (للداعم المرتبط بحساب فقط، بلا مبالغ ولا مراجع خاصة)؛ SponsorshipProgramService عند التفعيل → sponsorship_program_started (لداعمي البرنامج فقط)
 *  - E32 دفع الرعاية: SponsorshipPaymentService بعد الـcommit → support_request_received / support_action_required / support_rejected / support_refunded (لصاحب الطلب فقط؛ بلا مبالغ ولا مراجع تحويل ولا بيانات بنكية بالنص). تأكيد الدعم يستعمل support_confirmed القائم (لا إشعار تحقق مكرر)
 *  - E30 سلاسل: CreatorSeriesReviewService بعد الـcommit → series_approved / series_changes_requested / series_rejected / series_hidden (للمبدع)؛ اعتماد سلسلة لمبدع له متابعون (CreatorSeriesAnnouncer ← دفعات بالطابور) → creator_new_series (social)
 *  - E33 إحالات: ReferralNotifier بعد الـcommit → referral_qualified (للمحيل حين تتأهل إحالته؛ بلا اسم المُحال ولا بياناته) / referral_reward_received (لصاحب الجائزة فقط: مبلغه هو، عبر محفظة الجواهر المعلّقة). لا إشعار على نقرة ولا على تسجيل وحده
 *  - E34 LiveOps: LiveOpsReminderService (مجدول ساعيًا، قراءة فقط من حالة اللعب) → mission_expiring / weekly_missions_available / event_starting_soon / comeback_available.
 *    فئات قائمة فقط (Quest للمهام والعودة، Competitive لبدء المنافسة) فتخضع لتفضيلات المستخدم القائمة؛ **ليست** تذكيرات استعادية (لا تدخل ميزانية E15 ولا قائمتها)
 *    وبسقف يومي خاص بها يحترم ميزانية E15 أيضًا. بلا لغة ضغط ولا عقوبة ولا مكافأة مشروطة بالعودة.
 *  - E36 نقاشات: DiscussionNotifier بعد الـcommit → comment_reply_received (لكاتب التعليق الجذري حين يُردّ عليه؛ إشعار واحد كحدّ أقصى لكل جذر في نافذة) /
 *    new_comment_on_your_content (لمالك المحتوى بتعليق جديد؛ إشعار واحد كحدّ أقصى لكل نقاش في نافذة) / comment_hidden (لكاتب التعليق عند إخفائه إشرافيًا)، فئة social.
 *    لا إشعار على إعجاب ولا بلاغ ولا قفل، ولا ذاتي، ولا اسم للمعلِّق (النص لا يحمل هوية)، وفشله لا يمسّ التعليق.
 *  - E38 الثقة والسلامة: ModerationNotifier بعد الـcommit → moderation_notice (للمستخدم المعني بإجراء إشرافي: تنبيه/إخفاء/تعليق/تجميد؛ النص ثابت بلا اسم مشرف ولا مبلِّغ ولا تفاصيل داخلية)
 *    / moderation_appeal_decided (لصاحب الاعتراض عند القرار). فئة security الإلزامية (إشعارات سياسة لا تُغلق)، ووجهتها صفحة «الأمان والإشراف» فقط. **لا إشعار للمبلِّغ** (قرار موثَّق).
 *  - E40 مسار الأحجيات: PassNotifier بعد الـcommit → pass_premium_unlocked (لصاحب الشراء عند تحقق الدفع) / pass_purchase_rejected (عند رفض طلب الشراء) /
 *    pass_claim_window_closing (مرة واحدة لكل لاعب/مسار، فقط لمن لديه مكافآت قابلة للاستلام قبل إغلاق المهلة بساعات محددة). فئة social الاختيارية.
 *    لا إشعار على كل نقطة ولا مهمة ولا شريحة ولا على بدء المسار (لا بث جماعي)، ولا لغة ضغط أو ندرة. النص بلا مبالغ ولا مراجع تحويل.
 *  - E41 التجارة: CommerceNotifier بعد الـcommit → commerce_failed (فشل/إلغاء الدفع عند المزوّد؛ الطلب يبقى قابلًا لإعادة المحاولة) / commerce_action_required (دفعتك تحتاج مراجعة مالية قبل التفعيل)
 *    / commerce_refunded (سُجّل استردادك). فئة social الاختيارية. «تم التفعيل» يستعمل pass_premium_unlocked الموجود. بلا مبالغ ولا مراجع مزوّد ولا ملاحظات داخلية، ومفاتيح idempotency دلالية فلا تكرار مع webhook مكرَّر.
 *  - E35 مجموعات: CollectionModerationService بعد الـcommit → collection_hidden / collection_restored (لمالك المجموعة فقط، فئة social). لا إشعار على نشر/حفظ/بلاغ/ترتيب/رف: لا لحافظ ولا لصاحب مجموعة ولا للمبلِّغ.
 */
enum NotificationType: string
{
    case AchievementUnlocked = 'achievement_unlocked';
    case StreakAtRisk = 'streak_at_risk';
    case DailyQuestsAvailable = 'daily_quests_available';
    case SecurityPasswordReset = 'security_password_reset';
    case SeasonStarted = 'season_started';
    case CampaignAvailable = 'campaign_available';
    case StageUnlocked = 'stage_unlocked';
    case CampaignCompleted = 'campaign_completed';
    case SeasonCompleted = 'season_completed';
    case SeasonEndingSoon = 'season_ending_soon';
    case CampaignEndingSoon = 'campaign_ending_soon';
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case FriendChallengeReceived = 'friend_challenge_received';
    case FriendChallengeAccepted = 'friend_challenge_accepted';
    case FriendChallengeResultReady = 'friend_challenge_result_ready';
    case CompetitiveEventStarted = 'competitive_event_started';
    case CompetitiveEventEndingSoon = 'competitive_event_ending_soon';
    case CompetitiveEventResultReady = 'competitive_event_result_ready';
    case CompetitiveRewardGranted = 'competitive_reward_granted';
    case TeamInvitationReceived = 'team_invitation_received';
    case TeamInvitationAccepted = 'team_invitation_accepted';
    case TeamJoinRequestReceived = 'team_join_request_received';
    case TeamJoinRequestAccepted = 'team_join_request_accepted';
    case TeamMemberRemoved = 'team_member_removed';
    case TeamChallengeReceived = 'team_challenge_received';
    case TeamChallengeAccepted = 'team_challenge_accepted';
    case TeamChallengeResultReady = 'team_challenge_result_ready';
    case TeamChampionshipStarted = 'team_championship_started';
    case TeamChampionshipResultReady = 'team_championship_result_ready';
    case CreatorApproved = 'creator_approved';
    case CreatorRejected = 'creator_rejected';
    case SubmissionApproved = 'submission_approved';
    case SubmissionChangesRequested = 'submission_changes_requested';
    case SubmissionRejected = 'submission_rejected';
    case PublicationHidden = 'publication_hidden';
    case CreatorNewPublication = 'creator_new_publication';
    case SupportConfirmed = 'support_confirmed';
    case SupporterTierChanged = 'supporter_tier_changed';
    case SponsorshipProgramStarted = 'sponsorship_program_started';
    case SeriesApproved = 'series_approved';
    case SeriesChangesRequested = 'series_changes_requested';
    case SeriesRejected = 'series_rejected';
    case SeriesHidden = 'series_hidden';
    case CreatorNewSeries = 'creator_new_series';
    case SupportRequestReceived = 'support_request_received';
    case SupportActionRequired = 'support_action_required';
    case SupportRejected = 'support_rejected';
    case SupportRefunded = 'support_refunded';
    case ReferralQualified = 'referral_qualified';
    case ReferralRewardReceived = 'referral_reward_received';
    case MissionExpiring = 'mission_expiring';
    case WeeklyMissionsAvailable = 'weekly_missions_available';
    case EventStartingSoon = 'event_starting_soon';
    case ComebackAvailable = 'comeback_available';
    case CollectionHidden = 'collection_hidden';
    case CollectionRestored = 'collection_restored';
    case CommentReplyReceived = 'comment_reply_received';
    case NewCommentOnYourContent = 'new_comment_on_your_content';
    case CommentHidden = 'comment_hidden';
    case FeaturedCreatorSelected = 'featured_creator_selected';
    case ModerationNotice = 'moderation_notice';
    case ModerationAppealDecided = 'moderation_appeal_decided';
    case CreatorStatementReady = 'creator_statement_ready';
    case CreatorPayoutApproved = 'creator_payout_approved';
    case CreatorPayoutPaid = 'creator_payout_paid';
    case CreatorPayoutRejected = 'creator_payout_rejected';
    case PassPremiumUnlocked = 'pass_premium_unlocked';
    case PassPurchaseRejected = 'pass_purchase_rejected';
    case PassClaimWindowClosing = 'pass_claim_window_closing';
    case CommerceFailed = 'commerce_failed';
    case CommerceActionRequired = 'commerce_action_required';
    case CommerceRefunded = 'commerce_refunded';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable, self::MissionExpiring, self::WeeklyMissionsAvailable, self::ComebackAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset, self::ModerationNotice, self::ModerationAppealDecided => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
            self::StageUnlocked => NotificationCategory::Campaign,
            self::CampaignCompleted => NotificationCategory::Campaign,
            self::SeasonCompleted, self::SeasonEndingSoon => NotificationCategory::Season,
            self::CampaignEndingSoon => NotificationCategory::Campaign,
            self::FriendRequestReceived, self::FriendRequestAccepted, self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady,
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved,
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady,
            self::CreatorApproved, self::CreatorRejected, self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden, self::CreatorNewPublication,
            self::SupportConfirmed, self::SupporterTierChanged, self::SponsorshipProgramStarted,
            self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded,
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden, self::CreatorNewSeries,
            self::CollectionHidden, self::CollectionRestored,
            self::CommentReplyReceived, self::NewCommentOnYourContent, self::CommentHidden, self::FeaturedCreatorSelected,
            self::CreatorStatementReady, self::CreatorPayoutApproved, self::CreatorPayoutPaid, self::CreatorPayoutRejected,
            self::PassPremiumUnlocked, self::PassPurchaseRejected, self::PassClaimWindowClosing,
            self::CommerceFailed, self::CommerceActionRequired, self::CommerceRefunded,
            self::ReferralQualified, self::ReferralRewardReceived => NotificationCategory::Social,
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted,
            self::EventStartingSoon,
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => NotificationCategory::Competitive,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable, self::SeasonEndingSoon, self::CampaignEndingSoon, self::CompetitiveEventEndingSoon], true);
    }

    /** أعلى = أهم. اختيار الأولوية يتم بتقسيم الجمهور + الميزانية (راجع ReEngagementService). */
    public function priority(): int
    {
        return match ($this) {
            self::SecurityPasswordReset => 1000,
            self::ModerationNotice => 900,
            self::ModerationAppealDecided => 890,
            self::CreatorPayoutRejected => 60,
            self::CreatorPayoutApproved, self::CreatorPayoutPaid => 58,
            self::CreatorStatementReady => 46,
            self::PassPurchaseRejected => 59,
            self::PassPremiumUnlocked => 57,
            self::PassClaimWindowClosing => 45,
            self::CommerceActionRequired => 61,
            self::CommerceFailed => 56,
            self::CommerceRefunded => 55,
            self::StreakAtRisk => 100,
            self::DailyQuestsAvailable => 50,
            self::SeasonStarted => 20,
            self::CampaignAvailable => 15,
            self::StageUnlocked => 14,
            self::CampaignCompleted => 16,
            self::SeasonCompleted => 17,
            self::SeasonEndingSoon, self::CampaignEndingSoon => 70, // بين تحذير السلسلة (100) وتذكير المهام (50)
            self::FriendRequestReceived => 25,
            self::FriendRequestAccepted => 24,
            self::FriendChallengeReceived => 26,
            self::FriendChallengeAccepted => 23,
            self::FriendChallengeResultReady => 22,
            self::CompetitiveEventStarted => 21,
            self::CompetitiveEventEndingSoon => 70, // مع تذكيري النهاية الآخرين: بين تحذير السلسلة وتذكير المهام
            self::CompetitiveEventResultReady => 20,
            self::CompetitiveRewardGranted => 19,
            self::TeamInvitationReceived => 28,
            self::TeamJoinRequestReceived => 29,
            self::TeamInvitationAccepted => 13,
            self::TeamJoinRequestAccepted => 12,
            self::TeamMemberRemoved => 11,
            self::TeamChallengeReceived => 30,
            self::TeamChallengeAccepted => 18,
            self::TeamChallengeResultReady => 9,
            self::TeamChampionshipStarted => 8,
            self::TeamChampionshipResultReady => 7,
            self::AchievementUnlocked => 10,
            self::CreatorApproved => 6,
            self::CreatorRejected => 5,
            self::SubmissionApproved => 4,
            self::SubmissionChangesRequested => 3,
            self::SubmissionRejected => 2,
            self::PublicationHidden => 1,
            self::CreatorNewPublication => 27,
            self::SupportConfirmed => 31,
            self::SupporterTierChanged => 32,
            self::SponsorshipProgramStarted => 33,
            self::SeriesApproved => 34,
            self::SeriesChangesRequested => 35,
            self::SeriesRejected => 36,
            self::SeriesHidden => 37,
            self::CreatorNewSeries => 38,
            self::SupportRequestReceived => 39,
            self::SupportActionRequired => 40,
            self::SupportRejected => 41,
            self::SupportRefunded => 42,
            self::ReferralQualified => 43,
            self::ReferralRewardReceived => 44,
            self::MissionExpiring => 45,
            self::WeeklyMissionsAvailable => 46,
            self::EventStartingSoon => 47,
            self::ComebackAvailable => 48,
            self::CollectionHidden => 49,
            self::CollectionRestored => 50,
            self::CommentReplyReceived => 51,
            self::NewCommentOnYourContent => 52,
            self::CommentHidden => 53,
            self::FeaturedCreatorSelected => 54,
        };
    }

    /** أيقونة من سجل مغلق (رموز نصية) - لا أيقونة تأتي من قاعدة البيانات. */
    public function icon(): string
    {
        return match ($this) {
            self::AchievementUnlocked => '🏆',
            self::StreakAtRisk => '🔥',
            self::DailyQuestsAvailable => '🎯',
            self::SecurityPasswordReset => '🛡️',
            self::SeasonStarted => '🏁',
            self::CampaignAvailable => '🧭',
            self::StageUnlocked => '🔓',
            self::CampaignCompleted => '✅',
            self::SeasonCompleted => '🏅',
            self::SeasonEndingSoon, self::CampaignEndingSoon => '⏳',
            self::FriendRequestReceived, self::FriendRequestAccepted => '🤝',
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => '⚔️',
            self::CompetitiveEventStarted, self::CompetitiveEventResultReady => '🏆',
            self::CompetitiveRewardGranted => '🎁',
            self::TeamInvitationReceived, self::TeamInvitationAccepted, self::TeamJoinRequestReceived, self::TeamJoinRequestAccepted, self::TeamMemberRemoved => '👥',
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => '⚔️',
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => '🏆',
            self::CompetitiveEventEndingSoon => '⏳',
            self::CreatorApproved => '🎨',
            self::CreatorRejected => '📝',
            self::SubmissionApproved => '✅',
            self::SubmissionChangesRequested => '✍️',
            self::SubmissionRejected => '📝',
            self::PublicationHidden => '🚫',
            self::CreatorNewPublication => '🆕',
            self::SupportConfirmed => '💎',
            self::SupporterTierChanged => '🏅',
            self::SponsorshipProgramStarted => '📣',
            self::SeriesApproved => '📚',
            self::SeriesChangesRequested => '✍️',
            self::SeriesRejected => '📝',
            self::SeriesHidden => '🚫',
            self::CreatorNewSeries => '📚',
            self::SupportRequestReceived => '🧾',
            self::SupportActionRequired => '📎',
            self::SupportRejected => '⚠️',
            self::SupportRefunded => '↩️',
            self::ReferralQualified => '🤝',
            self::ReferralRewardReceived => '💎',
            self::MissionExpiring => '🎯',
            self::WeeklyMissionsAvailable => '🗓️',
            self::EventStartingSoon => '⏰',
            self::ComebackAvailable => '👋',
            self::CollectionHidden => '🚫',
            self::CollectionRestored => '✅',
            self::CommentReplyReceived => '💬',
            self::NewCommentOnYourContent => '🗨️',
            self::CommentHidden => '🚫',
            self::FeaturedCreatorSelected => '⭐',
            self::ModerationNotice => '🛡️',
            self::ModerationAppealDecided => '⚖️',
            self::CreatorStatementReady => '🧾',
            self::CreatorPayoutApproved => '✅',
            self::CreatorPayoutPaid => '💸',
            self::CreatorPayoutRejected => '↩️',
            self::PassPremiumUnlocked => '✨',
            self::PassPurchaseRejected => '⚠️',
            self::PassClaimWindowClosing => '⏳',
            self::CommerceFailed => '⚠️',
            self::CommerceActionRequired => '🔎',
            self::CommerceRefunded => '↩️',
        };
    }

    /** أسماء المسارات الداخلية الوحيدة المسموحة لهذا النوع (الأولى = الافتراضية). لا URL خارجي أبدًا. */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::AchievementUnlocked => ['progress.show'],
            self::StreakAtRisk => ['puzzles.index'],
            self::DailyQuestsAvailable => ['quests.show'],
            self::MissionExpiring, self::WeeklyMissionsAvailable, self::ComebackAvailable => ['missions.index'],
            self::SecurityPasswordReset => ['profile.edit'],
            self::SeasonStarted => ['seasons.show'],
            self::CampaignAvailable => ['campaigns.show'],
            self::StageUnlocked => ['campaigns.show', 'seasons.show'],
            self::CampaignCompleted => ['campaigns.show', 'seasons.show'],
            self::SeasonCompleted, self::SeasonEndingSoon => ['seasons.show'],
            self::CampaignEndingSoon => ['campaigns.show'],
            self::FriendRequestReceived, self::FriendRequestAccepted => ['friends.index'],
            self::FriendChallengeReceived, self::FriendChallengeAccepted, self::FriendChallengeResultReady => ['friends.challenges.show'],
            self::CompetitiveEventStarted, self::CompetitiveEventEndingSoon, self::CompetitiveEventResultReady, self::CompetitiveRewardGranted, self::EventStartingSoon => ['competitions.show'],
            self::TeamInvitationReceived => ['teams.invitations'],
            self::TeamInvitationAccepted, self::TeamJoinRequestReceived => ['teams.manage', 'teams.show'],
            self::TeamJoinRequestAccepted => ['teams.show'],
            self::TeamMemberRemoved => ['teams.index'],
            self::TeamChallengeReceived, self::TeamChallengeAccepted, self::TeamChallengeResultReady => ['teams.challenges.show'],
            self::TeamChampionshipStarted, self::TeamChampionshipResultReady => ['team-championships.show'],
            self::CreatorApproved => ['creator.dashboard'],
            self::CreatorRejected => ['creator.apply'],
            self::SubmissionApproved, self::SubmissionChangesRequested, self::SubmissionRejected, self::PublicationHidden => ['creator.puzzles.index'],
            self::CreatorNewPublication => ['community.puzzles.show'],
            self::SupportConfirmed, self::SupporterTierChanged => ['supporters.index', 'supporters.show'],
            self::SponsorshipProgramStarted => ['supporters.index'],
            self::SeriesApproved, self::SeriesChangesRequested, self::SeriesRejected, self::SeriesHidden => ['creator.series.index'],
            self::CreatorNewSeries => ['series.show'],
            self::SupportRequestReceived, self::SupportActionRequired, self::SupportRejected, self::SupportRefunded => ['support.payments.show', 'support.history'],
            self::ReferralQualified, self::ReferralRewardReceived => ['referrals.index'],
            self::CollectionHidden, self::CollectionRestored => ['collections.index', 'collections.show'],
            self::CommentReplyReceived, self::NewCommentOnYourContent, self::CommentHidden => ['discussions.permalink'],
            self::FeaturedCreatorSelected => ['creators.show'],
            self::ModerationNotice, self::ModerationAppealDecided => ['account.safety.index'],
            self::CreatorStatementReady, self::CreatorPayoutApproved, self::CreatorPayoutPaid, self::CreatorPayoutRejected => ['creator.earnings'],
            self::PassPremiumUnlocked, self::PassPurchaseRejected, self::PassClaimWindowClosing => ['pass.index'],
            self::CommerceFailed, self::CommerceActionRequired, self::CommerceRefunded => ['commerce.purchases.index'],
        };
    }

    public function defaultRoute(): string
    {
        return $this->allowedRoutes()[0];
    }

    public function title(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'إنجاز جديد: '.($params['name'] ?? ''),
            self::StreakAtRisk => 'حافظ على سلسلتك اليومية',
            self::DailyQuestsAvailable => 'مهام اليوم جاهزة',
            self::SecurityPasswordReset => 'أُعيد تعيين كلمة مرور حسابك',
            self::SeasonStarted => 'بدأ موسم: '.($params['name'] ?? ''),
            self::CampaignAvailable => 'حملة جديدة متاحة: '.($params['name'] ?? ''),
            self::StageUnlocked => 'تم فتح مرحلة جديدة لك: '.($params['stage'] ?? ''),
            self::CampaignCompleted => 'أكملت الحملة بنجاح: '.($params['campaign'] ?? ''),
            self::SeasonCompleted => 'أكملت الموسم بنجاح: '.($params['season'] ?? ''),
            self::SeasonEndingSoon => 'ينتهي الموسم قريبًا: '.($params['name'] ?? ''),
            self::CampaignEndingSoon => 'تنتهي الحملة قريبًا: '.($params['name'] ?? ''),
            self::FriendRequestReceived => 'أرسل لك '.($params['name'] ?? '').' طلب صداقة',
            self::FriendRequestAccepted => 'قبل '.($params['name'] ?? '').' طلب صداقتك',
            self::FriendChallengeReceived => 'تحدّاك '.($params['name'] ?? '').' في أحجية',
            self::FriendChallengeAccepted => 'قبل '.($params['name'] ?? '').' تحدّيك',
            self::FriendChallengeResultReady => 'نتيجة التحدي جاهزة',
            self::CompetitiveEventStarted => 'بدأت المنافسة: '.($params['title'] ?? ''),
            self::CompetitiveEventEndingSoon => 'تنتهي المنافسة قريبًا: '.($params['title'] ?? ''),
            self::CompetitiveEventResultReady => 'نتائج المنافسة جاهزة: '.($params['title'] ?? ''),
            self::CompetitiveRewardGranted => 'حصلت على جائزة '.($params['placement'] ?? '').' في '.($params['title'] ?? ''),
            self::TeamInvitationReceived => 'دعاك '.($params['name'] ?? '').' للانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamInvitationAccepted => 'انضم '.($params['name'] ?? '').' إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestReceived => 'طلب '.($params['name'] ?? '').' الانضمام إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamJoinRequestAccepted => 'قُبل طلب انضمامك إلى فريق «'.($params['team'] ?? '').'»',
            self::TeamMemberRemoved => 'لم تعد عضوًا في فريق «'.($params['team'] ?? '').'»',
            self::TeamChallengeReceived => 'تحدّاكم فريق «'.($params['team'] ?? '').'» بمباراة',
            self::TeamChallengeAccepted => 'قَبِل فريق «'.($params['team'] ?? '').'» تحدّيكم',
            self::TeamChallengeResultReady => 'نتيجة مباراتكم ضد «'.($params['team'] ?? '').'» جاهزة',
            self::TeamChampionshipStarted => 'بدأت بطولة الفرق: '.($params['title'] ?? ''),
            self::TeamChampionshipResultReady => 'اعتُمدت نتائج بطولة الفرق: '.($params['title'] ?? ''),
            self::CreatorApproved => 'تمت الموافقة على انضمامك كمبدع',
            self::CreatorRejected => 'لم يُقبل طلب انضمامك كمبدع',
            self::SubmissionApproved => 'اعتُمدت أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionChangesRequested => 'طُلبت تعديلات على أحجيتك: '.($params['title'] ?? ''),
            self::SubmissionRejected => 'لم تُعتمد أحجيتك: '.($params['title'] ?? ''),
            self::PublicationHidden => 'أُخفيت أحجيتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' أحجيته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' أحجية جديدة: '.($params['title'] ?? ''),
            self::SupportConfirmed => 'تم تأكيد دعمك للمنصة، شكرًا لك',
            self::SupportRequestReceived => 'تم إنشاء طلب دعمك: أتمم التحويل وأرسل الإثبات',
            self::SupportActionRequired => 'طلب دعمك يحتاج إلى توضيح منك',
            self::SupportRejected => 'لم يُقبل طلب دعمك',
            self::SupportRefunded => 'سُجّل استرداد دعمك',
            self::ReferralQualified => 'أصبحت إحدى دعواتك مؤهَّلة',
            self::ReferralRewardReceived => 'حصلت على '.(int) ($params['gems'] ?? 0).' جوهرة من الدعوات',
            self::MissionExpiring => 'مهامك الجارية اليوم',
            self::WeeklyMissionsAvailable => 'أهداف هذا الأسبوع جاهزة',
            self::EventStartingSoon => 'تبدأ المنافسة قريبًا: '.($params['title'] ?? ''),
            self::ComebackAvailable => 'مرحبًا بعودتك',
            self::CollectionHidden => 'أُخفيت مجموعتك من المنصة: '.($params['title'] ?? ''),
            self::CollectionRestored => 'استُعيدت مجموعتك: '.($params['title'] ?? ''),
            self::CommentReplyReceived => 'ردّ جديد على تعليقك'.(! empty($params['title']) ? ' في: '.$params['title'] : ''),
            self::NewCommentOnYourContent => 'تعليق جديد على محتواك'.(! empty($params['title']) ? ': '.$params['title'] : ''),
            self::CommentHidden => 'أُخفي تعليقك من قبل الإشراف',
            self::FeaturedCreatorSelected => 'اختارك فريق المنصة «مبدعًا مختارًا»',
            self::ModerationNotice => 'إشعار من فريق الإشراف',
            self::ModerationAppealDecided => 'صدر قرار بشأن اعتراضك',
            self::CreatorStatementReady => 'كشف أرباحك الجديد جاهز',
            self::CreatorPayoutApproved => 'اعتُمد طلب دفعتك',
            self::CreatorPayoutPaid => 'سُجّل صرف دفعتك',
            self::CreatorPayoutRejected => 'لم يُعتمد طلب دفعتك',
            self::PassPremiumUnlocked => 'تم تفعيل المسار المميز'.(! empty($params['pass']) ? ': '.$params['pass'] : ''),
            self::PassPurchaseRejected => 'لم يُؤكَّد طلب شراء المسار المميز',
            self::PassClaimWindowClosing => 'مهلة استلام مكافآت المسار تقترب'.(! empty($params['pass']) ? ': '.$params['pass'] : ''),
            self::CommerceFailed => 'لم تكتمل عملية الدفع',
            self::CommerceActionRequired => 'دفعتك قيد المراجعة المالية',
            self::CommerceRefunded => 'سُجّل استرداد طلبك',
            self::SupporterTierChanged => ! empty($params['up'])
                ? 'ارتقى مستوى دعمك إلى: '.($params['tier'] ?? '')
                : 'تغيّر مستوى دعمك'.(! empty($params['tier']) ? ' إلى: '.$params['tier'] : ''),
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية: '.($params['title'] ?? ''),
            self::SeriesApproved => 'اعتُمدت سلسلتك: '.($params['title'] ?? ''),
            self::SeriesChangesRequested => 'طُلبت تعديلات على سلسلتك: '.($params['title'] ?? ''),
            self::SeriesRejected => 'لم تُعتمد سلسلتك: '.($params['title'] ?? ''),
            self::SeriesHidden => 'أُخفيت سلسلتك من المنصة: '.($params['title'] ?? ''),
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'حدّث '.($params['creator'] ?? 'مبدع تتابعه').' سلسلته: '.($params['title'] ?? '')
                : 'نشر '.($params['creator'] ?? 'مبدع تتابعه').' سلسلة جديدة: '.($params['title'] ?? ''),
        };
    }

    public function body(array $params = []): string
    {
        return match ($this) {
            self::AchievementUnlocked => 'فتحتَ إنجازًا جديدًا. تصفّح تقدّمك لترى بقية الإنجازات.',
            self::StreakAtRisk => 'سلسلتك الحالية '.(int) ($params['streak'] ?? 0).' يومًا. حلّ أحجية واحدة قبل نهاية اليوم لتستمر.',
            self::DailyQuestsAvailable => 'مهام اليوم متاحة الآن. حلّ أحجية وتقدّم نحو أهدافك اليومية.',
            self::SecurityPasswordReset => 'إن لم تكن أنت من أعاد تعيينها، تواصل مع الدعم فورًا وراجع أمان حسابك.',
            self::SeasonStarted => 'الموسم متاح الآن ويمكنك الانضمام واللعب.',
            self::CampaignAvailable => 'الحملة متاحة الآن ويمكنك البدء بها.',
            self::StageUnlocked => 'مرحلة «'.($params['stage'] ?? '').'» من حملة «'.($params['campaign'] ?? '').'» أصبحت متاحة لك الآن.',
            self::CampaignCompleted => 'أنهيت جميع مراحل حملة «'.($params['campaign'] ?? '').'».',
            self::SeasonCompleted => 'أنهيت جميع مراحل موسم «'.($params['season'] ?? '').'».',
            self::SeasonEndingSoon => 'ينتهي موسم «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكماله.',
            self::CampaignEndingSoon => 'تنتهي حملة «'.($params['name'] ?? '').'» خلال '.($params['hours'] ?? '').' ساعة أو أقل، وما زال بإمكانك إكمالها.',
            self::FriendRequestReceived => 'يمكنك قبول الطلب أو رفضه من صفحة الأصدقاء.',
            self::FriendRequestAccepted => 'أصبحتما صديقين.',
            self::FriendChallengeReceived => 'أحجية «'.($params['puzzle'] ?? '').'». يمكنك قبول التحدي أو رفضه من صفحة التحديات.',
            self::FriendChallengeAccepted => 'تحدّي أحجية «'.($params['puzzle'] ?? '').'» صار نشطًا، ويمكنك اللعب الآن.',
            self::FriendChallengeResultReady => 'تحدّي «'.($params['puzzle'] ?? '').'» ضد '.($params['name'] ?? '').': '.($params['outcome'] ?? '').'.',
            self::CompetitiveEventStarted => 'المنافسة التي سجّلت بها بدأت الآن، ويمكنك اللعب حتى انتهائها.',
            self::CompetitiveEventEndingSoon => 'لم تُرسل نتيجتك بعد. تنتهي المنافسة خلال '.($params['hours'] ?? '').' ساعة أو أقل.',
            self::CompetitiveEventResultReady => 'ترتيبك النهائي: '.($params['rank'] ?? '').'.',
            self::CompetitiveRewardGranted => 'مُنحت لك: '.($params['reward'] ?? '').'.',
            self::TeamInvitationReceived => 'يمكنك قبول الدعوة أو تجاهلها من صفحة دعواتك.',
            self::TeamInvitationAccepted => 'قَبِل دعوتك وأصبح عضوًا بالفريق.',
            self::TeamJoinRequestReceived => 'راجع الطلب من صفحة إدارة الفريق.',
            self::TeamJoinRequestAccepted => 'أهلًا بك في الفريق.',
            self::TeamMemberRemoved => 'يمكنك الانضمام إلى فريق آخر أو إنشاء فريقك من صفحة الفرق.',
            self::TeamChallengeReceived => 'اختر روستر فريقكم واقبل التحدّي قبل انتهاء المهلة.',
            self::TeamChallengeAccepted => 'قُفل الروستران، ويمكن للاعبي الروستر اللعب الآن.',
            self::TeamChallengeResultReady => ($params['outcome'] ?? 'اعتُمدت النتيجة').'.',
            self::TeamChampionshipStarted => 'تُحتسب نتائج أحداثها المعتمَدة بنقاط الترتيب.',
            self::TeamChampionshipResultReady => 'اطّلع على الترتيب النهائي وبطل البطولة.',
            self::CreatorApproved => 'يمكنك الآن إنشاء أحجيات المجتمع وإرسالها للمراجعة من استوديو المبدعين.',
            self::CreatorRejected => static::withNote('يمكنك مراجعة الملاحظة وإعادة التقديم لاحقًا.', $params),
            self::SubmissionApproved => 'أصبحت أحجيتك منشورة ضمن «من المجتمع» ويمكن للاعبين لعبها.',
            self::SubmissionChangesRequested => static::withNote('عدّل الأحجية وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SubmissionRejected => static::withNote('يمكنك إنشاء نسخة جديدة من استوديو المبدعين.', $params),
            self::PublicationHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewPublication => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من الأحجية ضمن «من المجتمع». أحجيات المجتمع بلا جوائز اقتصادية.'
                : 'أحجية جديدة ضمن «من المجتمع» من مبدع تتابعه. أحجيات المجتمع بلا جوائز اقتصادية.',
            self::SupportConfirmed => 'سُجّل دعمك وأُكّد من فريق المنصة. الدعم لا يمنحك أي أفضلية في اللعب أو المكافآت.',
            self::SupportRequestReceived => 'لن يُحتسب الدعم قبل تحقق الفريق من التحويل. اكتب رمز المرجع في التحويل وأرسل الإثبات من صفحة الطلب.',
            self::SupportActionRequired => static::withNote('راجع صفحة الطلب وأكمل البيانات المطلوبة ثم أعد الإرسال.', $params),
            self::SupportRejected => static::withNote('لم يُحتسب هذا الطلب كدعم. يمكنك إنشاء طلب جديد متى شئت.', $params),
            self::SupportRefunded => 'سُجّل استرداد دعمك ويُحتسب مستواك من صافي دعمك المؤكَّد. لا تُسحب مكافآت اللاعبين السابقة.',
            self::ReferralQualified => 'أنهى صديق دعوتَه إلى المنصة خطواته الأولى. الدعوات لا تغيّر ترتيبك ولا نتائج اللعب.',
            self::ReferralRewardReceived => 'أُضيفت الجواهر إلى رصيدك المعلّق وتصير متاحة بعد فترة الحجز المعتادة.',
            self::MissionExpiring => 'لديك '.max(1, (int) ($params['count'] ?? 1)).' مهمة قيد التقدّم تنتهي مع نهاية اليوم (بتوقيت المنصة). أكملها متى شئت؛ لا شيء يُخصم إن لم تفعل.',
            self::WeeklyMissionsAvailable => 'بدأ أسبوع جديد بأهداف جديدة. اطّلع عليها متى شئت.',
            self::EventStartingSoon => 'سجّلت في هذه المنافسة، وتبدأ خلال '.($params['hours'] ?? '').' ساعة أو أقل.',
            self::ComebackAvailable => 'جهّزنا لك خطوات سهلة للعودة: أحجية بسيطة ومهمة خفيفة. كل ما اكتسبته سابقًا محفوظ.',
            self::CollectionHidden => static::withNote('أخفاها فريق الإشراف، ولا تظهر للآخرين الآن. يمكنك تعديلها أو حذفها من «مجموعاتي».', $params),
            self::CollectionRestored => 'رفع فريق الإشراف الإخفاء عن مجموعتك. يعود ظهورها بحسب إعدادك (خاصة / غير مدرجة / عامة).',
            self::CommentReplyReceived => 'ردّ أحد الأعضاء على تعليقك. اطّلع على الرد من النقاش.',
            self::NewCommentOnYourContent => 'بدأ نقاش جديد أو أُضيف تعليق على محتواك. نرسل لك إشعارًا واحدًا فقط كل فترة حتى لا يغرقك نقاش نشط.',
            self::CommentHidden => ! empty($params['reason'])
                ? 'السبب: '.$params['reason'].'. لا يظهر تعليقك للآخرين الآن. يمكنك الالتزام بإرشادات المنصة في تعليقاتك القادمة.'
                : 'لا يظهر تعليقك للآخرين الآن. يمكنك الالتزام بإرشادات المنصة في تعليقاتك القادمة.',
            self::FeaturedCreatorSelected => 'يظهر ملفك ضمن «مبدعون مختارون» تقديرًا لأعمالك. الاختيار تحريري بقرار الفريق ولا يتأثر بأي دعم أو دفع.',
            self::ModerationNotice => 'اتُّخذ إجراء يخص حسابك أو محتواك. افتح صفحة «الأمان والإشراف» لمعرفة التفاصيل وخيارات الاعتراض.',
            self::ModerationAppealDecided => 'راجع فريق الإشراف اعتراضك. افتح صفحة «الأمان والإشراف» لقراءة القرار.',
            self::CreatorStatementReady => 'أُقفلت فترة إيراد تخصك. افتح صفحة «الأرباح» لمراجعة الكشف.',
            self::CreatorPayoutApproved => 'اعتمد الفريق طلب دفعتك وينتظر الصرف. تابع حالته في صفحة «الأرباح».',
            self::CreatorPayoutPaid => 'سجّل الفريق صرف دفعتك. راجع التفاصيل في صفحة «الأرباح».',
            self::CreatorPayoutRejected => 'أُعيد المبلغ المحجوز إلى رصيدك المتاح. راجع رسالة الفريق في صفحة «الأرباح».',
            self::PassPremiumUnlocked => 'تأكّد دفعك وفُتحت مكافآت المسار المميز لما بلغتَه من شرائح. استلمها متى شئت من صفحة المسار.',
            self::PassPurchaseRejected => 'راجع حالة طلبك في صفحة المسار؛ يمكنك تقديم طلب جديد إن كانت المبيعات ما زالت متاحة.',
            self::PassClaimWindowClosing => 'لديك مكافآت جاهزة للاستلام وستُغلق مهلة استلامها قريبًا. استلمها من صفحة المسار.',
            self::CommerceFailed => 'لم يُفعَّل المسار المميز لأن عملية الدفع لم تكتمل. يمكنك إعادة المحاولة من صفحة الطلب متى شئت.',
            self::CommerceActionRequired => 'وصلتنا عملية دفع تحتاج مراجعة يدوية قبل تفعيل المسار المميز. يتولى الفريق المالي الأمر، ولا يلزمك فعل شيء الآن.',
            self::CommerceRefunded => 'سُجّل استرداد طلبك. تجد التفاصيل في «مشترياتي».',
            self::SupporterTierChanged => 'يظهر مستواك ضمن «الداعمون». المستوى يُحسب من صافي دعمك المؤكَّد.',
            self::SponsorshipProgramStarted => 'بدأ برنامج رعاية تشارك فيه كداعم. اطّلع على صفحة الداعمين.',
            self::SeriesApproved => 'أصبحت سلسلتك منشورة ضمن «من المجتمع» ويمكن للاعبين تتبّعها.',
            self::SeriesChangesRequested => static::withNote('عدّل السلسلة وأعد إرسالها للمراجعة من استوديو المبدعين.', $params),
            self::SeriesRejected => static::withNote('يمكنك إنشاء سلسلة جديدة من استوديو المبدعين.', $params),
            self::SeriesHidden => 'أخفاها فريق الإشراف مؤقتًا. راجع حالتها في استوديو المبدعين.',
            self::CreatorNewSeries => ! empty($params['updated'])
                ? 'اعتُمدت نسخة جديدة من السلسلة ضمن «من المجتمع». سلاسل المجتمع بلا جوائز اقتصادية.'
                : 'سلسلة أحجيات جديدة ضمن «من المجتمع» من مبدع تتابعه. سلاسل المجتمع بلا جوائز اقتصادية.',
        };
    }

    /** ملاحظة المراجع (نص عادي يُهرَّب عند العرض) تُلحق بنص الإشعار إن وُجدت؛ الغياب لا يكسر النص. */
    protected static function withNote(string $base, array $params): string
    {
        $note = trim((string) ($params['note'] ?? ''));

        return $note === '' ? $base : 'ملاحظة المراجع: '.$note.' — '.$base;
    }

    /** @return list<string> */
    public static function reEngagementKeys(?self $atLeast = null): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isReEngagement() && ($atLeast === null || $t->priority() >= $atLeast->priority())),
        ));
    }
}
