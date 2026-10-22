<?php

namespace App\Services\Progression;

use App\Services\Progression\Achievements\AchievementEvaluator;
use App\Services\Progression\Achievements\CommunityRecognitionEvaluator;
use App\Services\Progression\Achievements\CampaignsCompletedTotalEvaluator;
use App\Services\Progression\Achievements\CompetitiveAchievementEvaluator;
use App\Services\Progression\Achievements\CampaignStepsCompletedTotalEvaluator;
use App\Services\Progression\Achievements\PuzzlesSolvedInCategoryEvaluator;
use App\Services\Progression\Achievements\PuzzlesSolvedTotalEvaluator;
use App\Services\Progression\Achievements\QualificationsEarnedEvaluator;
use App\Services\Progression\Achievements\StreakLongestDaysEvaluator;

class AchievementEvaluatorRegistry
{
    public const PUZZLES_SOLVED_TOTAL = 'puzzles_solved_total';
    public const PUZZLES_SOLVED_IN_CATEGORY = 'puzzles_solved_in_category';
    public const QUALIFICATIONS_EARNED_TOTAL = 'qualifications_earned_total';
    public const CAMPAIGN_STEPS_COMPLETED_TOTAL = 'campaign_steps_completed_total';
    public const CAMPAIGNS_COMPLETED_TOTAL = 'campaigns_completed_total';
    public const COMPETITIVE_EVENTS_WON = 'competitive_events_won';
    public const COMPETITIVE_TOP3_FINISHES = 'competitive_top3_finishes';
    public const COMPETITIVE_EVENTS_COMPLETED = 'competitive_events_completed';
    /** E34: معلم السلسلة اليومية (أطول سلسلة أيام لعب رسمي متتالية؛ المصدر PlayerStreak.longest_streak). */
    public const STREAK_LONGEST_DAYS = 'streak_longest_days';

    /** E37: اعتراف مجتمعي (مبدعون/منسّقون/مساهمون). المصدر لقطة السمعة المشتقّة؛ بلا مكافآت (يمنعها AchievementInvariantGuard). */
    public const CREATOR_PUBLISHED_PUZZLES_TOTAL = 'creator_published_puzzles_total';
    public const CREATOR_PUBLISHED_SERIES_TOTAL = 'creator_published_series_total';
    public const CREATOR_RATINGS_RECEIVED_TOTAL = 'creator_ratings_received_total';
    public const CREATOR_FAVORITES_RECEIVED_TOTAL = 'creator_favorites_received_total';
    public const CREATOR_FOLLOWERS_TOTAL = 'creator_followers_total';
    public const CREATOR_QUALITY_SCORE = 'creator_quality_score';
    public const PUBLIC_COLLECTIONS_TOTAL = 'public_collections_total';
    public const PUBLIC_COLLECTION_SAVERS_TOTAL = 'public_collection_savers_total';
    public const DISCUSSION_LIKERS_TOTAL = 'discussion_likers_total';

    public const COMMUNITY_TYPES = [
        self::CREATOR_PUBLISHED_PUZZLES_TOTAL,
        self::CREATOR_PUBLISHED_SERIES_TOTAL,
        self::CREATOR_RATINGS_RECEIVED_TOTAL,
        self::CREATOR_FAVORITES_RECEIVED_TOTAL,
        self::CREATOR_FOLLOWERS_TOTAL,
        self::CREATOR_QUALITY_SCORE,
        self::PUBLIC_COLLECTIONS_TOTAL,
        self::PUBLIC_COLLECTION_SAVERS_TOTAL,
        self::DISCUSSION_LIKERS_TOTAL,
    ];

    public const ALL_TYPES = [
        self::PUZZLES_SOLVED_TOTAL,
        self::PUZZLES_SOLVED_IN_CATEGORY,
        self::QUALIFICATIONS_EARNED_TOTAL,
        self::CAMPAIGN_STEPS_COMPLETED_TOTAL,
        self::CAMPAIGNS_COMPLETED_TOTAL,
        self::COMPETITIVE_EVENTS_WON,
        self::COMPETITIVE_TOP3_FINISHES,
        self::COMPETITIVE_EVENTS_COMPLETED,
        self::STREAK_LONGEST_DAYS,
        ...self::COMMUNITY_TYPES,
    ];

    public const SCOPED_TYPES = [
        self::PUZZLES_SOLVED_IN_CATEGORY => 'puzzle_category',
    ];

    public const EVENT_GROUPS = [
        'puzzle_solved' => [self::PUZZLES_SOLVED_TOTAL, self::PUZZLES_SOLVED_IN_CATEGORY, self::CAMPAIGN_STEPS_COMPLETED_TOTAL],
        'campaign_step_completed' => [self::CAMPAIGN_STEPS_COMPLETED_TOTAL, self::CAMPAIGNS_COMPLETED_TOTAL],
        'qualification_earned' => [self::QUALIFICATIONS_EARNED_TOTAL],
        'competitive_event_finalized' => [self::COMPETITIVE_EVENTS_WON, self::COMPETITIVE_TOP3_FINISHES, self::COMPETITIVE_EVENTS_COMPLETED],
        'streak_updated' => [self::STREAK_LONGEST_DAYS],
        'community_reputation_refreshed' => self::COMMUNITY_TYPES,
    ];

    public function options(): array
    {
        return [
            self::PUZZLES_SOLVED_TOTAL => 'إجمالي الأحجيات المحلولة',
            self::PUZZLES_SOLVED_IN_CATEGORY => 'أحجيات محلولة ضمن تصنيف معيَّن',
            self::QUALIFICATIONS_EARNED_TOTAL => 'إجمالي التأهلات المُكتسَبة (First-N)',
            self::CAMPAIGN_STEPS_COMPLETED_TOTAL => 'إجمالي خطوات الحملات المكتملة',
            self::CAMPAIGNS_COMPLETED_TOTAL => 'إجمالي الحملات المكتملة بالكامل',
            self::COMPETITIVE_EVENTS_WON => 'منافسات رسمية فاز بها (المركز الأول)',
            self::COMPETITIVE_TOP3_FINISHES => 'منافسات رسمية أنهاها ضمن المراكز الثلاثة الأولى',
            self::COMPETITIVE_EVENTS_COMPLETED => 'منافسات رسمية معتمَدة بنتيجة صحيحة',
            self::STREAK_LONGEST_DAYS => 'أطول سلسلة أيام لعب رسمي متتالية (معلم السلسلة)',
            self::CREATOR_PUBLISHED_PUZZLES_TOTAL => 'مبدع: أعمال ظاهرة منشورة (اعتراف مجتمعي)',
            self::CREATOR_PUBLISHED_SERIES_TOTAL => 'مبدع: سلاسل ظاهرة منشورة (اعتراف مجتمعي)',
            self::CREATOR_RATINGS_RECEIVED_TOTAL => 'مبدع: تقييمات مستلَمة من آخرين (اعتراف مجتمعي)',
            self::CREATOR_FAVORITES_RECEIVED_TOTAL => 'مبدع: إضافات للمفضلة من لاعبين مختلفين (اعتراف مجتمعي)',
            self::CREATOR_FOLLOWERS_TOTAL => 'مبدع: متابعون (اعتراف مجتمعي)',
            self::CREATOR_QUALITY_SCORE => 'مبدع: درجة الجودة (0–10000، لمؤهَّل فقط؛ اعتراف مجتمعي)',
            self::PUBLIC_COLLECTIONS_TOTAL => 'منسّق: مجموعات عامة مُدرَجة (اعتراف مجتمعي)',
            self::PUBLIC_COLLECTION_SAVERS_TOTAL => 'منسّق: حافظون مختلفون لمجموعاته العامة (اعتراف مجتمعي)',
            self::DISCUSSION_LIKERS_TOTAL => 'مساهم: معجِبون مختلفون بتعليقاته (اعتراف مجتمعي)',
        ];
    }

    public function evaluatorFor(string $conditionType): ?AchievementEvaluator
    {
        return match ($conditionType) {
            self::PUZZLES_SOLVED_TOTAL => app(PuzzlesSolvedTotalEvaluator::class),
            self::PUZZLES_SOLVED_IN_CATEGORY => app(PuzzlesSolvedInCategoryEvaluator::class),
            self::QUALIFICATIONS_EARNED_TOTAL => app(QualificationsEarnedEvaluator::class),
            self::CAMPAIGN_STEPS_COMPLETED_TOTAL => app(CampaignStepsCompletedTotalEvaluator::class),
            self::CAMPAIGNS_COMPLETED_TOTAL => app(CampaignsCompletedTotalEvaluator::class),
            self::COMPETITIVE_EVENTS_WON, self::COMPETITIVE_TOP3_FINISHES, self::COMPETITIVE_EVENTS_COMPLETED => app(CompetitiveAchievementEvaluator::class),
            self::STREAK_LONGEST_DAYS => app(StreakLongestDaysEvaluator::class),
            self::CREATOR_PUBLISHED_PUZZLES_TOTAL, self::CREATOR_PUBLISHED_SERIES_TOTAL, self::CREATOR_RATINGS_RECEIVED_TOTAL, self::CREATOR_FAVORITES_RECEIVED_TOTAL,
            self::CREATOR_FOLLOWERS_TOTAL, self::CREATOR_QUALITY_SCORE, self::PUBLIC_COLLECTIONS_TOTAL, self::PUBLIC_COLLECTION_SAVERS_TOTAL,
            self::DISCUSSION_LIKERS_TOTAL => app(CommunityRecognitionEvaluator::class),
            default => null,
        };
    }

    public function conditionTypesForEvent(string $event): array
    {
        return self::EVENT_GROUPS[$event] ?? [];
    }

    public function requiresScope(string $conditionType): bool
    {
        return array_key_exists($conditionType, self::SCOPED_TYPES);
    }

    public function isValidConditionType(string $conditionType): bool
    {
        return in_array($conditionType, self::ALL_TYPES, true);
    }
}