<?php

namespace App\View\Components;

use App\Models\CreatorProfile;
use App\Models\SeasonWriterEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * E44 - سجل «دوري الكتّاب» على صفحة المبدع العامة: المواسم المُنهاة التي شارك فيها هذا المبدع (آخر 5) مع المركز للثلاثة الأوائل فقط.
 * سياسة عرض: **لا يُنشر «خارج الموسم التالي» على الملف الشخصي** (قاعدة تنظيم قائمة لا عقوبة، وتبقى ظاهرة في ترتيب الموسم نفسه)؛ بقية المشاركين يظهر لهم «شارك في دوري الموسم» فقط.
 * يعتمد على لقطة الإنهاء الثابتة فلا يتغير بأثر رجعي. معزول الفشل ولا يُخرج شيئًا بلا سجل.
 */
class CreatorSeasonalRecord extends Component
{
    /** @var list<array{season: string, url: string, label: string, place: ?int}> */
    public array $records = [];

    public function __construct(public CreatorProfile $creator)
    {
        try {
            $labels = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث'];

            $rows = DB::table('season_writer_entries as e')
                ->join('season_recognition_settings as st', 'st.season_id', '=', 'e.season_id')
                ->join('seasons as s', 's.id', '=', 'e.season_id')
                ->join('campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('e.creator_profile_id', $creator->id)
                ->where('e.status', SeasonWriterEntry::STATUS_ACTIVE)
                ->whereNotNull('st.writers_finalized_at')
                ->whereNotNull('e.finalized_at')
                ->where('s.is_published', true)
                ->orderByDesc('e.finalized_at')->orderByDesc('e.id')
                ->limit(5)
                ->get(['s.slug as season_slug', 'c.title as season_title', 'e.final_rank as pos']);

            foreach ($rows as $r) {
                $place = in_array((int) $r->pos, [1, 2, 3], true) ? (int) $r->pos : null;
                $this->records[] = [
                    'season' => (string) $r->season_title,
                    'url' => route('seasonal.writers.show', ['season' => $r->season_slug]),
                    'label' => $place !== null ? 'المركز '.$labels[$place] : 'شارك في دوري الموسم',
                    'place' => $place,
                ];
            }
        } catch (\Throwable $e) {
            report($e);
            $this->records = [];
        }
    }

    public function render(): View
    {
        return view('components.creator-seasonal-record');
    }
}
