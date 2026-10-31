<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiKnowledgeArticleResource\Pages;
use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use App\Services\AiAssistant\Knowledge\KnowledgeSafetyException;
use App\Services\AiAssistant\Safety\AiLinkPolicy;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * E45 - مقالات معرفة «تطوّر AI» (دليل المنصة). **كتابة بشرية ومراجعة** قبل النشر: لا استيراد آلي لمجلد الوثائق.
 *  - الحقول التحريرية فقط (الحالة/النسخة/البصمة وتواريخ النشر تكتبها KnowledgePublisher وحدها).
 *  - تعديل مقال منشور يعيده **مسودة** تلقائيًا (لا يُخدَم محتوى غير مراجَع)؛ النشر إجراء صريح يعيد فحص الأمان (أسرار/حقول إجابات/أوامر تشغيل/روابط خارجية...).
 *  - النشر والسحب بصلاحية ai-assistant.knowledge.publish (تُعاد في الإجراء نفسه)؛ لا حذف.
 *  - مرجع المصدر إداري للتتبع فقط: لا يُعرَض للمستخدم ولا يُرسَل للنموذج.
 */
class AiKnowledgeArticleResource extends Resource
{
    protected static ?string $model = AiKnowledgeArticle::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'المشاركة';

    protected static ?string $navigationLabel = 'معرفة تطوّر AI';

    protected static ?string $modelLabel = 'مقال معرفة';

    protected static ?string $pluralModelLabel = 'مقالات معرفة تطوّر AI';

    protected static ?int $navigationSort = 76;

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $slugRules = [fn (): Closure => function (string $attribute, $value, Closure $fail): void {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) !== 1) {
                $fail('المعرّف أحرف لاتينية صغيرة وأرقام وشرطات فقط.');
            }
        }];

        return $form->schema([
            Forms\Components\Section::make('المقال')->columns(2)->schema([
                Forms\Components\TextInput::make('slug')->label('المعرّف (في رابط الدليل)')->required()->maxLength(120)->rules($slugRules)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('locale', 'ar'))
                    ->disabled(fn (?AiKnowledgeArticle $record) => $record !== null && $record->published_at !== null)->dehydrated()
                    ->helperText('لا يتغير بعد أول نشر (روابط عامة).')->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('title')->label('العنوان')->required()->minLength(3)->maxLength(160),
                Forms\Components\Textarea::make('summary')->label('الملخص (سطر أو سطران يجيبان عن «ما هذا؟»)')->required()->minLength(10)->maxLength(400)->rows(3)->columnSpanFull(),
                Forms\Components\Select::make('area')->label('المجال')->native(false)->required()->options(AiKnowledgeArticle::AREAS)->default('general'),
                Forms\Components\Select::make('audience')->label('الجمهور')->native(false)->required()->options(['public' => 'الجميع', 'member' => 'الأعضاء الموثَّقون فقط'])->default('public'),
                Forms\Components\TextInput::make('tags')->label('كلمات مفتاحية (مفصولة بفاصلة)')->maxLength(300)->helperText('تحسّن الاسترجاع: مرادفات وأسماء بديلة يكتبها المستخدمون.'),
                Forms\Components\TextInput::make('canonical_path')->label('الصفحة المرتبطة داخل المنصة')->maxLength(190)->extraInputAttributes(['dir' => 'ltr'])
                    ->rules([fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if ($value !== null && $value !== '' && ! AiLinkPolicy::isInternalPath((string) $value)) {
                            $fail('مسار داخلي نسبي فقط، مثل /puzzles (بلا رابط خارجي أو مسافات).');
                        }
                    }])->helperText('مسار داخلي نسبي فقط (يبدأ بـ«/»). تظهر منه شريحة المصدر للمستخدم.'),
                Forms\Components\TextInput::make('source_label')->label('اسم المصدر الظاهر')->required()->maxLength(60)->default('دليل المنصة'),
                Forms\Components\Select::make('source_type')->label('نوع المصدر')->native(false)->required()->options(['curated' => 'مكتوب يدويًا', 'distilled' => 'مستخلص من وثيقة بعد المراجعة'])->default('curated'),
                Forms\Components\TextInput::make('source_reference')->label('مرجع إداري (لا يُعرَض ولا يُرسَل للنموذج)')->maxLength(190),
            ]),
            Forms\Components\Section::make('النص')->schema([
                Forms\Components\Textarea::make('body')->label('النص')->required()->rows(18)
                    ->helperText('نص عادي فقط: «## عنوان» لقسم، و«- » لنقطة. بلا HTML ولا روابط خارجية ولا أسرار ولا أوامر تشغيل ولا حلول أحجيات. تتحقق خدمة النشر من ذلك.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('area')
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('المقال')->searchable()->limit(48)->description(fn (AiKnowledgeArticle $r) => $r->slug),
                Tables\Columns\TextColumn::make('area')->label('المجال')->badge()->formatStateUsing(fn ($state) => AiKnowledgeArticle::AREAS[$state] ?? $state),
                Tables\Columns\TextColumn::make('audience')->label('الجمهور')->badge()->formatStateUsing(fn ($state) => $state === 'member' ? 'الأعضاء' : 'الجميع'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => ['draft' => 'مسودة', 'published' => 'منشور', 'retired' => 'مسحوب'][$state] ?? $state)
                    ->color(fn ($state) => ['published' => 'success', 'draft' => 'warning'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('version')->label('النسخة')->numeric(),
                Tables\Columns\TextColumn::make('published_at')->label('نُشر')->dateTime('Y-m-d H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('الحالة')->options(['draft' => 'مسودة', 'published' => 'منشور', 'retired' => 'مسحوب']),
                Tables\Filters\SelectFilter::make('area')->label('المجال')->options(AiKnowledgeArticle::AREAS),
                Tables\Filters\SelectFilter::make('audience')->label('الجمهور')->options(['public' => 'الجميع', 'member' => 'الأعضاء']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('publish')->label('نشر')->color('success')->requiresConfirmation()
                    ->modalDescription('يُفحص المقال أمنيًا ثم يُفهرَس ويصبح مصدرًا للمساعد وصفحات الدليل فور النشر.')
                    ->visible(fn (AiKnowledgeArticle $r) => $r->status === AiKnowledgeArticle::STATUS_DRAFT && (auth()->user()?->can('ai-assistant.knowledge.publish') ?? false))
                    ->action(function (AiKnowledgeArticle $record): void {
                        abort_unless(auth()->user()?->can('ai-assistant.knowledge.publish'), 403);

                        try {
                            app(KnowledgePublisher::class)->publish($record, auth()->user());
                            Notification::make()->success()->title('نُشر المقال وفُهرس.')->send();
                        } catch (KnowledgeSafetyException $e) {
                            Notification::make()->danger()->title('لا يصلح للنشر')->body(implode('، ', $e->violations !== [] ? $e->violations : ['بيانات ناقصة أو غير صالحة']))->send();
                        }
                    }),
                Tables\Actions\Action::make('retire')->label('سحب')->color('danger')->requiresConfirmation()
                    ->modalDescription('يُزال المقال من المساعد ومن صفحات الدليل فورًا، ويبقى محفوظًا للمراجعة.')
                    ->visible(fn (AiKnowledgeArticle $r) => $r->status === AiKnowledgeArticle::STATUS_PUBLISHED && (auth()->user()?->can('ai-assistant.knowledge.publish') ?? false))
                    ->action(function (AiKnowledgeArticle $record): void {
                        abort_unless(auth()->user()?->can('ai-assistant.knowledge.publish'), 403);
                        app(KnowledgePublisher::class)->retire($record, auth()->user());
                        Notification::make()->success()->title('سُحب المقال من الخدمة.')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiKnowledgeArticles::route('/'),
            'create' => Pages\CreateAiKnowledgeArticle::route('/create'),
            'edit' => Pages\EditAiKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
