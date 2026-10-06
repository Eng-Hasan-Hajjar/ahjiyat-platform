<?php

namespace App\Services\Notifications;

/**
 * سجل مغلق للفئات المنفَّذة فعليًا فقط (لا فئة بلا حدث حقيقي يغذّيها). لا فئة تأتي من نص
 * إدارة أو من قاعدة البيانات. فئات مؤجَّلة (campaign/season/system/store/engagement): راجع docs/notifications.md.
 */
enum NotificationCategory: string
{
    case Achievement = 'achievement';
    case Streak = 'streak';
    case Quest = 'quest';
    case Security = 'security';
    case Season = 'season';
    case Campaign = 'campaign';
    case Social = 'social';
    case Competitive = 'competitive';

    public function label(): string
    {
        return match ($this) {
            self::Achievement => 'الإنجازات',
            self::Streak => 'السلسلة اليومية',
            self::Quest => 'المهام اليومية',
            self::Security => 'الأمان',
            self::Season => 'المواسم',
            self::Campaign => 'الحملات',
            self::Social => 'الاجتماعية',
            self::Competitive => 'المنافسات',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Achievement => 'إشعار عند فتح إنجاز جديد.',
            self::Streak => 'تذكير واحد في اليوم عند اقتراب انقطاع سلسلتك.',
            self::Quest => 'تذكير واحد في اليوم بجاهزية مهام اليوم.',
            self::Security => 'تنبيهات أمان حسابك - إلزامية ولا يمكن تعطيلها.',
            self::Season => 'إشعار واحد عند بدء موسم جديد.',
            self::Campaign => 'إشعار واحد عند إتاحة حملة جديدة.',
            self::Social => 'طلبات الصداقة الجديدة وقبول طلباتك، وتحديات الأصدقاء ونتائجها.',
            self::Competitive => 'بدء المنافسات المسجَّل بها وقرب نهايتها وجاهزية نتائجها.',
        };
    }

    /** الأمان إلزامي: لا يخضع لتفضيل المستخدم ولا للمفتاح الشامل. */
    public function isMandatory(): bool
    {
        return $this === self::Security;
    }

    /** عمود التفضيل بجدول notification_preferences (null للإلزامي). */
    public function preferenceColumn(): ?string
    {
        return match ($this) {
            self::Achievement => 'achievement_enabled',
            self::Streak => 'streak_enabled',
            self::Quest => 'quest_enabled',
            self::Security => null,
            self::Season => 'season_enabled',
            self::Campaign => 'campaign_enabled',
            self::Social => 'social_enabled',
            self::Competitive => 'competitive_enabled',
        };
    }

    /** @return list<self> */
    public static function optional(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c) => ! $c->isMandatory()));
    }
}
