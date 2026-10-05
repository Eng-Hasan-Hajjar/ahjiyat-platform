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
 */
enum NotificationType: string
{
    case AchievementUnlocked = 'achievement_unlocked';
    case StreakAtRisk = 'streak_at_risk';
    case DailyQuestsAvailable = 'daily_quests_available';
    case SecurityPasswordReset = 'security_password_reset';
    case SeasonStarted = 'season_started';
    case CampaignAvailable = 'campaign_available';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::AchievementUnlocked => NotificationCategory::Achievement,
            self::StreakAtRisk => NotificationCategory::Streak,
            self::DailyQuestsAvailable => NotificationCategory::Quest,
            self::SecurityPasswordReset => NotificationCategory::Security,
            self::SeasonStarted => NotificationCategory::Season,
            self::CampaignAvailable => NotificationCategory::Campaign,
        };
    }

    /** تذكير "عودة" (يخضع لميزانية اليوم) - مقابل المعاملاتي (إنجاز) والأمان (لا ميزانية). */
    public function isReEngagement(): bool
    {
        return in_array($this, [self::StreakAtRisk, self::DailyQuestsAvailable], true);
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
        };
    }

    /** @return list<string> */
    public static function reEngagementKeys(): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isReEngagement()),
        ));
    }
}
