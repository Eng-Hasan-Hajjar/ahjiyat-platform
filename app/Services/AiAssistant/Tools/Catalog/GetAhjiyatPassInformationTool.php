<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Services\AhjiyatPass\PassLifecycleService;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;
use App\Services\Sponsorship\SponsorshipMoney;

/** E45 - get_ahjiyat_pass_information: مسار الأحجيات الفعّال الآن (النافذة، عدد المراحل، توفر الفئة المميزة وسعرها). لا مكافآت فردية ولا أرقام مستخدمين. */
final class GetAhjiyatPassInformationTool extends AiTool
{
    public function name(): string
    {
        return 'get_ahjiyat_pass_information';
    }

    public function description(): string
    {
        return 'Get the current Ahjiyat Pass (seasonal progression track): name, description, dates, number of tiers, and whether the premium track is on sale with its price.';
    }

    public function area(): string
    {
        return 'pass';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $pass = app(PassLifecycleService::class)->current();

        if ($pass === null) {
            return $this->ok(['pass' => null, 'note' => 'No Ahjiyat Pass is active right now.'], [Presenter::link('مسار الأحجيات', Presenter::path('pass.index'))]);
        }

        $premium = null;

        if ($pass->premium_enabled && (int) $pass->premium_price_minor > 0) {
            $premium = [
                'sales_open' => $pass->isPremiumSalesOpen($context->now),
                'price' => SponsorshipMoney::format((int) $pass->premium_price_minor, (string) $pass->currency),
            ];
        }

        return $this->ok(
            [
                'pass' => [
                    'name' => Presenter::text($pass->name, 100),
                    'subtitle' => Presenter::text($pass->subtitle, 140),
                    'description' => Presenter::text($pass->description, 320),
                    'active' => $pass->isActiveNow($context->now),
                    'starts_at' => Presenter::date($pass->starts_at),
                    'ends_at' => Presenter::date($pass->ends_at),
                    'claim_ends_at' => Presenter::date($pass->claim_ends_at),
                    'total_tiers' => (int) $pass->total_tiers,
                    'premium' => $premium,
                    'path' => Presenter::path('pass.index'),
                ],
            ],
            array_values(array_filter([Presenter::link('مسار الأحجيات', Presenter::path('pass.index'))])),
            ['name', 'subtitle', 'description'],
        );
    }
}
