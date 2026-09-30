<?php

namespace App\Services\Progression;

use App\Services\Progression\Achievements\AchievementEvaluator;
use App\Services\Progression\Achievements\CampaignsCompletedTotalEvaluator;
use App\Services\Progression\Achievements\CampaignStepsCompletedTotalEvaluator;
use App\Services\Progression\Achievements\PuzzlesSolvedInCategoryEvaluator;
use App\Services\Progression\Achievements\PuzzlesSolvedTotalEvaluator;
use App\Services\Progression\Achievements\QualificationsEarnedEvaluator;

class AchievementEvaluatorRegistry
{
    public const PUZZLES_SOLVED_TOTAL = 'puzzles_solved_total';
    public const PUZZLES_SOLVED_IN_CATEGORY = 'puzzles_solved_in_category';
    public const QUALIFICATIONS_EARNED_TOTAL = 'qualifications_earned_total';
    public const CAMPAIGN_STEPS_COMPLETED_TOTAL = 'campaign_steps_completed_total';
    public const CAMPAIGNS_COMPLETED_TOTAL = 'campaigns_completed_total';

    public const ALL_TYPES = [
        self::PUZZLES_SOLVED_TOTAL,
        self::PUZZLES_SOLVED_IN_CATEGORY,
        self::QUALIFICATIONS_EARNED_TOTAL,
        self::CAMPAIGN_STEPS_COMPLETED_TOTAL,
        self::CAMPAIGNS_COMPLETED_TOTAL,
    ];

    public const SCOPED_TYPES = [
        self::PUZZLES_SOLVED_IN_CATEGORY => 'puzzle_category',
    ];

    public const EVENT_GROUPS = [
        'puzzle_solved' => [self::PUZZLES_SOLVED_TOTAL, self::PUZZLES_SOLVED_IN_CATEGORY, self::CAMPAIGN_STEPS_COMPLETED_TOTAL],
        'campaign_step_completed' => [self::CAMPAIGN_STEPS_COMPLETED_TOTAL, self::CAMPAIGNS_COMPLETED_TOTAL],
        'qualification_earned' => [self::QUALIFICATIONS_EARNED_TOTAL],
    ];

    public function options(): array
    {
        return [
            self::PUZZLES_SOLVED_TOTAL => 'إجمالي الأحجيات المحلولة',
            self::PUZZLES_SOLVED_IN_CATEGORY => 'أحجيات محلولة ضمن تصنيف معيَّن',
            self::QUALIFICATIONS_EARNED_TOTAL => 'إجمالي التأهلات المُكتسَبة (First-N)',
            self::CAMPAIGN_STEPS_COMPLETED_TOTAL => 'إجمالي خطوات الحملات المكتملة',
            self::CAMPAIGNS_COMPLETED_TOTAL => 'إجمالي الحملات المكتملة بالكامل',
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