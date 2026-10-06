<?php

namespace App\Services\Competitive;

use App\Models\CompetitiveEvent;
use App\Models\CompetitiveEventResult;
use App\Models\User;
use App\Services\Social\FriendshipService;
use App\Services\Social\PlayerCardLoader;

/**
 * ترتيب المنافسة (E17-D): كله بالسيرفر ومن النتائج المحسوبة بالسيرفر. الترتيب الكامل المستقر: النقاط تنازليًا ← المدة تصاعديًا ← وقت الإكمال ←
 * المعرّف (لا يتغيّر ترتيب المتساوين عشوائيًا). نطاقان: عام، وأصدقاء = أنا + أصدقائي المقبولون فقط (لا pending ولا محظور ولا مُزال: E16).
 * رتبة المستخدم تُحسب باستعلام عدّ واحد (حتى خارج الصفحة الأولى). حقول عامة فقط: الاسم وpublic_id وأفاتار؛ لا بريد ولا محفظة ولا حالة أمنية.
 * قبل الاعتماد النهائي تُعرض "ترتيب مؤقت" وبعده "نتائج نهائية". لا كاش (استعلام مفهرس بصفحة محدودة).
 */
class CompetitiveLeaderboardService
{
    public function __construct(protected FriendshipService $friends, protected PlayerCardLoader $cards) {}

    public function scopeFor(?User $viewer, ?string $requested): string
    {
        return $viewer !== null && $requested === 'friends' ? 'friends' : 'global';
    }

    /** @return array{paginator: \Illuminate\Pagination\LengthAwarePaginator, rows: \Illuminate\Support\Collection, my_rank: ?int, my_result: ?CompetitiveEventResult, is_final: bool, scope: string} */
    public function page(CompetitiveEvent $event, ?User $viewer, string $scope): array
    {
        $ids = $scope === 'friends' && $viewer !== null ? [...$this->friends->friendIds($viewer), $viewer->getKey()] : null;
        $base = fn () => CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())
            ->when($ids !== null, fn ($q) => $q->whereIn('user_id', $ids));

        $paginator = $this->ordered($base())->with('user:id,name,public_id,profile_visibility')
            ->paginate((int) config('competitive.leaderboard_per_page', 20))->withQueryString();

        $this->cards->attach($paginator->getCollection()->pluck('user')->filter(), $viewer);

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $rows = $paginator->getCollection()->values()->map(fn (CompetitiveEventResult $r, int $i) => [
            'rank' => $offset + $i + 1,
            'result' => $r,
            'user' => $r->user,
            'is_me' => $viewer !== null && $r->user_id === $viewer->getKey(),
        ]);

        $mine = $viewer === null ? null : CompetitiveEventResult::query()->where('competitive_event_id', $event->getKey())->where('user_id', $viewer->getKey())->first();

        return [
            'paginator' => $paginator,
            'rows' => $rows,
            'my_rank' => $mine === null ? null : 1 + $this->before($base(), $mine)->count(),
            'my_result' => $mine,
            'is_final' => $event->status === CompetitiveEvent::STATUS_COMPLETED,
            'scope' => $scope,
        ];
    }

    /** الترتيب الكامل المستقر (مصدر الحقيقة الوحيد؛ المنفِّذ يستعمل الترتيب نفسه لكتابة final_rank). */
    public function ordered($query)
    {
        return $query->orderByDesc('score')->orderBy('duration_ms')->orderBy('completed_at')->orderBy('id');
    }

    /** النتائج التي تسبق $mine بالترتيب الكامل (للرتبة). */
    protected function before($query, CompetitiveEventResult $mine)
    {
        return $query->where(function ($q) use ($mine) {
            $q->where('score', '>', $mine->score)
                ->orWhere(fn ($w) => $w->where('score', $mine->score)->where('duration_ms', '<', $mine->duration_ms))
                ->orWhere(fn ($w) => $w->where('score', $mine->score)->where('duration_ms', $mine->duration_ms)->where('completed_at', '<', $mine->completed_at))
                ->orWhere(fn ($w) => $w->where('score', $mine->score)->where('duration_ms', $mine->duration_ms)->where('completed_at', $mine->completed_at)->where('id', '<', $mine->id));
        });
    }
}
