<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EditorialShelfResource\Pages;
use App\Models\EditorialShelf;
use App\Services\Editorial\EditorialContentType;
use App\Services\Editorial\EditorialShelfResolver;
use App\Services\Editorial\EditorialShelfService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * E35 - الأرفف التحريرية (فريق المنصة). الإنشاء/التعديل/العناصر/التفعيل/الأرشفة كلها عبر EditorialShelfService (editorial.manage + قفل + تدقيق + تفريغ كاش).
 * اختيار المحتوى بحقل **بحث خادمي محدود** (حتى 20 نتيجة بالعنوان للنوع المختار عبر EditorialShelfResolver::search): لا تحميل لكل السجلات ولا ::all().
 * لا حذف: الأرشفة مسار نهائي يحفظ التاريخ. المؤرشف لا يُعدَّل. لا HTML ولا روابط ولا أسماء أصناف تُخزَّن؛ العناصر (نوع مغلق + معرّف) تُحَلّ وقت القراءة.
 */
class EditorialShelfResource extends Resource
{
    protected static ?string $model = EditorialShelf::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'الأرفف التحريرية';

    protected static ?string $modelLabel = 'رف تحريري';

    protected static ?string $pluralModelLabel = 'الأرفف التحريرية';

    protected static ?int $navigationSort = 68;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return parent::canEdit($record) && $record->status !== EditorialShelf::STATUS_ARCHIVED;
    }

    public static function form(Form $form): Form
    {
        $cap = (int) config('collections.shelves.max_items_cap');

        return $form->schema([
            Forms\Components\Section::make('الرف')->columns(2)->schema([
                Forms\Components\TextInput::make('internal_key')->label('المفتاح الداخلي')->required()->maxLength(64)->regex('/^[a-z0-9][a-z0-9_-]{2,63}$/')
                    ->helperText('أحرف لاتينية صغيرة وأرقام وشرطات، ثلاثة أحرف على الأقل. للاستعمال الداخلي فقط.')->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Select::make('placement')->label('الموضع')->native(false)->required()->options(EditorialShelf::PLACEMENTS)->default(EditorialShelf::PLACEMENT_DISCOVER),
                Forms\Components\TextInput::make('title')->label('العنوان (يراه الزوار)')->required()->minLength(2)->maxLength(80)->helperText('نص عادي بلا HTML.'),
                Forms\Components\TextInput::make('subtitle')->label('العنوان الفرعي (اختياري)')->maxLength(160),
                Forms\Components\DateTimePicker::make('starts_at')->label('يبدأ (اختياري)')->helperText('بتوقيت المنصة (UTC). الغياب = من التفعيل.'),
                Forms\Components\DateTimePicker::make('ends_at')->label('ينتهي (اختياري)')->after('starts_at')->helperText('يُقيَّم وقت القراءة؛ بعد الانتهاء يختفي الرف دون مهمة مجدولة.'),
                Forms\Components\TextInput::make('sort_order')->label('الترتيب (الأصغر أولًا)')->numeric()->minValue(0)->maxValue(999)->default(0),
                Forms\Components\TextInput::make('max_items')->label('أقصى عدد عناصر معروضة')->numeric()->minValue(1)->maxValue($cap)->default((int) config('collections.shelves.default_max_items')),
            ]),
            Forms\Components\Section::make('العناصر')->schema([
                Forms\Components\Repeater::make('items')->label('عناصر الرف (بالترتيب)')->reorderable()->maxItems($cap)->addActionLabel('إضافة عنصر')->defaultItems(0)->columns(2)
                    ->helperText('يُعاد فحص أهلية كل عنصر عند العرض: المخفي أو المؤرشف أو المحظور على الزائر يسقط تلقائيًا.')
                    ->schema([
                        Forms\Components\Select::make('type')->label('النوع')->native(false)->required()->options(EditorialContentType::options())->live()
                            ->afterStateUpdated(fn (Set $set) => $set('content_id', null)),
                        Forms\Components\Select::make('content_id')->label('المحتوى (ابحث بالعنوان)')->required()->searchable()
                            ->getSearchResultsUsing(function (string $search, Get $get): array {
                                $type = EditorialContentType::tryFrom((string) $get('type'));

                                return $type === null || trim($search) === '' ? [] : app(EditorialShelfResolver::class)->search($type, $search);
                            })
                            ->getOptionLabelUsing(function ($value, Get $get): ?string {
                                $type = EditorialContentType::tryFrom((string) $get('type'));

                                return $type === null ? null : (app(EditorialShelfResolver::class)->label($type, (int) $value) ?? 'غير متاح حاليًا');
                            }),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('items'))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(20)
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('الرف')->searchable()->limit(40)->description(fn (EditorialShelf $r) => $r->internal_key),
                Tables\Columns\TextColumn::make('placement')->label('الموضع')->badge()->formatStateUsing(fn ($state) => EditorialShelf::PLACEMENTS[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => EditorialShelf::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'draft' => 'warning'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('items_count')->label('العناصر')->numeric(),
                Tables\Columns\TextColumn::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('sort_order')->label('ترتيب')->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(EditorialShelf::STATUSES),
                Tables\Filters\SelectFilter::make('placement')->label('الموضع')->options(EditorialShelf::PLACEMENTS),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                self::transition('activate', 'تفعيل', 'success', EditorialShelf::STATUS_DRAFT, fn (EditorialShelfService $s, EditorialShelf $r) => $s->activate(auth()->user(), $r), 'فُعّل الرف (يظهر ضمن نافذة جدولته).'),
                self::transition('archive', 'أرشفة', 'danger', null, fn (EditorialShelfService $s, EditorialShelf $r) => $s->archive(auth()->user(), $r), 'أُرشف الرف وأُزيل من الصفحات فورًا.'),
            ]);
    }

    /** إجراء تغيير حالة: يظهر لـeditorial.manage فقط، ولا يظهر على مؤرشف. $onlyFrom = حالة مطلوبة أو null لأي حالة غير مؤرشفة. */
    protected static function transition(string $name, string $label, string $color, ?string $onlyFrom, \Closure $call, string $ok): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)->label($label)->color($color)->requiresConfirmation()
            ->visible(fn (EditorialShelf $r) => $r->status !== EditorialShelf::STATUS_ARCHIVED && ($onlyFrom === null || $r->status === $onlyFrom) && (auth()->user()?->can('editorial.manage') ?? false))
            ->action(function (EditorialShelf $record) use ($call, $ok) {
                try {
                    $call(app(EditorialShelfService::class), $record);
                    Notification::make()->success()->title($ok)->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEditorialShelves::route('/'),
            'create' => Pages\CreateEditorialShelf::route('/create'),
            'edit' => Pages\EditEditorialShelf::route('/{record}/edit'),
        ];
    }
}
