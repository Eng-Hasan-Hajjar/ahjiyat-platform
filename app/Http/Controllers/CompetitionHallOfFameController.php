<?php

namespace App\Http\Controllers;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Services\Social\PlayerCardLoader;
use Illuminate\Http\Request;

/**
 * قاعة الأمجاد (E18-D): **المنافسات المعتمَدة فقط** (status = completed). لا مسوّدات ولا جارية ولا ملغاة، ولا ترتيب حي. لكل حدث: الفائز والمراكز الثلاثة
 * الأولى (نتائج صحيحة بمراكز نهائية). استعلامان ثابتان للصفحة (الأحداث، ثم أبطالها دفعة واحدة) فلا N+1. فلاتر: السنة وعنوان الحدث. قراءة فقط؛ روابط
 * الملفات بحسب رؤية كل ملف. بلا كاش: الاستعلامات مفهرسة ومرقَّمة (12/صفحة).
 */
class CompetitionHallOfFameController extends Controller
{
    public function index(Request $request, PlayerCardLoader $cards)
    {
        $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'q' => ['nullable', 'string', 'max:60']]);

        $term = trim((string) $request->query('q', ''));
        $year = $request->filled('year') ? (int) $request->query('year') : null;
        $finalized = fn () => CompetitiveEvent::query()->where('status', CompetitiveEvent::STATUS_COMPLETED);

        $events = $finalized()
            ->when($year, fn ($q) => $q->whereBetween('ends_at', [now()->setDate($year, 1, 1)->startOfDay(), now()->setDate($year, 12, 31)->endOfDay()]))
            ->when($term !== '', fn ($q) => $q->whereRaw("LOWER(title) like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%']))
            ->orderByDesc('ends_at')->orderByDesc('id')
            ->paginate((int) config('competitive.hall_of_fame_per_page', 12))->withQueryString();

        $podium = CompetitiveEventResult::query()
            ->whereIn('competitive_event_id', $events->pluck('id'))->where('is_correct', true)->where('final_rank', '<=', 3)
            ->with('user:id,name,public_id,profile_visibility')->orderBy('final_rank')->get()->groupBy('competitive_event_id');

        $cards->attach($podium->flatten()->pluck('user')->filter()->unique('id'), $request->user());

        $range = $finalized()->selectRaw('min(ends_at) as first_end, max(ends_at) as last_end')->first();
        $years = $range?->first_end === null ? [] : range((int) date('Y', strtotime($range->last_end)), (int) date('Y', strtotime($range->first_end)));

        return view('competitions.hall-of-fame', ['events' => $events, 'podium' => $podium, 'years' => $years, 'year' => $year, 'term' => $term]);
    }
}
