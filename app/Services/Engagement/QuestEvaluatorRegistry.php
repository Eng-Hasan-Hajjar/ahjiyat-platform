<?php

namespace App\Services\Engagement;

use App\Models\QuestDefinition;
use App\Services\Engagement\Evaluators\CampaignStepsCompletedEvaluator;
use App\Services\Engagement\Evaluators\PuzzlesSolvedEvaluator;
use App\Services\Engagement\Evaluators\PuzzlesSolvedInCategoryEvaluator;
use App\Services\Engagement\Evaluators\QualificationsEarnedEvaluator;
use App\Services\Engagement\Evaluators\QuestEvaluator;

/**
 * E13 (بند 15): قائمة مغلقة - لا شرط تجاري واحد، بنفس فلسفة
 * AchievementEvaluatorRegistry تمامًا لكن نسخة مستقلة كاملة لـQuest.
 */
class QuestEvaluatorRegistry
{
    public const PUZZLES_SOLVED = 'puzzles_solved';
    public const PUZZLES_SOLVED_IN_CATEGORY = 'puzzles_solved_in_category';
    public const CAMPAIGN_STEPS_COMPLETED = 'campaign_steps_completed';
    public const QUALIFICATIONS_EARNED = 'qualifications_earned';

    public const ALL_TYPES = [
        self::PUZZLES_SOLVED,
        self::PUZZLES_SOLVED_IN_CATEGORY,
        self::CAMPAIGN_STEPS_COMPLETED,
        self::QUALIFICATIONS_EARNED,
    ];

    /** بند 232: تجميع بالحدث - لا فحص كل مهمة نشطة عند كل حدث. */
    public const EVENT_GROUPS = [
        'puzzle_solved' => [self::PUZZLES_SOLVED, self::PUZZLES_SOLVED_IN_CATEGORY],
        'campaign_step_completed' => [self::CAMPAIGN_STEPS_COMPLETED],
        'qualification_earned' => [self::QUALIFICATIONS_EARNED],
    ];

    /** @var QuestEvaluator[] */
    protected array $evaluators;

    public function __construct(
        PuzzlesSolvedEvaluator $puzzlesSolved,
        PuzzlesSolvedInCategoryEvaluator $puzzlesSolvedInCategory,
        CampaignStepsCompletedEvaluator $campaignSteps,
        QualificationsEarnedEvaluator $qualifications,
    ) {
        $this->evaluators = [$puzzlesSolved, $puzzlesSolvedInCategory, $campaignSteps, $qualifications];
    }

    public function evaluatorFor(string $conditionType): ?QuestEvaluator
    {
        foreach ($this->evaluators as $evaluator) {
            $questStub = new QuestDefinition(['condition_type' => $conditionType]);
            if ($evaluator->supports($questStub)) {
                return $evaluator;
            }
        }

        return null;
    }

    public function conditionTypesForEvent(string $event): array
    {
        return self::EVENT_GROUPS[$event] ?? [];
    }

    public function options(): array
    {
        return [
            self::PUZZLES_SOLVED => 'حل أحجيات (إجمالي بالفترة)',
            self::PUZZLES_SOLVED_IN_CATEGORY => 'حل أحجيات (بتصنيف محدَّد)',
            self::CAMPAIGN_STEPS_COMPLETED => 'إكمال خطوات حملة',
            self::QUALIFICATIONS_EARNED => 'تحقيق تأهلات',
        ];
    }

    public function requiresScope(string $conditionType): bool
    {
        return $conditionType === self::PUZZLES_SOLVED_IN_CATEGORY;
    }

    public function isValidConditionType(string $conditionType): bool
    {
        return in_array($conditionType, self::ALL_TYPES, true);
    }
}
