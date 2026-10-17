<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupporterTierResource\Pages;
use App\Models\SupporterTier;
use App\Services\Sponsorship\SponsorshipMoney;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E30 - مستويات الداعمين (تُدار من قاعدة البيانات). الحدّ الأدنى بعملة المحاسبة؛ المستوى يُشتقّ دائمًا من صافي الدعم المؤكَّد ولا يُسنَد يدويًا لداعم.
 * تعديل المستويات صلاحية sponsorships.manage ويُدقَّق (صفحات الإنشاء/التعديل) ويُبطل الكاش تلقائيًا (خطاف النموذج). لا حذف: يُعطَّل المستوى بدل حذفه.
 */
class SupporterTierResource extends Resource
{
    protected static ?string $model = SupporterTier::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'مستويات الداعمين';

    protected static ?string $modelLabel = 'مستوى';

    protected static ?string $pluralModelLabel = 'مستويات الداعمين';

    protected static ?int $navigationSort = 93;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(60),
            Forms\Components\TextInput::make('slug')->label('المعرّف')->required()->maxLength(40)->alphaDash()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('minimum_decimal')->label('الحدّ الأدنى بعملة المحاسبة ('.SponsorshipMoney::accountingCurrency().')')->required()->maxLength(20),
            Forms\Components\TextInput::make('sort_order')->label('الترتيب')->numeric()->default(0),
            Forms\Components\Select::make('badge_key')->label('الشارة')->options(['supporter' => 'داعم', 'silver' => 'فضي', 'gold' => 'ذهبي', 'platinum' => 'بلاتيني', 'diamond' => 'ماسي'])->required()->default('supporter'),
            Forms\Components\Toggle::make('is_active')->label('نشط')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('minimum_accounting_amount_minor')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الاسم'),
                Tables\Columns\TextColumn::make('minimum_accounting_amount_minor')->label('الحدّ الأدنى')->state(fn (SupporterTier $r) => SponsorshipMoney::format((int) $r->minimum_accounting_amount_minor)),
                Tables\Columns\TextColumn::make('badge_key')->label('الشارة'),
                Tables\Columns\IconColumn::make('is_active')->label('نشط')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupporterTiers::route('/'),
            'create' => Pages\CreateSupporterTier::route('/create'),
            'edit' => Pages\EditSupporterTier::route('/{record}/edit'),
        ];
    }
}
