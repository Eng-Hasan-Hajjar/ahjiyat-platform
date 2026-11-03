<?php

namespace App\Services\AiAssistant\Local;

/**
 * TI-2/TI-3 - كتالوج النوايا المعتمد للمحرك الداخلي. كل نية = مقال معرفي منشور + أقسامه (معرفية) أو أداة قراءة حيّة/شخصية.
 *
 * مفاتيح كل نية:
 *   k  النوع: know (معرفية) | live (بيانات حيّة عامة) | my (بيانات حسابك)
 *   a  slug المقال المنشور الذي تُستخرج منه الإجابة (know)
 *   s  عناوين الأقسام المقصودة بالضبط (know)
 *   w  أوزان المفاهيم (مفهوم ⇒ وزن)
 *   r  مجموعات إلزامية: يجب حضور مفهوم واحد على الأقل من كل مجموعة
 *   f  مكافأة وجه السؤال (diff/why/how/where/when/howmuch/can/what/who/which)
 *   t  مفتاح الأداة الحيّة (live/my)
 *
 * لا يوجد هنا نص إجابة ولا رقم ولا سعر ولا تاريخ؛ الإجابات تُستخرج من المعرفة المنشورة أو من أدوات القراءة وحدها.
 * إضافة ميزة جديدة = إضافة نية هنا + بطاقة معرفة في Cards (ويفرض ذلك أمر ai:local:coverage).
 */
final class IntentCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $all = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::$all ??= array_merge(self::knowledge(), self::live(), self::personal());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $intent): ?array
    {
        return self::all()[$intent] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function knowledge(): array
    {
        $K = fn (string $a, array $s, array $w, array $r = [], array $f = []) => ['k' => 'know', 'a' => $a, 's' => $s, 'w' => $w, 'r' => $r, 'f' => $f];

        return [
            // ── البداية والمنصة ──────────────────────────────────────
            'platform.what' => $K('ma-hiya-ahjiyat', ['نظرة عامة'], ['platform' => 3, 'platform_name' => 5, 'puzzle' => 1, 'community' => -5], [['platform', 'platform_name']], ['what' => 3, 'who' => 1]),
            'platform.free' => $K('ma-hiya-ahjiyat', ['اللعب الأساسي مجاني'], ['free' => 6, 'payment' => 4, 'buy' => 1, 'register' => 2, 'conditions' => 1, 'spend' => 1], [['free', 'payment']], ['can' => 1]),
            'start.how' => $K('kayfa-abda', ['الخطوات'], ['start' => 6, 'register' => 3, 'account' => 2, 'join' => 1, 'platform' => 1], [['start', 'register', 'account']], ['how' => 2, 'where' => 1]),
            'start.verify_email' => $K('kayfa-abda', ['الخطوات', 'ملاحظات'], ['verify' => 6, 'verification' => 4, 'email' => 3, 'not_received' => 5, 'resend' => 4], [['verify', 'verification'], ['verify', 'email', 'not_received', 'resend']], ['how' => 1]),
            'start.one_account' => $K('kayfa-abda', ['ملاحظات'], ['second_account' => 7, 'account' => 1, 'multiple' => 2], [['second_account', 'multiple']], ['can' => 1]),

            // ── الأحجيات ─────────────────────────────────────────────
            'puzzles.types' => $K('al-ahjiyat-wa-anwaauha', ['الأنواع'], ['puzzle_types' => 6, 'types_word' => 4, 'puzzle_kind_memory' => 5, 'puzzle_kind_sequence' => 5, 'puzzle_kind_choice' => 5, 'puzzle_kind_images' => 5, 'puzzle' => 2], [['puzzle_types', 'types_word', 'puzzle_kind_memory', 'puzzle_kind_sequence', 'puzzle_kind_choice', 'puzzle_kind_images'], ['puzzle', 'puzzle_types', 'puzzle_kind_memory', 'puzzle_kind_sequence', 'puzzle_kind_choice', 'puzzle_kind_images']], ['what' => 1]),
            'puzzles.attempts' => $K('al-ahjiyat-wa-anwaauha', ['المحاولات والتلميحات'], ['attempt' => 6, 'timer' => 6, 'how_many' => 1, 'puzzle' => 1, 'competition' => -4], [['attempt', 'timer']], ['howmuch' => 1]),
            'puzzles.hint_feature' => $K('al-ahjiyat-wa-anwaauha', ['المحاولات والتلميحات'], ['hint_official' => 7, 'hint' => 5, 'spend' => 1, 'price' => 1, 'gems' => 1, 'competition' => -4], [['hint', 'hint_official']], ['how' => 1]),
            'puzzles.verification' => $K('al-ahjiyat-wa-anwaauha', ['تحقق الإجابة'], ['verification' => 7, 'server_word' => 5, 'speed_accuracy' => 1], [['verification', 'server_word']], ['how' => 1, 'who' => 1]),
            'puzzles.browse' => $K('al-ahjiyat-wa-anwaauha', ['التصنيفات والصعوبة'], ['difficulty' => 5, 'category' => 5, 'filter' => 4, 'by_title' => 6, 'search' => 1, 'puzzle' => 1], [['difficulty', 'category', 'filter', 'by_title']], ['where' => 1, 'how' => 1]),

            // ── الجواهر والاستبدال ───────────────────────────────────
            'gems.what' => $K('al-jawahir', ['ما هي الجواهر؟'], ['gems' => 4], [['gems']], ['what' => 3]),
            'gems.lifecycle' => $K('al-jawahir', ['دورة الجوهرة'], ['pending' => 6, 'available' => 5, 'release' => 5, 'hold' => 4, 'gems' => 1], [['pending', 'available', 'release', 'hold']], ['diff' => 2, 'why' => 1, 'when' => 1]),
            'gems.spend' => $K('al-jawahir', ['دورة الجوهرة'], ['spend' => 6, 'gems' => 2, 'item' => 1, 'store' => 1, 'hint' => 1, 'studio' => -5], [['spend']], ['what' => 1, 'where' => 1]),
            'gems.earn' => $K('al-jawahir', ['ما هي الجواهر؟'], ['earn' => 6, 'gems' => 3, 'reward' => 1], [['earn'], ['gems']], ['how' => 2]),
            'gems.cap' => $K('al-jawahir', ['حدود'], ['cap' => 7, 'daily_word' => 3, 'earn' => 1, 'gems' => 1], [['cap']], ['can' => 1]),
            'gems.cash' => $K('al-jawahir', ['ما هي الجواهر؟'], ['cash_value' => 6, 'is_currency' => 5, 'gems' => 3, 'cashout' => 3, 'outside' => 1, 'pass' => -4], [['cash_value', 'cashout', 'is_currency']], ['can' => 1]),
            'redeem.how' => $K('al-istibdal', ['كيف يعمل'], ['redeem' => 6, 'page' => 2, 'gems' => 1, 'status' => -3, 'conditions' => -3, 'review' => -3, 'freeze' => -3], [['redeem']], ['how' => 2, 'where' => 1]),
            'redeem.conditions' => $K('al-istibdal', ['الشروط العامة'], ['conditions' => 7, 'minimum' => 5, 'redeem' => 3, 'studio' => -5], [['conditions', 'minimum'], ['redeem', 'minimum', 'gems', 'order']], ['what' => 1]),
            'redeem.status' => $K('al-istibdal', ['حالات الطلب'], ['status' => 6, 'accepted' => 4, 'order' => 3, 'redeem' => 3], [['status', 'accepted'], ['redeem', 'order']], ['what' => 1]),
            'redeem.review' => $K('al-istibdal', ['تنبيه'], ['review' => 6, 'freeze' => 6, 'reject' => 3, 'fraud' => 3, 'delay' => 4, 'order' => 1, 'redeem' => 2], [['review', 'freeze', 'reject', 'fraud', 'delay']], ['why' => 3]),

            // ── التقدم والمهام ───────────────────────────────────────
            'xp.what' => $K('al-taqaddum-wal-mustawayat', ['الخبرة والمستوى'], ['xp' => 5, 'level' => 5, 'next_level' => 3, 'earn' => 1], [['xp', 'level']], ['what' => 2, 'how' => 1]),
            'xp.convert' => $K('al-taqaddum-wal-mustawayat', ['الخبرة والمستوى'], ['convert' => 6, 'is_currency' => 5, 'xp' => 4, 'gems' => 2, 'cash_value' => 1], [['convert', 'is_currency'], ['xp']], ['can' => 1]),
            'xp.buy' => $K('al-taqaddum-wal-mustawayat', ['الخبرة والمستوى'], ['buy' => 6, 'xp' => 4, 'level' => 3, 'cash_value' => 2], [['buy'], ['xp', 'level']], ['can' => 1]),
            'achievements.what' => $K('al-taqaddum-wal-mustawayat', ['الإنجازات'], ['achievement' => 8, 'unlock' => 1], [['achievement']], ['what' => 1, 'how' => 1]),
            'quests.what' => $K('al-mahamm-wal-silsila', ['المهام'], ['quest' => 8, 'daily_word' => 2, 'weekly_word' => 2, 'reward' => 1], [['quest']], ['what' => 1, 'how' => 1]),
            'streak.what' => $K('al-mahamm-wal-silsila', ['سلسلة النشاط'], ['streak' => 8, 'streak_break' => 8, 'restore_paid' => 4], [['streak', 'streak_break']], ['what' => 1]),
            'progress.where' => $K('al-taqaddum-wal-mustawayat', ['أين أرى تقدّمي؟'], ['progress' => 6, 'page' => 2, 'achievement' => 1], [['progress']], ['where' => 3]),

            // ── الأصدقاء والفرق والدردشة ──────────────────────────────
            'friends.add' => $K('al-asdiqa-wal-hazr', ['الصداقة'], ['friend_request' => 7, 'friend' => 3, 'receive' => -5, 'visibility' => -3], [['friend_request', 'friend']], ['how' => 2]),
            'friends.block' => $K('al-asdiqa-wal-hazr', ['الحظر'], ['block' => 8, 'friend' => 1], [['block']], ['what' => 1]),
            'friends.privacy' => $K('al-asdiqa-wal-hazr', ['الخصوصية', 'الصداقة'], ['receive' => 6, 'visibility' => 4, 'private_word' => 4, 'friend' => 2, 'friend_request' => 2], [['receive', 'visibility', 'private_word'], ['friend', 'friend_request']], ['who' => 2]),
            'friends.rewards' => $K('al-asdiqa-wal-hazr', ['لا مكافآت'], ['reward' => 5, 'friend' => 3, 'xp' => 2, 'gems' => 2, 'challenge' => -5], [['reward', 'gems', 'xp'], ['friend']], ['can' => 1]),
            'teams.membership' => $K('al-furuq', ['العضوية'], ['team_role' => 7, 'authority' => 5, 'leave' => 4, 'multiple' => 5, 'how_many' => 3, 'team' => 3, 'join' => 1], [['team', 'team_role'], ['team_role', 'authority', 'leave', 'multiple', 'how_many', 'join']], ['howmuch' => 4]),
            'teams.join' => $K('al-furuq', ['الانضمام'], ['join' => 5, 'open_word' => 5, 'invite' => 5, 'team' => 3, 'available' => -4, 'recommend' => -4], [['team'], ['join', 'open_word', 'invite']], ['how' => 1, 'diff' => 1, 'howmuch' => -2]),
            'teams.competition_score' => $K('al-furuq', ['الفرق في المنافسات'], ['score' => 3, 'counted' => 3, 'competition' => 2, 'big' => 5, 'top_three' => 6, 'points' => 2, 'team' => 3, 'winner' => 1], [['team'], ['score', 'counted', 'big', 'top_three', 'points']], ['how' => 1]),
            'teams.rewards' => $K('al-furuq', ['لا مكافآت'], ['reward' => 4, 'wallet' => 4, 'team' => 3, 'gems' => 2, 'xp' => 2], [['team'], ['reward', 'wallet']], ['can' => 1]),
            'teams.challenges' => $K('tahaddiyat-wa-butulat-al-furuq', ['تحدّي فريق ضد فريق'], ['team_challenge' => 9, 'challenge' => 2, 'team' => 2, 'roster' => 3], [['team_challenge', 'challenge']], ['how' => 1]),
            'teams.championships' => $K('tahaddiyat-wa-butulat-al-furuq', ['بطولات الفرق', 'كيف تشارك في بطولة فرق؟'], ['championship' => 9, 'team' => 2, 'points' => 1, 'score' => 1], [['championship']], ['what' => 1, 'how' => 1]),
            'chat.what' => $K('al-dardasha', ['الأنواع'], ['chat' => 5, 'room' => 5, 'private_message' => 2, 'types_word' => 4, 'outside' => -4], [['chat', 'room', 'private_message']], ['what' => 2]),
            'chat.rules' => $K('al-dardasha', ['القواعد'], ['report' => 4, 'delete' => 4, 'mute' => 5, 'violation' => 4, 'chat' => 2], [['report', 'delete', 'mute', 'violation']], ['how' => 1]),
            'chat.ai_privacy' => $K('al-dardasha', ['الخصوصية'], ['ai' => 4, 'read' => 6, 'private_message' => 3, 'chat' => 2, 'visibility' => 1], [['ai', 'read'], ['private_message', 'chat']], ['can' => 1]),

            // ── المنافسات ────────────────────────────────────────────
            'competitions.how' => $K('al-munafasat', ['المنافسات'], ['competition' => 5, 'participate' => 4, 'attempt' => 4, 'hint' => 3, 'abandon' => 5, 'register_competition' => 5], [['competition', 'abandon']], ['how' => 2]),
            'competitions.scoring' => $K('al-munafasat', ['المنافسات'], ['winner' => 3, 'speed_accuracy' => 6, 'rank_word' => 5, 'score' => 3, 'wrong' => 4, 'competition' => 3, 'solution' => 1], [['speed_accuracy', 'winner', 'rank_word', 'score', 'wrong']], ['how' => 1, 'where' => -2]),
            'competitions.prizes' => $K('al-munafasat', ['الجوائز'], ['prize' => 7, 'reward' => 1, 'competition' => 1, 'winner' => 2, 'writer' => -5], [['prize']], ['when' => 1]),
            'competitions.friend_challenge' => $K('al-munafasat', ['تحدّيات الأصدقاء'], ['challenge' => 5, 'friend' => 5, 'tie' => 4, 'xp' => 2, 'reward' => 2, 'gems' => 2], [['challenge'], ['friend']], ['how' => 1]),
            'competitions.hall_of_fame' => $K('al-munafasat', ['الجوائز'], ['hall_of_fame' => 9, 'winner' => 4, 'competition' => 1], [['hall_of_fame', 'winner']], ['what' => 1, 'where' => 3]),

            // ── مسار الأحجيات والمواسم ────────────────────────────────
            'pass.what' => $K('masar-al-ahjiyat', ['الفكرة'], ['pass' => 6, 'join' => 2, 'reward' => -4, 'claim' => -4, 'tier' => -3, 'premium' => -4, 'pass_points' => -5], [['pass']], ['what' => 3, 'how' => 1]),
            'pass.points' => $K('masar-al-ahjiyat', ['نقاط المسار'], ['pass_points' => 9, 'same' => 1, 'xp' => 1, 'gems' => 1], [['pass_points']], ['can' => 1]),
            'pass.rewards' => $K('masar-al-ahjiyat', ['المكافآت'], ['reward' => 5, 'pass' => 4, 'claim' => 5, 'tier' => 5, 'cash_value' => 3, 'gems' => 2], [['pass', 'tier'], ['reward', 'claim', 'tier']], ['what' => 1]),
            'pass.premium' => $K('masar-al-ahjiyat', ['المميز'], ['premium' => 9, 'pass' => 2, 'proof' => 3, 'payment' => 3, 'buy' => 3, 'unlock' => 2, 'series' => -6, 'price' => -6, 'available' => -4, 'how_many' => -2, 'me_did' => -3], [['premium']], ['how' => 1]),
            'season.what' => $K('al-mawasim', ['ما هو الموسم؟'], ['season' => 6], [['season']], ['what' => 3]),
            'season.states' => $K('al-mawasim', ['الحالات'], ['season_state' => 9, 'season' => 2], [['season_state']], ['what' => 1]),
            'season.stages' => $K('al-mawasim', ['المراحل والإعلانات'], ['stage' => 6, 'announcement' => 6, 'season_center' => 7, 'archive' => 6, 'season' => 1], [['stage', 'announcement', 'season_center', 'archive']], ['where' => 1]),
            'writers.what' => $K('dawri-al-kuttab', ['الفكرة'], ['writer' => 6, 'league' => 4, 'status' => -3, 'vote' => -3, 'results_after' => -5, 'prize' => -3], [['writer', 'league']], ['what' => 2, 'who' => 1]),
            'writers.vote' => $K('dawri-al-kuttab', ['كيف تصوّت؟'], ['vote' => 6, 'star' => 4, 'eligible_vote' => 6, 'writer' => 3, 'how_many' => 1, 'results_after' => -6], [['vote', 'star', 'eligible_vote']], ['how' => 2, 'howmuch' => 1]),
            'writers.results' => $K('dawri-al-kuttab', ['النتائج', 'تنبيه'], ['results_after' => 7, 'prize' => 4, 'reward' => 2, 'gems' => 1, 'vote' => 1, 'writer' => 3], [['results_after', 'prize'], ['writer', 'vote', 'star', 'league']], ['what' => 1]),

            // ── الداعمون والمبدعون والمتجر ────────────────────────────
            'supporters.points' => $K('taqdir-al-daaimin', ['نقاط الدعم'], ['support_points' => 9, 'convert' => 2, 'cash_value' => 2], [['support_points']], ['what' => 1]),
            'supporters.cards' => $K('taqdir-al-daaimin', ['بطاقات الداعم'], ['supporter_card' => 8, 'card_level' => 6, 'proof' => 3, 'payment' => 2, 'supporter' => 2, 'packages' => -3, 'available' => -3], [['supporter_card', 'card_level']], ['what' => 1]),
            'supporters.ranking' => $K('taqdir-al-daaimin', ['الترتيب والخصوصية'], ['ranking_support' => 7, 'anonymous' => 6, 'visibility' => 1, 'supporter' => 2], [['ranking_support', 'anonymous']], ['who' => 2]),
            'creators.community_puzzles' => $K('al-mubdiun-wa-ahjiyat-al-mujtamaa', ['أحجيات المجتمع'], ['community_puzzle' => 6, 'community' => 4, 'platform_name' => 3, 'publish' => 3, 'studio' => 3, 'creator' => 1], [['community_puzzle', 'community']], ['what' => 1, 'how' => 1]),
            'creators.economy' => $K('al-mubdiun-wa-ahjiyat-al-mujtamaa', ['بلا أثر اقتصادي'], ['community_puzzle' => 3, 'reward' => 5, 'gems' => 4, 'xp' => 3, 'quest' => 3, 'leaderboard' => 3, 'streak' => 2, 'community' => 1], [['community_puzzle', 'community', 'creator', 'series'], ['reward', 'gems', 'xp', 'quest', 'leaderboard']], ['can' => 1]),
            'creators.become' => $K('al-mubdiun-wa-ahjiyat-al-mujtamaa', ['كيف أصبح مبدعًا؟'], ['apply_creator' => 8, 'creator' => 3, 'studio' => 6, 'publish' => 4, 'conditions' => 4, 'status' => -4, 'redeem' => -6], [['apply_creator', 'studio', 'publish']], ['how' => 2]),
            'creators.interact' => $K('al-mubdiun-wa-ahjiyat-al-mujtamaa', ['التفاعل'], ['rating' => 6, 'favorite' => 4, 'follow' => 3, 'collection' => 3, 'community_puzzle' => 3, 'creator' => 2, 'favorites_page' => -5], [['rating', 'favorite', 'follow', 'collection']], ['how' => 1]),
            'creators.series' => $K('salasil-al-mubdiin', ['ما هي السلسلة؟', 'سلاسل مميزة'], ['series' => 7, 'creator' => 1, 'premium' => 3, 'streak_break' => -6], [['series']], ['what' => 2, 'when' => -2]),
            'creators.series_visibility' => $K('salasil-al-mubdiin', ['متى تظهر؟', 'تعديل السلسلة'], ['series_hidden' => 6, 'version' => 5, 'series' => 3, 'public_word' => 2], [['series', 'series_hidden', 'version']], ['when' => 4]),
            'creators.earnings' => $K('arbah-al-mubdiin', ['أرباح المبدع'], ['earnings' => 9, 'creator' => 3], [['earnings']], ['where' => 1, 'how' => 1]),
            'store.what' => $K('al-matjar-wal-maqtaniyat', ['المتجر', 'تنبيهات'], ['store' => 7, 'item' => 2, 'buy' => 1, 'reward' => 1], [['store']], ['what' => 2]),
            'store.inventory' => $K('al-matjar-wal-maqtaniyat', ['المخزون والتجهيز'], ['inventory' => 7, 'purchases' => 5, 'equip' => 7, 'item' => 2, 'wear' => 3], [['inventory', 'purchases', 'equip', 'wear']], ['where' => 2, 'how' => 1]),
            'store.exclusive' => $K('al-matjar-wal-maqtaniyat', ['تنبيهات'], ['exclusive' => 9, 'item' => 2, 'earn' => 1], [['exclusive']], ['where' => 1]),
            'cosmetics.slots' => $K('khanat-altakhsees', ['خانات التخصيص'], ['slot' => 8, 'avatar' => 4, 'frame' => 4, 'badge' => 4, 'title_word' => 4, 'background' => 4, 'cosmetic' => 3, 'profile' => 1], [['slot', 'avatar', 'frame', 'badge', 'title_word', 'background', 'cosmetic']], ['what' => 1, 'can' => 1]),

            // ── البحث والإشعارات والمساعد ─────────────────────────────
            'search.how' => $K('al-bahth-wal-iktishaf', ['البحث'], ['search' => 6, 'shortcut' => 6, 'by_title' => -4, 'difficulty' => -3, 'category' => -3, 'filter' => -3], [['search', 'shortcut']], ['how' => 2]),
            'discover.what' => $K('al-bahth-wal-iktishaf', ['الاكتشاف', 'النزاهة'], ['discover' => 9, 'ranking_paid' => 5, 'rank_word' => 1], [['discover']], ['what' => 1]),
            'notifications.what' => $K('al-ishaaraat', ['القناة', 'ما هي؟'], ['notification' => 6, 'outside' => 6, 'chat' => 1], [['notification', 'outside']], ['what' => 2]),
            'notifications.prefs' => $K('al-ishaaraat', ['التفضيلات'], ['preferences' => 7, 'notification' => 3, 'edit' => 2], [['preferences']], ['how' => 1]),
            'notifications.rewards' => $K('al-ishaaraat', ['بلا مكافآت'], ['click' => 7, 'reward' => 4, 'notification' => 4, 'gems' => 2], [['click', 'reward', 'gems'], ['notification', 'click']], ['can' => 1]),
            'ai.what' => $K('tatawwur-ai', ['ما الذي يفعله؟'], ['ai' => 6, 'can_do' => 2], [['ai']], ['what' => 3]),
            'ai.limits' => $K('tatawwur-ai', ['ما الذي لا يفعله؟'], ['cannot_do' => 7, 'do_for_me' => 6, 'ai' => 3], [['ai'], ['cannot_do', 'do_for_me']], ['what' => 1]),
            'ai.privacy' => $K('tatawwur-ai', ['بياناتك الشخصية'], ['retention' => 8, 'ai' => 2, 'delete' => 3, 'guest' => 3], [['retention', 'guest']], ['howmuch' => 1]),

            // ── ميزات إضافية (بطاقات المعرفة الجديدة) ─────────────────
            'referrals.what' => $K('barnamaj-al-daawa', ['ما هو رابط الدعوة؟'], ['referral' => 9, 'link' => 2, 'invite' => 3], [['referral']], ['what' => 1, 'how' => 1]),
            'referrals.qualify' => $K('barnamaj-al-daawa', ['متى تُحسب الدعوة؟'], ['counted' => 6, 'referral' => 4, 'reward' => 2, 'invite' => 3], [['referral', 'invite'], ['counted', 'reward']], ['when' => 2, 'can' => 1]),
            'collections.what' => $K('al-majmuaat', ['ما هي المجموعات؟'], ['collection' => 9, 'saved' => 4, 'puzzle' => 1], [['collection', 'saved']], ['what' => 1, 'how' => 1]),
            'collections.visibility' => $K('al-majmuaat', ['الظهور والخصوصية'], ['visibility' => 5, 'private_word' => 4, 'public_word' => 4, 'unlisted' => 6, 'collection' => 4], [['collection'], ['visibility', 'private_word', 'public_word', 'unlisted']], ['diff' => 2, 'who' => 2]),
            'discussions.what' => $K('al-taaliqat', ['الكتابة والتعديل'], ['comment' => 9, 'edit' => 2, 'report' => -5, 'like' => -4], [['comment']], ['how' => 1, 'can' => 1]),
            'discussions.report' => $K('al-taaliqat', ['الإعجاب والإبلاغ'], ['report' => 6, 'like' => 6, 'comment' => 3], [['report', 'like']], ['how' => 1]),
            'reputation.rankings' => $K('al-sumaa', ['ترتيب السمعة'], ['reputation' => 9, 'authority' => 4, 'community' => 2, 'reward' => 1], [['reputation', 'authority']], ['what' => 1]),
            'safety.account' => $K('aman-al-hisab', ['صفحة أمان الحساب'], ['safety' => 9, 'page' => 1, 'account' => 1], [['safety']], ['what' => 1, 'where' => 1]),
            'safety.appeal' => $K('aman-al-hisab', ['الاعتراض على إجراء'], ['appeal' => 9, 'freeze' => 2, 'account' => 1], [['appeal']], ['how' => 2]),
            'sponsor.support' => $K('daam-al-minassa', ['كيف أدعم المنصة؟'], ['support_verb' => 8, 'platform' => 1, 'status' => -4, 'payment' => -4, 'support_history' => -5], [['support_verb']], ['how' => 1, 'what' => 1]),
            'sponsor.history' => $K('daam-al-minassa', ['سجل المساهمات'], ['support_history' => 9, 'status' => 4, 'payment' => 3, 'support_verb' => 3], [['support_history', 'status', 'payment'], ['support_verb', 'support_history']], ['where' => 2, 'how' => 1]),
            'purchases.history' => $K('mushtarayati', ['مشترياتي وإيصالاتي'], ['receipt' => 8, 'purchases' => 3, 'payment' => 4, 'status' => 3], [['receipt', 'purchases', 'status']], ['where' => 2, 'how' => 1]),
            'missions.hub' => $K('al-mahamm-wal-fa3aliyat', ['صفحة المهام'], ['missions_hub' => 9, 'same' => 2, 'quest' => 1], [['missions_hub']], ['where' => 2]),
            'events.calendar' => $K('al-mahamm-wal-fa3aliyat', ['تقويم الفعاليات'], ['events' => 9, 'upcoming' => 2], [['events']], ['where' => 2]),
            'challenges.list' => $K('al-tahaddiyat-wal-hamalat', ['التحديات المفتوحة'], ['challenge' => 7, 'open_word' => 2, 'join' => 2], [['challenge']], ['what' => 1]),
            'campaigns.what' => $K('al-tahaddiyat-wal-hamalat', ['الحملات'], ['campaign' => 9, 'participate' => 2], [['campaign']], ['what' => 1]),
            'leaderboard.what' => $K('lawhat-al-sadara', ['كيف تعمل لوحة الصدارة؟'], ['leaderboard' => 9, 'friends_only' => 5, 'visibility' => 1], [['leaderboard', 'friends_only']], ['how' => 1]),
            'profile.edit' => $K('al-malaf-al-shakhsi', ['تعديل الملف الشخصي'], ['edit' => 3, 'profile' => 5, 'name' => 3, 'convert' => 2, 'account' => 3, 'settings' => 3], [['edit', 'name', 'settings', 'convert'], ['profile', 'account', 'name']], ['how' => 1, 'where' => 1]),
            'profile.public' => $K('al-malaf-al-shakhsi', ['الملف العام'], ['visibility' => 4, 'profile' => 4, 'public_word' => 4, 'private_word' => 3], [['visibility', 'public_word', 'private_word'], ['profile']], ['what' => 1, 'can' => 1]),
            'account.password' => $K('al-malaf-al-shakhsi', ['كلمة المرور'], ['password' => 9, 'forgot' => 4, 'login' => 1], [['password']], ['how' => 1]),
            'legal.terms' => $K('al-shurut-wal-musaada', ['الشروط والخصوصية'], ['terms' => 9], [['terms']], ['where' => 2]),
            'help.center' => $K('al-shurut-wal-musaada', ['مركز المساعدة'], ['help' => 9], [['help']], ['where' => 2]),
            'favorites.follow' => $K('al-mufaddala-wal-mutabaa', ['المفضلة والمتابعة'], ['favorites_page' => 9, 'follow' => 5, 'favorite' => 4, 'creator' => 2, 'community_puzzle' => -3, 'rating' => -4], [['favorites_page', 'follow', 'favorite']], ['where' => 2, 'how' => 1]),
            'players.profile' => $K('al-mufaddala-wal-mutabaa', ['ملف لاعب آخر'], ['other_player' => 6, 'competitive_record' => 7, 'profile' => 2], [['other_player', 'competitive_record']], ['how' => 1, 'where' => 1]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function live(): array
    {
        $L = fn (string $t, array $w, array $r = [], array $f = []) => ['k' => 'live', 't' => $t, 'w' => $w, 'r' => $r, 'f' => $f];

        return [
            'live.season' => $L('season', ['season' => 5, 'live_now' => 3, 'ends_when' => 3, 'upcoming' => 2], [['season']], ['when' => 1, 'what' => 1]),
            'live.campaign' => $L('campaign', ['campaign' => 6, 'live_now' => 3, 'available' => 3, 'upcoming' => 1], [['campaign']], ['what' => 1]),
            'live.competitions' => $L('competitions', ['competition' => 4, 'live_now' => 3, 'open_word' => 2, 'register' => 2, 'upcoming' => 3, 'participants' => 3, 'how_many' => 1], [['competition']], ['can' => 1]),
            'live.teams' => $L('teams', ['team' => 4, 'available' => 3, 'join' => 2, 'recommend' => 3, 'open_word' => 3, 'members_count' => 3], [['team']], ['diff' => 1]),
            'live.pass' => $L('pass', ['pass' => 4, 'live_now' => 3, 'premium' => 3, 'buy' => 2, 'ends_when' => 3, 'tier' => 2, 'price' => 2, 'how_many' => 1, 'available' => 1], [['pass']], ['when' => 1, 'howmuch' => 1]),
            'live.store' => $L('store', ['store' => 4, 'live_now' => 3, 'price' => 3, 'item' => 2, 'limited' => 5, 'available' => 1], [['store', 'item', 'limited']], ['what' => 1, 'howmuch' => 1]),
            'live.writers' => $L('writers', ['writer' => 4, 'league' => 2, 'status' => 2, 'season' => 1, 'vote' => 1, 'open_word' => 2, 'live_now' => 3], [['writer', 'league']], ['what' => 1]),
            'live.supporters' => $L('supporters', ['ranking_support' => 5, 'supporter' => 3, 'supporter_card' => 3, 'card_level' => 3, 'packages' => 5, 'season' => 1, 'available' => 2, 'live_now' => 3, 'who_top' => 4, 'how_many' => 1], [['ranking_support', 'supporter', 'supporter_card', 'who_top', 'packages', 'card_level']], ['who' => 1, 'what' => 1, 'howmuch' => 1]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function personal(): array
    {
        $M = fn (string $t, array $w, array $r = [], array $f = []) => ['k' => 'my', 't' => $t, 'w' => $w, 'r' => $r, 'f' => $f];

        return [
            'my.progress' => $M('progress', ['level' => 5, 'xp' => 5, 'next_level' => 5, 'how_many' => 2, 'progress' => 2, 'achievement' => 3, 'unlock' => 2, 'remaining' => 2], [['level', 'xp', 'next_level', 'achievement', 'progress']], ['howmuch' => 1, 'what' => 1]),
            'my.wallet' => $M('wallet', ['wallet' => 6, 'gems' => 4, 'how_many' => 2, 'available' => 3, 'pending' => 3, 'figure_gems' => 2], [['wallet', 'gems', 'pending', 'available']], ['howmuch' => 1]),
            'my.profile' => $M('profile', ['summary' => 5, 'profile' => 4, 'name' => 2, 'account' => 3], [['summary', 'profile', 'account', 'name']], ['what' => 1]),
            'my.pass' => $M('pass', ['pass' => 4, 'pass_points' => 5, 'tier' => 4, 'premium' => 3, 'me_did' => 3, 'how_many' => 1], [['pass', 'pass_points']], ['howmuch' => 1]),
            'my.season' => $M('season', ['season' => 5, 'progress' => 2, 'live_now' => 1, 'status' => 3], [['season']], ['what' => 1]),
            'my.competition' => $M('competition', ['competition' => 5, 'registered' => 4, 'score' => 3, 'live_now' => 1, 'rank_word' => 2], [['competition']], ['can' => 1]),
            'my.writer_stars' => $M('writer_stars', ['writer' => 4, 'star' => 5, 'remaining' => 4, 'how_many' => 2, 'vote' => 1], [['star'], ['writer', 'vote', 'remaining']], ['howmuch' => 1]),
            'my.supporter' => $M('supporter', ['card_level' => 5, 'supporter_card' => 5, 'supporter' => 3, 'support_points' => 5, 'how_many' => 1], [['card_level', 'supporter_card', 'support_points', 'supporter']], ['what' => 1, 'howmuch' => 1]),
            'my.creator' => $M('creator', ['apply_creator' => 5, 'creator' => 4, 'status' => 3, 'approved' => 4, 'private_word' => 1], [['creator', 'apply_creator']], ['what' => 1]),
            'my.campaign' => $M('campaign', ['campaign' => 6, 'progress' => 3, 'how_many' => 1, 'me_did' => 2, 'live_now' => 1], [['campaign']], ['howmuch' => 1, 'where' => 1]),
        ];
    }
}
