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
 *  - FriendRequestCreated / FriendshipAccepted (أحداث مجال بعد commit، FriendshipService) → friend_request_received / friend_request_accepted
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

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
            self::StageUnlocked => NotificationCategory::Campaign,
            self::CampaignCompleted => NotificationCategory::Campaign,
            self::SeasonCompleted, self::SeasonEndingSoon => NotificationCategory::Season,
            self::CampaignEndingSoon => NotificationCategory::Campaign,
            self::FriendRequestReceived, self::FriendRequestAccepted => NotificationCategory::Social,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable, self::SeasonEndingSoon, self::CampaignEndingSoon], true);
    }

    /** أعلى = أهم. اختيار الأولوية يتم بتقسيم الجمهور + الميزانية (راجع ReEngagementService). */
    public function priority(): int
    {
        return match ($this) {
            self::SecurityPasswordReset => 1000,
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
            self::AchievementUnlocked => 10,
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
        };
    }

    /** أسماء المسارات الداخلية الوحيدة المسموحة لهذا النوع (الأولى = الافتراضية). لا URL خارجي أبدًا. */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::AchievementUnlocked => ['progress.show'],
            self::StreakAtRisk => ['puzzles.index'],
            self::DailyQuestsAvailable => ['quests.show'],
            self::SecurityPasswordReset => ['profile.edit'],
            self::SeasonStarted => ['seasons.show'],
            self::CampaignAvailable => ['campaigns.show'],
            self::StageUnlocked => ['campaigns.show', 'seasons.show'],
            self::CampaignCompleted => ['campaigns.show', 'seasons.show'],
            self::SeasonCompleted, self::SeasonEndingSoon => ['seasons.show'],
            self::CampaignEndingSoon => ['campaigns.show'],
            self::FriendRequestReceived, self::FriendRequestAccepted => ['friends.index'],
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
        };
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
