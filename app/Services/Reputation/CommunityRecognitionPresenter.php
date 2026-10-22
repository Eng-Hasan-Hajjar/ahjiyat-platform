<?php

namespace App\Services\Reputation;

use Illuminate\Support\Facades\DB;

/**
 * E37 - عرض الاعتراف المجتمعي من **نظام الإنجازات القائم** (لا مخزن ثانٍ): الإنجازات المفتوحة من تعريفات config('reputation.recognition') الفعّالة غير السرّية.
 * استعلام واحد لمجموعة مستخدمين (لا N+1). تصاعدي بطبيعته: الاعتراف المفتوح لا يتراجع بتقلّب الدرجة (أما «الشريحة الحالية» للترتيب فتُحسب من اللقطة الحيّة وتُعرض بقوائم الترتيب).
 * لا يكشف أي رقم خام ولا تاريخ فتح ولا هوية حدثٍ داخلي: اسم الاعتراف + مجموعته فقط.
 */
class CommunityRecognitionPresenter
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, array{tier: ?string, badges: list<string>, groups: array<string, list<string>>}>
     */
    public function forUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $out = [];

        foreach ($ids as $id) {
            $out[$id] = ['tier' => null, 'badges' => [], 'groups' => ['creator' => [], 'curator' => [], 'helpful' => []]];
        }

        $keys = array_keys((array) config('reputation.recognition', []));

        if ($ids === [] || $keys === []) {
            return $out;
        }

        $unlocked = [];

        foreach (DB::table('user_achievement_progress as p')->join('achievements as a', 'a.id', '=', 'p.achievement_id')
            ->whereIn('p.user_id', $ids)->whereNotNull('p.unlocked_at')
            ->whereIn('a.internal_key', $keys)->where('a.is_active', true)->where('a.is_hidden', false)
            ->get(['p.user_id as uid', 'a.internal_key as k', 'a.name as n']) as $row) {
            $unlocked[(int) $row->uid][(string) $row->k] = (string) $row->n;
        }

        $groups = (array) config('reputation.recognition_groups', []);

        foreach ($unlocked as $uid => $byKey) {
            // الترتيب بحسب تعريف الإعداد (الأعلى أولًا) لا بترتيب الفتح.
            $ordered = array_filter(array_map(fn (string $k) => $byKey[$k] ?? null, $keys));

            $out[$uid]['badges'] = array_values($ordered);

            foreach ((array) ($groups['creator_tier'] ?? []) as $tierKey) {
                if (isset($byKey[$tierKey])) {
                    $out[$uid]['tier'] = $byKey[$tierKey];

                    break;
                }
            }

            foreach (['creator', 'curator', 'helpful'] as $group) {
                foreach ((array) ($groups[$group] ?? []) as $key) {
                    if (isset($byKey[$key])) {
                        $out[$uid]['groups'][$group][] = $byKey[$key];
                    }
                }
            }
        }

        return $out;
    }

    /** @return array{tier: ?string, badges: list<string>, groups: array<string, list<string>>} */
    public function forUser(int $userId): array
    {
        return $this->forUsers([$userId])[$userId];
    }
}
