<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use App\Services\SeasonLifecycleService;
use App\Services\Seasons\SeasonAnalyticsService;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use App\Services\SeasonReadinessService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditSeason extends EditRecord
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        $user = fn () => auth()->user();

        return [
            Actions\Action::make('readiness')
                ->label('جاهزية النشر')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->modalHeading('جاهزية نشر الموسم')
                ->modalContent(fn () => view('filament.resources.season-readiness', ['report' => app(SeasonReadinessService::class)->report($this->record->fresh('campaign'))]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق'),

            Actions\Action::make('analytics')
                ->label('تحليلات الموسم')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->visible(fn () => $user()?->can('seasons.view') ?? false)
                ->modalHeading('تحليلات الموسم (تجميعات فقط)')
                ->modalContent(fn () => view('filament.resources.season-analytics', ['stats' => app(SeasonAnalyticsService::class)->overview($user(), $this->record)]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق'),

            Actions\Action::make('publish')
                ->label('نشر الموسم')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('يمرّ النشر ببوابة الجاهزية نفسها. التحذيرات لا تمنع النشر، والحواجز تمنعه.')
                ->visible(fn () => ! $this->record->is_published && ($user()?->can('seasons.publish') ?? false))
                ->action(fn () => $this->transition(fn (SeasonBuilderService $b) => $b->publish($user(), $this->record), 'نُشر الموسم.')),

            Actions\Action::make('unpublish')
                ->label('إيقاف النشر')
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->is_published && ($user()?->can('seasons.publish') ?? false))
                ->action(fn () => $this->transition(fn (SeasonBuilderService $b) => $b->unpublish($user(), $this->record), 'أُوقف نشر الموسم.')),

            Actions\Action::make('archive')
                ->label('أرشفة الموسم')
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('ينهي الموسم الجاري الآن ويجمّد نافذته وينقله للأرشيف، ولا يحذف أي تقدّم أو بيانات. لا يمكن تعديل موسم مؤرشف.')
                ->visible(fn () => in_array(app(SeasonLifecycleService::class)->state($this->record), [SeasonLifecycleService::STATE_LIVE, SeasonLifecycleService::STATE_ENDED], true) && ($user()?->can('seasons.publish') ?? false))
                ->action(fn () => $this->transition(fn (SeasonBuilderService $b) => $b->archive($user(), $this->record), 'أُرشف الموسم.')),

            Actions\Action::make('preview')
                ->label('معاينة الموسم')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn () => route('seasons.show', $this->record))
                ->openUrlInNewTab(),

            // موسم بدأ فعلًا لا يُحذف (يُؤرشف): الحارس في النموذج، والزر مخفي له.
            Actions\DeleteAction::make()->visible(fn () => $this->record->went_live_at === null),
        ];
    }

    /**
     * موسم قديم بلا theme_config (أُنشئ بالمصنع/البذور) كان يرفض الحفظ لأن نمط الثيم «مطلوب» ولا قيمة افتراضية في التحرير. الافتراضي الفعلي عند العرض
     * هو generic (Season::themePreset)، فنُظهره في النموذج كي يُحفظ الموسم القديم دون اضطرار المحرّر لاختيار نمط.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $theme = is_array($data['theme_config'] ?? null) ? $data['theme_config'] : [];
        $theme['preset'] = $theme['preset'] ?? 'generic';
        $data['theme_config'] = $theme;

        return $data;
    }

    protected function transition(\Closure $call, string $ok): void
    {
        try {
            $call(app(SeasonBuilderService::class));
            $this->record->refresh();
            $this->refreshFormData(['is_published', 'starts_at', 'ends_at', 'archive_at']);
            Notification::make()->success()->title($ok)->send();
        } catch (SeasonException $e) {
            Notification::make()->danger()->title($e->getMessage())->body($e->details === [] ? null : implode(' • ', $e->details))->persistent()->send();
        }
    }

    /**
     * التحقق قبل أي حفظ: الثيم (قائمة بيضاء)، نافذة الموسم (ترتيب/مراحل/أقفال ما بدأ)، وبوابة النشر عند الانتقال من «غير منشور» إلى «منشور» فقط
     * (صلاحية seasons.publish + الجاهزية) - الحفظ العادي لموسم منشور لا يوقف نشره بالخطأ.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $builder = app(SeasonBuilderService::class);

        try {
            if (array_key_exists('theme_config', $data)) {
                $data['theme_config'] = $builder->normalizeTheme($data['theme_config']);
            }

            foreach ($builder->normalizeSchedule($this->record, $this->record->campaign, Arr::only($data, ['starts_at', 'ends_at', 'announcement_at', 'archive_at'])) as $key => $value) {
                $data[$key] = $value;
            }
        } catch (SeasonException $e) {
            Notification::make()->danger()->title($e->getMessage())->body($e->details === [] ? null : implode(' • ', $e->details))->persistent()->send();
            $this->halt();
        }

        if (($data['is_published'] ?? false) && ! $this->record->is_published) {
            if (! (auth()->user()?->can('seasons.publish') ?? false)) {
                $data['is_published'] = false;

                Notification::make()->warning()->title('لا تملك صلاحية نشر الموسم')->body('حُفظت بقية التعديلات دون نشر.')->persistent()->send();

                return $data;
            }

            $readiness = app(SeasonReadinessService::class)->check($this->record);

            if (! $readiness['ready']) {
                $data['is_published'] = false;

                Notification::make()
                    ->warning()
                    ->title('لم يُحفَظ نشر الموسم')
                    ->body('توجد عناصر تحتاج استكمالاً قبل النشر العام: '.implode(' • ', $readiness['issues']))
                    ->persistent()
                    ->send();
            }
        }

        return $data;
    }
}
