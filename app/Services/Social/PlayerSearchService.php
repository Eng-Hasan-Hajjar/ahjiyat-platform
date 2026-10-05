<?php

namespace App\Services\Social;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * بحث آمن عن لاعبين بالاسم المعروض فقط (users.name). لا يقرأ ولا يعرض email/هاتف/معرّفًا داخليًا/محفظة: حقول مختارة صراحة.
 * سياسة الاكتشاف تتبع الخصوصية الحالية: لا يظهر إلا من يحق للباحث رؤية ملفه (public، أو members للمسجَّل)؛ الملف private غير
 * قابل للاكتشاف. يُستبعد: النفس، والمجمَّد، وغير الموثَّق، ومن بينه وبين الباحث حظر بأي اتجاه. ترقيم إلزامي وحد أدنى للطول.
 */
class PlayerSearchService
{
    public function __construct(protected BlockService $blocks) {}

    public function minLength(): int
    {
        return max(1, (int) config('friends.search_min_length', 2));
    }

    public function isValidTerm(?string $term): bool
    {
        return mb_strlen(trim((string) $term)) >= $this->minLength();
    }

    public function search(User $viewer, string $term): LengthAwarePaginator
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))).'%'; // الهروب من رموز LIKE

        return User::query()
            ->select('users.id', 'users.name', 'users.public_id', 'users.profile_visibility', 'users.friend_requests_enabled', 'users.is_frozen')
            ->whereKeyNot($viewer->getKey())
            ->where('users.is_frozen', false)
            ->whereNotNull('users.email_verified_at')
            ->whereIn('users.profile_visibility', [User::VISIBILITY_PUBLIC, User::VISIBILITY_MEMBERS])
            ->whereNotIn('users.id', $this->blocks->hiddenIds($viewer))
            ->whereRaw("LOWER(users.name) like ? escape '!'", [$like])
            ->orderBy('users.name')->orderBy('users.id')
            ->paginate((int) config('friends.search_per_page', 12))
            ->withQueryString();
    }
}
