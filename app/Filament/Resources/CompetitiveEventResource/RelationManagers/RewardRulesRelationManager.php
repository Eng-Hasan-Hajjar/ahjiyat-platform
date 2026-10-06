<?php

namespace App\Filament\Resources\CompetitiveEventResource\RelationManagers;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveRewardRule;
use App\Models\Currency;
use App\Models\StoreItem;
use App\Services\Competitive\Rewards\CompetitiveRewardRuleException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * جوائز المنافسة (E18-E1/E2). كل اختيار من الكتالوج بالاسم (Select قابل للبحث) لا بمعرّفات خام. القواعد **للقراءة فقط** حين تُقفل (بعد بدء المنافسة أو
 * اعتماد نتائجها) أو لمن لا يملك rewards.manage؛ والقفل الحقيقي بحارس النموذج (لا يعتمد على إخفاء زر): أي رفض يظهر رسالة بدل خطأ 500. لا إدخال يدوي
 * لجائزة لاعب بعينه: القواعد فقط، والتوزيع آلي من نتائج معتمَدة.
 */
class RewardRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rewardRules';

    protected static ?string $title = 'جوائز المنافسة';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return auth()->user()?->can('viewRewards', $ownerRecord) ?? false;
    }

    protected function canManage(): bool
    {
        /** @var CompetitiveEvent $event */
        $event = $this->getOwnerRecord();

        return $event->rewardsEditable() && (auth()->user()?->can('manageRewards', $event) ?? false);
    }

    public function form(Form $form): Form
    {
        $is = fn (string $field, string $value) => fn (Forms\Get $get) => $get($field) === $value;

        return $form->schema([
            Forms\Components\Select::make('kind')->label('النوع')->options(['rank' => 'مركز أو نطاق مراكز', 'participation' => 'مشاركة (لصاحب نتيجة صحيحة)'])->default('rank')->required()->live()->native(false),
            Forms\Components\TextInput::make('min_rank')->label('من المركز')->numeric()->minValue(1)->visible($is('kind', 'rank'))->required($is('kind', 'rank')),
            Forms\Components\TextInput::make('max_rank')->label('إلى المركز')->numeric()->minValue(1)->visible($is('kind', 'rank'))->required($is('kind', 'rank')),
            Forms\Components\Select::make('reward_type')->label('نوع الجائزة')->options(['currency' => 'عملة', 'xp' => 'نقاط خبرة', 'store_item' => 'عنصر متجر (شارة، لقب، مقتنى، امتياز)'])->required()->live()->native(false),
            Forms\Components\Select::make('currency_id')->label('العملة')->searchable()->preload()
                ->options(fn () => Currency::query()->where('is_active', true)->where('is_earnable', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($is('reward_type', 'currency'))->required($is('reward_type', 'currency')),
            Forms\Components\Select::make('store_item_id')->label('عنصر المتجر')->searchable()
                ->getSearchResultsUsing(fn (string $search) => StoreItem::query()->whereIn('fulfillment_type', [StoreItem::FULFILLMENT_INVENTORY, StoreItem::FULFILLMENT_ENTITLEMENT])
                    ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => StoreItem::query()->find($value)?->name)
                ->visible($is('reward_type', 'store_item'))->required($is('reward_type', 'store_item')),
            Forms\Components\TextInput::make('amount')->label('المقدار / الكمية')->numeric()->minValue(1)->required(),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('فعّالة')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('placement')->label('المركز')->state(fn (CompetitiveRewardRule $r) => $r->placementLabel()),
                Tables\Columns\TextColumn::make('reward')->label('الجائزة')->state(fn (CompetitiveRewardRule $r) => $r->rewardLabel()),
                Tables\Columns\IconColumn::make('is_active')->label('فعّالة')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('الترتيب'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['currency', 'storeItem']))
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('إضافة جائزة')->visible(fn () => $this->canManage())
                    ->using(fn (array $data) => $this->guarded(fn () => $this->getRelationship()->create($this->clean($data)))),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => $this->canManage())
                    ->using(fn (Model $record, array $data) => $this->guarded(function () use ($record, $data) {
                        $record->update($this->clean($data));

                        return $record;
                    })),
                Tables\Actions\DeleteAction::make()->visible(fn () => $this->canManage())
                    ->using(fn (Model $record) => $this->guarded(fn () => $record->delete())),
            ]);
    }

    /** يُنظّف الحقول غير المنطبقة (فلا تبقى مراكز لقاعدة مشاركة ولا عملة لجائزة XP). */
    protected function clean(array $data): array
    {
        if (($data['kind'] ?? null) === CompetitiveRewardRule::KIND_PARTICIPATION) {
            $data['min_rank'] = $data['max_rank'] = null;
        }

        $type = $data['reward_type'] ?? null;
        $data['currency_id'] = $type === CompetitiveRewardRule::TYPE_CURRENCY ? ($data['currency_id'] ?? null) : null;
        $data['store_item_id'] = $type === CompetitiveRewardRule::TYPE_STORE_ITEM ? ($data['store_item_id'] ?? null) : null;

        return $data;
    }

    /** أي رفض من الحارس (قفل، تداخل، تعريف غير صالح) يظهر إشعارًا واضحًا بدل خطأ 500. */
    protected function guarded(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (CompetitiveRewardRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
