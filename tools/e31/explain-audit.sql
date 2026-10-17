-- E31 - تدقيق EXPLAIN على أهم الاستعلامات الساخنة (MySQL 8). قراءة فقط: كلها EXPLAIN ولا تعدّل أي بيانات.
-- شغّله على قاعدة فيها بيانات واقعية (بعد النقل أو بعد php artisan performance:seed) وليس على قاعدة فارغة:
-- على جدول فارغ يختار المخطِّط خططًا لا تمثل الحمل الحقيقي.
--
-- PowerShell (من جذر المشروع؛ لا يدعم PowerShell عامل < لتمرير الملف):
--   $env:MYSQL_PWD = '<كلمة-مرور-المستخدم>'
--   Get-Content tools\e31\explain-audit.sql -Raw -Encoding UTF8 | mysql --default-character-set=utf8mb4 -u ahjiyat_app ahjiyat_platform
--   Remove-Item Env:\MYSQL_PWD
--
-- كيف تقرأ النتيجة: لكل استعلام انظر العمودين key (الفهرس المستعمل) وrows (الصفوف المقدّرة)، وExtra:
--   * key = الفهرس المذكور في التعليق  →  سليم.
--   * type = ALL (مسح كامل) على جدول كبير، أو Extra فيه "Using filesort" مع rows كبير  →  أرسل لي السطر لأقرر فهرسًا مدروسًا.
--   * "Using temporary" على استعلام يظهر في صفحة عامة  →  أرسله أيضًا.
-- لا نضيف أي فهرس قبل رؤية خطة حقيقية بحجم بيانات حقيقي (كل فهرس يبطئ الكتابة).

SET NAMES utf8mb4;

-- 01 الدردشة: صفحة الرسائل بمؤشر id.            المتوقع: chat_messages_chat_thread_id_id_index (range/ref)
EXPLAIN SELECT * FROM chat_messages WHERE chat_thread_id = 1 AND id < 100 ORDER BY id DESC LIMIT 41;

-- 02 بحث الأحجيات (بادئة العنوان).               المتوقع: puzzles_active_title_search_index (range)
EXPLAIN SELECT id, title FROM puzzles WHERE is_active = 1 AND title LIKE 'لغز%' ORDER BY title LIMIT 9;

-- 03 بحث اللاعبين (بادئة الاسم).                 المتوقع: users_name_search_index (range)
EXPLAIN SELECT id, name FROM users WHERE name LIKE 'حسن%' LIMIT 9;

-- 04 الرائج: مدى على المفتاح الأساسي.            المتوقع: PRIMARY (type=range؛ لا ALL على puzzle_attempts)
EXPLAIN SELECT puzzle_id, COUNT(DISTINCT user_id) AS players FROM puzzle_attempts
 WHERE id >= 1000 AND created_at >= NOW() - INTERVAL 7 DAY AND context_type IS NULL GROUP BY puzzle_id;

-- 05 قفل المحفظة (المسار المالي الأهم).          المتوقع: wallets_user_id_currency_id_unique (const)
EXPLAIN SELECT * FROM wallets WHERE user_id = 1 AND currency_id = 1 FOR UPDATE;

-- 06 مفتاح idempotency في الدفتر.                المتوقع: currency_transactions_idempotency_key_unique (const)
EXPLAIN SELECT * FROM currency_transactions WHERE idempotency_key = 'e31-probe';

-- 07 سجل عملات مستخدم (الأحدث أولًا).            قد يظهر filesort لأن الفهرس (user_id,type): مراقبة فقط، لا فهرس قبل القياس
EXPLAIN SELECT * FROM currency_transactions WHERE user_id = 1 ORDER BY id DESC LIMIT 20;

-- 08 صندوق الإشعارات غير المقروءة.               المتوقع: notifications_inbox_index
EXPLAIN SELECT * FROM notifications WHERE notifiable_type = 'App\\Models\\User' AND notifiable_id = 1 AND read_at IS NULL ORDER BY created_at DESC LIMIT 20;

-- 09 لوحة صدارة حدث تنافسي.                      المتوقع: فهرس (competitive_event_id, score, duration_ms)؛ filesort طفيف مقبول (اتجاهان مختلفان)
EXPLAIN SELECT user_id, score, duration_ms FROM competitive_event_results WHERE competitive_event_id = 1 ORDER BY score DESC, duration_ms ASC LIMIT 50;

-- 10 قائمة أعمال المجتمع (الأحدث).               المتوقع: creator_puzzles_visible_new_index
EXPLAIN SELECT id FROM creator_puzzles WHERE is_visible = 1 ORDER BY published_at DESC LIMIT 12;

-- 11 بحث أعمال المجتمع (بادئة العنوان).          المتوقع: creator_puzzles_visible_title_index (range)
EXPLAIN SELECT id FROM creator_puzzles WHERE is_visible = 1 AND published_title LIKE 'لغز%' LIMIT 9;

-- 12 سلسلة بالمعرّف العام.                       المتوقع: creator_series_public_id_unique (const)
EXPLAIN SELECT id FROM creator_series WHERE public_id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

-- 13 تجميع التقييمات لعمل.                       المتوقع: community_ratings_work_rating_index (Using index)
EXPLAIN SELECT COUNT(*), SUM(rating) FROM community_puzzle_ratings WHERE creator_puzzle_id = 1;

-- 14 توزيع إشعار النشر على المتابعين بدفعات.     المتوقع: creator_follows_fanout_index (range)
EXPLAIN SELECT user_id FROM creator_follows WHERE creator_profile_id = 1 AND id > 0 ORDER BY id LIMIT 500;

-- 15 لوحة الداعمين (تجميع مؤكَّد غير مجهول).     المتوقع: sponsorship_contrib_leaderboard_index
EXPLAIN SELECT supporter_profile_id, SUM(accounting_amount_minor) AS total FROM sponsorship_contributions
 WHERE status = 'confirmed' AND is_anonymous = 0 GROUP BY supporter_profile_id;

-- 16 قائمة طلبات الاستبدال قيد المراجعة.         المتوقع: redemption_requests_status_index
EXPLAIN SELECT * FROM redemption_requests WHERE status = 'pending' ORDER BY id LIMIT 25;

-- 17 جلسة لعب قائمة لمستخدم وأحجية.              المتوقع: game_sessions_user_id_puzzle_id_status_index
EXPLAIN SELECT * FROM game_sessions WHERE user_id = 1 AND puzzle_id = 1 AND status = 'in_progress' LIMIT 1;
