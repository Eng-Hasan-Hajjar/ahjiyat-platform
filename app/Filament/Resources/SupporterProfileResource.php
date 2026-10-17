<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupporterProfileResource\Pages;
use App\Models\SupporterProfile;
use App\Models\User;
use App\Services\Sponsorship\SupporterLogoService;
use App\Services\Sponsorship\SupporterTierService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E30 - ملفات الداعمين (أفراد/مؤسسات). عرض sponsorships.view، إنشاء/تعديل sponsorships.manage (سياسة SupporterProfilePolicy). لا حذف (تاريخ مالي مرتبط)؛ الإيقاف يخفي الملف علنًا.
 * الكتابة كلها عبر SupporterService (تحقق + تدقيق + ربط الشعار الآمن)، لا عبر حفظ Filament الخام. لا تفاصيل دفع بأي حقل. المستوى مشتقّ للعرض فقط.
 */
class SupporterProfileResource extends Resource
{
    protected static ?string $model = SupporterProfile::class;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'الرعاية والدعم';

    protected static ?string $navigationLabel = 'الداعمون';

    protected static ?string $modelLabel = 'داعم';

    protected static ?string $pluralModelLabel = 'الداعمون';

    protected static ?int $navigationSort = 90;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('الهوية')->columns(2)->schema([
                Forms\Components\TextInput::make('display_name')->label('الاسم المعروض')->required()->minLength(2)->maxLength((int) config('sponsorship.profile.name_max')),
                Forms\Components\Select::make('type')->label('النوع')->options(['individual' => 'فرد', 'organization' => 'مؤسسة / شركة'])->required()->default('individual'),
                Forms\Components\Textarea::make('description')->label('نبذة (نص عادي)')->rows(3)->maxLength((int) config('sponsorship.profile.description_max'))->columnSpanFull(),
                Forms\Components\TextInput::make('website_url')->label('الموقع (http/https فقط)')->maxLength((int) config('sponsorship.profile.url_max'))->url()->columnSpanFull(),
                Forms\Components\FileUpload::make('logo')->label('الشعار (JPEG / PNG / WEBP)')->image()->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize((int) config('sponsorship.logo.max_kb'))->disk('public')->directory('supporters')->dehydrated(),
                Forms\Components\Toggle::make('remove_logo')->label('إزالة الشعار الحالي')->visibleOn('edit'),
            ]),
            Forms\Components\Section::make('الظهور والخصوصية')->columns(2)->schema([
                Forms\Components\Select::make('status')->label('الحالة')->options(['active' => 'نشط', 'suspended' => 'موقوف'])->default('active')->required(),
                Forms\Components\Toggle::make('is_public')->label('ملف علني')->default(true),
                Forms\Components\Toggle::make('anonymous_by_default')->label('مساهماته مجهولة افتراضيًا')->default(false),
                Forms\Components\Select::make('user_id')->label('حساب لاعب مرتبط (اختياري)')->searchable()->placeholder('— بلا ربط —')
                    ->getSearchResultsUsing(fn (string $search) => User::query()->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('email', 'like', addcslashes($search, '%_\\').'%')->limit(20)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => User::query()->whereKey($value)->value('name')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tiers = app(SupporterTierService::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('supporter_profiles.*')->selectSub(
                'select COALESCE(SUM(c.accounting_amount_minor), 0) from sponsorship_contributions c where c.supporter_profile_id = supporter_profiles.id and c.status = \'confirmed\'',
                'net_minor',
            ))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('display_name')->label('الاسم')->searchable()->limit(40),
                Tables\Columns\TextColumn::make('type')->label('النوع')->formatStateUsing(fn ($state) => $state === 'organization' ? 'مؤسسة' : 'فرد'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state === 'active' ? 'نشط' : 'موقوف')->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
                Tables\Columns\IconColumn::make('is_public')->label('علني')->boolean(),
                Tables\Columns\TextColumn::make('tier')->label('المستوى (مشتقّ)')->state(fn (SupporterProfile $r) => $tiers->forAmount((int) $r->net_minor)?->name ?? '—'),
                Tables\Columns\TextColumn::make('net_minor')->label('صافي المؤكَّد')->state(fn (SupporterProfile $r) => \App\Services\Sponsorship\SponsorshipMoney::format((int) $r->net_minor))
                    ->visible(fn () => auth()->user()?->can('sponsorships.view') ?? false),
                Tables\Columns\TextColumn::make('created_at')->label('أُنشئ')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('النوع')->options(['individual' => 'فرد', 'organization' => 'مؤسسة']),
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['active' => 'نشط', 'suspended' => 'موقوف']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultPaginationPageOption(20);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupporterProfiles::route('/'),
            'create' => Pages\CreateSupporterProfile::route('/create'),
            'edit' => Pages\EditSupporterProfile::route('/{record}/edit'),
        ];
    }

    /** يستخرج ملف الشعار المرفوع (إن وُجد) من حالة النموذج. يقبل كائنًا أو مصفوفة مفاتيحها uuid (سلوك Filament عند storeFiles(false)). */
    public static function extractLogo(mixed $state): ?\Illuminate\Http\UploadedFile
    {
        if (is_array($state)) {
            $state = reset($state) ?: null;
        }

        return $state instanceof \Illuminate\Http\UploadedFile ? $state : null;
    }
}
