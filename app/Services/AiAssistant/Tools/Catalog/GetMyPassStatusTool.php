<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AhjiyatPass\PassPageData;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_my_pass_status: حالة مسار الأحجيات للمستخدم (المرحلة والنقاط والمتبقي للمرحلة التالية والمكافآت الجاهزة للاستلام وهل الفئة المميزة مفعّلة). قراءة فقط (PassPageData::widgetFor لا يكتب). لا استلام. */
final class GetMyPassStatusTool extends AiTool
{
    public function name(): string
    {
        return 'get_my_pass_status';
    }

    public function description(): string
    {
        return "Get the signed-in member's own Ahjiyat Pass status: current tier, pass points, points to next tier, claimable rewards count, premium track unlocked or not, days left. Read-only: cannot claim.";
    }

    public function personal(): bool
    {
        return true;
    }

    public function area(): string
    {
        return 'pass';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $widget = app(PassPageData::class)->widgetFor($context->user);
        $sources = array_values(array_filter([Presenter::link('مسار الأحجيات', Presenter::path('pass.index'))]));

        if ($widget === null) {
            return $this->ok(['pass' => null, 'note' => 'There is no Ahjiyat Pass you can take part in right now (none active, feature off, or account restricted).'], $sources);
        }

        return $this->ok(
            [
                'pass' => [
                    'name' => Presenter::text($widget['pass']->name, 100),
                    'active' => (bool) $widget['active'],
                    'tier' => (int) $widget['tier'],
                    'max_tier' => (int) $widget['max_tier'],
                    'pass_points' => (int) $widget['points'],
                    'points_to_next_tier' => (int) $widget['next_remaining'],
                    'claimable_rewards' => (int) $widget['claimable_count'],
                    'premium_unlocked' => (bool) $widget['has_premium'],
                    'days_left' => (int) $widget['days_left'],
                ],
            ],
            $sources,
            ['pass'],
        );
    }
}
