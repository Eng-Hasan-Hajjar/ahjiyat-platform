<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use App\Models\Campaign;
use App\Models\Season;
use App\Services\Seasons\SeasonBuilderService;
use App\Services\Seasons\SeasonException;
use App\Services\SeasonReadinessService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateSeason extends CreateRecord
{
    protected static string $resource = SeasonResource::class;

    /**
     * Publish Safety: لا نسمح بحفظ is_published=true إذا كانت الجاهزية غير
     * مكتملة (Blocking Technical Placeholder، بنية ناقصة...) أو لا صلاحية نشر - نُعيدها false
     * ونُنبّه Admin بدل حجب الحفظ بالكامل (Admin Preview يبقى يعمل دائماً).
     * E42: الثيم والنافذة يُتحقَّق منهما قبل الإنشاء (قائمة بيضاء، ترتيب التواريخ).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $builder = app(SeasonBuilderService::class);
        $campaign = ($data['campaign_id'] ?? null) ? Campaign::find($data['campaign_id']) : null;

        try {
            if (array_key_exists('theme_config', $data)) {
                $data['theme_config'] = $builder->normalizeTheme($data['theme_config']);
            }

            foreach ($builder->normalizeSchedule(null, $campaign, Arr::only($data, ['starts_at', 'ends_at', 'announcement_at', 'archive_at'])) as $key => $value) {
                $data[$key] = $value;
            }
        } catch (SeasonException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $this->halt();
        }

        if (($data['is_published'] ?? false) && $campaign !== null) {
            if (! (auth()->user()?->can('seasons.publish') ?? false)) {
                $data['is_published'] = false;

                Notification::make()->warning()->title('لا تملك صلاحية نشر الموسم')->body('أُنشئ الموسم كمسودة.')->persistent()->send();

                return $data;
            }

            $tempSeason = new Season($data);
            $tempSeason->setRelation('campaign', $campaign);

            $readiness = app(SeasonReadinessService::class)->check($tempSeason);

            if (! $readiness['ready']) {
                $data['is_published'] = false;

                Notification::make()
                    ->warning()
                    ->title('لم يُنشَر الموسم بعد')
                    ->body('توجد عناصر تحتاج استكمالاً قبل النشر العام: '.implode(' • ', $readiness['issues']))
                    ->persistent()
                    ->send();
            }
        }

        return $data;
    }
}
