<?php

namespace App\Support;

use App\Models\User;

/**
 * بنية التنقل العامة (E22) - **عرض فقط**: لا منطق مجال ولا صلاحيات جديدة. تبني قوائم التنقل الرئيسية/الثانوية/الحساب من المسارات القائمة وتحترم مفاتيح
 * إعدادات المنصة (`navigation.show_*`). كل وجهة كانت بالتنقل القديم باقية بوصول ≤ نقرتين: الرئيسية (أيقونات) ← «المزيد» ← قائمة الحساب ← ورقة «المزيد» بالجوال.
 * مصدر واحد لسطح المكتب والجوال فلا تنحرف القائمتان.
 */
final class NavigationMenu
{
    /**
     * @param  array<string, mixed>  $navigation  إعدادات المجموعة navigation
     * @return array{primary: list<array>, bottom: list<array>, guest: list<array>, more: list<array{title: string, items: list<array>}>, account: list<array>}
     */
    public static function build(?User $user, array $navigation): array
    {
        $verified = $user !== null && $user->email_verified_at !== null;
        $show = fn (string $key) => (bool) ($navigation[$key] ?? true);
        $item = fn (string $key, string $route, string $icon, string $label, array $match, array $extra = []) => [
            'key' => $key, 'url' => route($route), 'route' => $route, 'icon' => $icon, 'label' => $label, 'match' => $match,
        ] + $extra;

        $home = $item('home', 'home', 'home', 'الرئيسية', ['home']);
        $puzzles = $item('puzzles', 'puzzles.index', 'puzzle', 'الأحجيات', ['puzzles.*']);
        $competitions = $item('competitions', 'competitions.index', 'trophy', 'المنافسات', ['competitions.*', 'team-championships.*', 'players.competitive']);
        $teams = $item('teams', 'teams.index', 'team', 'الفرق', ['teams.*'], ['except' => ['teams.chat']]);
        $friends = $item('friends', 'friends.index', 'friends', 'الأصدقاء', ['friends.*']);
        $messages = $item('messages', 'messages.index', 'chat', 'الرسائل', ['messages.*', 'community.chat', 'teams.chat', 'chat.*'], ['badge' => 'chat']);

        $primary = array_values(array_filter([
            $home,
            $show('show_puzzles_link') ? $puzzles : null,
            $competitions,
            $teams,
            $verified ? $friends : null,
            $verified ? $messages : null,
        ]));

        // الجوال: 4 مدخل سفلية + «المزيد». الفريق والأصدقاء بورقة «المزيد» (نقرتان).
        $bottom = array_values(array_filter([
            $home,
            $show('show_puzzles_link') ? $puzzles : null,
            $competitions,
            $verified ? $messages : $teams,
        ]));

        $explore = array_values(array_filter([
            $show('show_seasons_link') ? $item('seasons', 'seasons.index', 'calendar', 'المواسم', ['seasons.*', 'campaigns.*']) : null,
            $show('show_challenges_link') ? $item('challenges', 'challenges.index', 'bolt', 'التحديات', ['challenges.*', 'friends.challenges.*']) : null,
            $show('show_leaderboard_link') ? $item('leaderboard', 'leaderboard.index', 'chart', 'لوحة الصدارة', ['leaderboard.*']) : null,
            $item('championships', 'team-championships.index', 'flag', 'بطولات الفرق', ['team-championships.*']),
            $item('hall-of-fame', 'competitions.hall-of-fame', 'sparkles', 'قاعة الأمجاد', ['competitions.hall-of-fame']),
        ]));

        $moreGroups = [['title' => 'استكشاف', 'items' => $explore]];

        if ($user !== null) {
            $moreGroups[] = ['title' => 'تقدّمي', 'items' => [
                $item('progress', 'progress.show', 'sparkles', 'تقدّمي وإنجازاتي', ['progress.*']),
                $item('quests', 'quests.show', 'quests', 'المهام اليومية', ['quests.*']),
            ]];
            $moreGroups[] = ['title' => 'متجري ومحفظتي', 'items' => [
                $item('store', 'store.index', 'store', 'المتجر', ['store.*']),
                $item('inventory', 'inventory.index', 'inventory', 'مقتنياتي', ['inventory.*']),
                $item('wallet', 'wallet.index', 'wallet', 'محفظتي', ['wallet.*']),
                $item('redemption', 'redemption.index', 'gift', 'الاستبدال', ['redemption.*']),
            ]];
            $verified && $moreGroups[] = ['title' => 'التواصل', 'items' => array_values(array_filter([
                $item('notifications', 'notifications.index', 'bell', 'مركز الإشعارات', ['notifications.*']),
                $teams,
                $friends,
            ]))];
            $user->email_verified_at === null && $moreGroups[] = ['title' => 'المجتمع', 'items' => [$teams]];
        } else {
            $moreGroups[] = ['title' => 'المتجر', 'items' => [$item('store', 'store.index', 'store', 'المتجر', ['store.*'])]];
        }

        $guest = array_values(array_filter([
            $show('show_puzzles_link') ? $puzzles : null,
            $show('show_seasons_link') ? $item('seasons', 'seasons.index', 'calendar', 'المواسم', ['seasons.*', 'campaigns.*']) : null,
            $competitions,
            $teams,
            $show('show_leaderboard_link') ? $item('leaderboard', 'leaderboard.index', 'chart', 'لوحة الصدارة', ['leaderboard.*']) : null,
            $item('store', 'store.index', 'store', 'المتجر', ['store.*']),
        ]));

        $account = [];

        if ($user !== null) {
            $account[] = ['key' => 'profile', 'route' => 'players.show', 'url' => route('players.show', $user), 'icon' => 'user', 'label' => 'عرض ملفي الشخصي', 'match' => ['players.show']];
            $verified && $account[] = ['key' => 'customize', 'route' => 'profile.customize', 'url' => route('profile.customize'), 'icon' => 'palette', 'label' => 'تخصيص الهوية', 'match' => ['profile.customize']];
            $account[] = ['key' => 'settings', 'route' => 'profile.edit', 'url' => route('profile.edit'), 'icon' => 'settings', 'label' => 'إعدادات الحساب', 'match' => ['profile.edit', 'profile.update']];
            $account[] = ['key' => 'privacy', 'route' => 'profile.edit', 'url' => route('profile.edit').'#privacy', 'icon' => 'lock', 'label' => 'الخصوصية', 'match' => []];
        }

        return compact('primary', 'bottom', 'guest') + ['more' => $moreGroups, 'account' => $account];
    }

    /** هل العنصر هو الصفحة الحالية؟ (مطابقة أسماء المسارات، مع استثناءات). */
    public static function isActive(array $item): bool
    {
        $request = request();

        if ($item['match'] === [] || ! $request->routeIs(...$item['match'])) {
            return false;
        }

        return ! (($item['except'] ?? []) !== [] && $request->routeIs(...$item['except']));
    }
}
