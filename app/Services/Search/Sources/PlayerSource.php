<?php

namespace App\Services\Search\Sources;

use App\Models\User;
use App\Services\Search\PartialProbe;
use App\Services\Search\SearchContext;
use App\Services\Search\SearchResult;
use App\Services\Social\BlockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * اللاعبون. الخصوصية أولًا (نفس سياسة PlayerSearchService + أشدّ للضيف):
 *  - بالاسم المعروض فقط (users.name). لا بريد ولا هاتف ولا معرّف داخلي ولا محفظة: الأعمدة المختارة صراحة.
 *  - لا يظهر إلا من يحق للباحث رؤية ملفه: الضيف وغير الموثَّق يرون ملفات «عام» فقط؛ الموثَّق يرى «عام» و«للأعضاء»؛ «خاص» لا يُكتشف أبدًا.
 *  - يُستبعد: الباحث نفسه، المجمَّد، غير الموثَّق بريده، ومن بينه وبين الباحث حظر بأي اتجاه (للموثَّق).
 *  - حدّ أدنى أعلى للضيف (config search.min_length_guest_players).
 *  - الرابط بـpublic_id (ULID) لا بالمعرّف التسلسلي.
 */
class PlayerSource extends EloquentSource
{
    public function __construct(PartialProbe $probe, protected BlockService $blocks)
    {
        parent::__construct($probe);
    }

    public function type(): string
    {
        return 'players';
    }

    public function label(): string
    {
        return 'اللاعبون';
    }

    public function icon(): string
    {
        return 'user';
    }

    public function minLength(SearchContext $context): int
    {
        return $context->isTrustedMember()
            ? max(1, (int) config('search.min_length', 2))
            : max(1, (int) config('search.min_length_guest_players', 3));
    }

    protected function table(): string
    {
        return 'users';
    }

    protected function column(): string
    {
        return 'users.name';
    }

    protected function baseQuery(SearchContext $context): Builder
    {
        $visibilities = $context->isTrustedMember() ? [User::VISIBILITY_PUBLIC, User::VISIBILITY_MEMBERS] : [User::VISIBILITY_PUBLIC];

        $query = User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility')
            ->where('users.is_frozen', false)
            ->whereNotNull('users.email_verified_at')
            ->whereIn('users.profile_visibility', $visibilities);

        if ($context->viewer !== null) {
            $query->whereKeyNot($context->viewer->getKey());
        }

        if ($context->isTrustedMember()) {
            $query->whereNotIn('users.id', $this->blocks->hiddenIds($context->viewer));
        }

        return $query;
    }

    protected function tieBreak(Builder $query): Builder
    {
        return $query->orderBy('users.name')->orderBy('users.id');
    }

    protected function toResult(Model $model, int $tier): SearchResult
    {
        /** @var User $model */
        return new SearchResult(
            type: $this->type(),
            title: (string) $model->name,
            url: route('players.show', $model),
            subtitle: $model->profile_visibility === User::VISIBILITY_MEMBERS ? 'ملف للأعضاء' : null,
            badge: null,
            icon: $this->icon(),
            tier: $tier,
            subject: $model,
        );
    }
}
