<?php

namespace App\Services\AiAssistant\Tools\Catalog;

use App\Models\StoreItem;
use App\Models\StoreItemPrice;
use App\Services\AiAssistant\Dto\AiToolResult;
use App\Services\AiAssistant\Tools\AiTool;
use App\Services\AiAssistant\Tools\Presenter;
use App\Services\AiAssistant\Tools\ToolContext;

/** E45 - get_store_item_information: عناصر المتجر الظاهرة علنًا والقابلة للشراء (الاسم، النوع، الأسعار الفعّالة بالعملة، المخزون المتبقي إن حُدّد). لا شراء ولا حصريات المسار ولا مخزون داخلي. */
final class GetStoreItemInformationTool extends AiTool
{
    private const TYPES = [
        StoreItem::TYPE_PHYSICAL => 'عنصر مادي',
        StoreItem::TYPE_DIGITAL => 'عنصر رقمي',
        StoreItem::TYPE_COSMETIC => 'عنصر تجميلي',
        StoreItem::TYPE_CONSUMABLE => 'عنصر استهلاكي',
        StoreItem::TYPE_ACCESS => 'صلاحية وصول',
    ];

    public function name(): string
    {
        return 'get_store_item_information';
    }

    public function description(): string
    {
        return 'Get public store items (name, type, active prices, remaining stock when limited), either a list or one item by slug or part of its name. Read-only: cannot buy.';
    }

    public function properties(): array
    {
        return [
            'slug' => ['type' => 'string', 'description' => 'Optional store item slug.', 'maxLength' => 120],
            'name' => ['type' => 'string', 'description' => 'Optional part of the item name.', 'maxLength' => 60],
        ];
    }

    public function area(): string
    {
        return 'store';
    }

    public function run(ToolContext $context, array $arguments): AiToolResult
    {
        $query = StoreItem::query()->with(['activePrices.currency'])->where('is_active', true)->whereDoesntHave('passRewards');

        if (isset($arguments['slug'])) {
            $query->where('slug', (string) $arguments['slug']);
        } elseif (isset($arguments['name'])) {
            $query->where('name', 'like', '%'.addcslashes((string) $arguments['name'], '%_\\').'%');
        }

        $items = $query->orderBy('sort_order')->orderBy('id')->limit(30)->get()->filter(fn (StoreItem $i) => $i->isPubliclyVisible())->take(8)->values();

        if ($items->isEmpty()) {
            return $this->notFound('لم أجد عنصرًا متاحًا في المتجر يطابق طلبك.');
        }

        $rows = $items->map(fn (StoreItem $i) => [
            'name' => Presenter::text($i->name, 100),
            'summary' => Presenter::text($i->short_description, 200),
            'type' => self::TYPES[$i->item_type] ?? 'عنصر',
            'prices' => $i->activePrices->map(fn (StoreItemPrice $p) => ['currency' => Presenter::text($p->currency?->name, 40), 'amount' => (int) $p->amount])->values()->all(),
            'remaining_stock' => $i->remainingStock(),
            'per_user_limit' => $i->per_user_limit,
            'available_until' => Presenter::date($i->ends_at),
            'path' => Presenter::path('store.items.show', ['item' => $i->slug]),
        ])->all();

        return $this->ok(['items' => $rows], array_values(array_filter([Presenter::link('المتجر', Presenter::path('store.index')), Presenter::link('صفحة العنصر', $rows[0]['path'] ?? null)])), ['name', 'summary']);
    }
}
