<?php

namespace App\View\Components;

use App\Models\Season;
use App\Models\SeasonWriterEntry;
use App\Services\SeasonalRecognition\SeasonalRecognitionVisibility;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * E44 - مدخلا «كتّاب الموسم» و«داعمو الموسم» في صفحة الموسم. عرض فقط، وقرار الظهور من مصدر واحد (SeasonalRecognitionVisibility).
 * معزول الفشل: أي استثناء ⇒ لا شيء يُرسم (صفحة الموسم لا تتعطل بالتقدير الموسمي). موسم بلا دوري/تقدير ⇒ صفر إخراج، فتبقى الصفحة كما كانت.
 * عند الإنهاء يُعرض منصة التتويج (الأسماء العامة للثلاثة الأوائل) فقط؛ لا خروج من القائمة ولا هوية ناخب.
 */
class SeasonalRecognitionStrip extends Component
{
    /** @var array{writers: ?array<string, mixed>, supporters: ?array<string, mixed>, any: bool} (لا يُسمّى data: اسم محجوز في View\Component فلا يصل للقالب) */
    public array $recognition = ['writers' => null, 'supporters' => null, 'any' => false];

    public function __construct(public Season $season)
    {
        try {
            $visibility = app(SeasonalRecognitionVisibility::class);
            $viewer = Auth::user();
            $setting = $visibility->setting($season);

            if ($setting === null) {
                return;
            }

            if ($visibility->writersAvailable($season, $viewer)) {
                $finalized = $setting->writers_finalized_at !== null;
                $podium = [];

                if ($finalized) {
                    $podium = SeasonWriterEntry::query()
                        ->join('users', 'users.id', '=', 'season_writer_entries.user_id')
                        ->join('creator_profiles', 'creator_profiles.id', '=', 'season_writer_entries.creator_profile_id')
                        ->where('season_writer_entries.season_id', $season->id)
                        ->whereIn('season_writer_entries.final_rank', [1, 2, 3])
                        ->where('creator_profiles.status', 'approved')
                        ->where('users.is_frozen', false)
                        ->orderBy('season_writer_entries.final_rank')->orderBy('season_writer_entries.id')
                        ->limit(3)
                        ->get(['users.name as writer_name', 'creator_profiles.slug as writer_slug', 'season_writer_entries.final_rank as pos'])
                        ->map(fn ($r) => ['pos' => (int) $r->pos, 'name' => (string) $r->writer_name, 'slug' => (string) $r->writer_slug])->all();
                }

                $this->recognition['writers'] = [
                    'url' => route('seasonal.writers.show', $season),
                    'finalized' => $finalized,
                    'podium' => $podium,
                ];
            }

            if ($visibility->supportersAvailable($season, $viewer)) {
                $this->recognition['supporters'] = [
                    'url' => route('seasonal.supporters.show', $season),
                    'finalized' => $setting->supporters_finalized_at !== null,
                ];
            }

            $this->recognition['any'] = $this->recognition['writers'] !== null || $this->recognition['supporters'] !== null;
        } catch (\Throwable $e) {
            report($e);
            $this->recognition = ['writers' => null, 'supporters' => null, 'any' => false];
        }
    }

    public function render(): View
    {
        return view('components.seasonal-recognition-strip');
    }
}
