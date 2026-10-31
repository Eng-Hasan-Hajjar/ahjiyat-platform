<?php

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AiAssistant\Conversation\ConversationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/_support.php';

/*
|--------------------------------------------------------------------------
| E45 - حدود «تطوّر AI» في القاعدة نفسها. سائق-محايد: SQLite افتراضيًا وMySQL 8 عبر phpunit.mysql.xml.dist (خطوة CI المخصصة).
| تثبت: فرادة (اللغة، المعرّف النصي) للمقال و(المقال، الموضع) للمقطع، المعرّفات العامة الفريدة، ترتيب الرسائل الحتمي وفرادة (المحادثة، التسلسل)،
| تقييم واحد لكل (رسالة، مستخدم) مع تعايش NULL، سياسة المفاتيح الأجنبية (حذف المحادثة/المستخدم يزيل الرسائل؛ التقييمات والمقالات تُفكّ ولا تُحذف تتابعيًا)،
| جسم الرسالة المشفَّر والنص العربي والرموز التعبيرية حتى حدود الأعمدة، الاحتفاظ (الحذف المنتهي دون الطازج)، الفهارس المسماة، ولا float/decimal،
| ثم مسار كامل لرسالة عضو على المحرك الحقيقي. لا اتصال بأي مزوّد (Http::preventStrayRequests + المزوّد الحتمي).
|--------------------------------------------------------------------------
*/

const E45DB_TABLES = ['ai_knowledge_articles', 'ai_knowledge_chunks', 'ai_conversations', 'ai_messages', 'ai_feedback', 'ai_request_metrics'];

function e45db_violates(callable $call): bool
{
    try {
        DB::transaction($call);
    } catch (QueryException) {
        return true;
    }

    return false;
}

function e45db_article(array $over = []): int
{
    return (int) DB::table('ai_knowledge_articles')->insertGetId($over + [
        'public_id' => (string) Str::ulid(), 'slug' => 'article-'.Str::lower(Str::random(10)), 'locale' => 'ar', 'title' => 'عنوان', 'summary' => 'ملخص', 'body' => 'نص',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function e45db_chunk(int $article, int $position, array $over = []): int
{
    return (int) DB::table('ai_knowledge_chunks')->insertGetId($over + [
        'article_id' => $article, 'article_version' => 1, 'position' => $position, 'body' => 'مقطع', 'source_label' => 'دليل المنصة',
        'content_hash' => str_repeat('a', 64), 'char_count' => 4, 'created_at' => now(),
    ]);
}

function e45db_conversation(int $userId, array $over = []): int
{
    return (int) DB::table('ai_conversations')->insertGetId($over + [
        'public_id' => (string) Str::ulid(), 'user_id' => $userId, 'messages_count' => 0, 'last_message_at' => now(), 'expires_at' => now()->addDays(30),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function e45db_message(int $conversation, int $seq, array $over = []): int
{
    return (int) DB::table('ai_messages')->insertGetId($over + [
        'public_id' => (string) Str::ulid(), 'conversation_id' => $conversation, 'seq' => $seq, 'role' => 'user', 'body' => 'x', 'created_at' => now(),
    ]);
}

function e45db_feedback(?int $message, ?int $user, array $over = []): int
{
    return (int) DB::table('ai_feedback')->insertGetId($over + ['message_id' => $message, 'user_id' => $user, 'rating' => 'up', 'created_at' => now(), 'updated_at' => now()]);
}

// ───────────────────────────── الفرادة ─────────────────────────────

test('E45-DB-01: an article slug is unique per locale, public ids are unique, and a chunk position is unique per article', function () {
    $a = e45db_article(['slug' => 'gems', 'locale' => 'ar']);
    e45db_article(['slug' => 'gems', 'locale' => 'en']);                     // نفس المعرّف بلغة أخرى مسموح

    expect(e45db_violates(fn () => e45db_article(['slug' => 'gems', 'locale' => 'ar'])))->toBeTrue();

    $public = (string) DB::table('ai_knowledge_articles')->where('id', $a)->value('public_id');
    expect(e45db_violates(fn () => e45db_article(['public_id' => $public])))->toBeTrue();

    e45db_chunk($a, 0);
    e45db_chunk($a, 1);
    expect(e45db_violates(fn () => e45db_chunk($a, 1)))->toBeTrue();

    $b = e45db_article();
    e45db_chunk($b, 1);                                                      // الموضع نفسه في مقال آخر مسموح
    expect(DB::table('ai_knowledge_chunks')->where('position', 1)->count())->toBe(2);
});

test('E45-DB-02: conversation, message and metric public ids are unique, and (conversation, seq) is unique so concurrent sends cannot interleave', function () {
    $user = e16User();
    $c1 = e45db_conversation($user->id);
    $c2 = e45db_conversation($user->id);

    $publicConversation = (string) DB::table('ai_conversations')->where('id', $c1)->value('public_id');
    expect(e45db_violates(fn () => e45db_conversation($user->id, ['public_id' => $publicConversation])))->toBeTrue();

    $m = e45db_message($c1, 1);
    e45db_message($c2, 1);                                                   // seq نفسه في محادثة أخرى مسموح

    expect(e45db_violates(fn () => e45db_message($c1, 1)))->toBeTrue();

    $publicMessage = (string) DB::table('ai_messages')->where('id', $m)->value('public_id');
    expect(e45db_violates(fn () => e45db_message($c1, 2, ['public_id' => $publicMessage])))->toBeTrue();

    $metric = ['public_id' => (string) Str::ulid(), 'audience' => 'member', 'outcome' => 'answered', 'created_at' => now()];
    DB::table('ai_request_metrics')->insert($metric);
    expect(e45db_violates(fn () => DB::table('ai_request_metrics')->insert($metric)))->toBeTrue();
});

test('E45-DB-03: messages read back in sequence order whatever the insertion order, and the last sequence is the count', function () {
    $user = e16User();
    $c = e45db_conversation($user->id);

    foreach ([3, 1, 4, 2] as $seq) {
        e45db_message($c, $seq, ['body' => "m{$seq}"]);
    }

    expect(DB::table('ai_messages')->where('conversation_id', $c)->orderBy('seq')->pluck('seq')->map(fn ($v) => (int) $v)->all())->toBe([1, 2, 3, 4])
        ->and((int) DB::table('ai_messages')->where('conversation_id', $c)->max('seq'))->toBe(4);

    // الخدمة تبني التسلسل نفسه بلا ثغرات.
    $service = app(ConversationService::class);
    $conversation = AiConversation::query()->findOrFail($service->start($user)->id);
    foreach (['أ', 'ب', 'ج'] as $text) {
        $service->append($conversation, AiMessage::ROLE_USER, $text);
    }

    expect(DB::table('ai_messages')->where('conversation_id', $conversation->id)->orderBy('seq')->pluck('seq')->map(fn ($v) => (int) $v)->all())->toBe([1, 2, 3])
        ->and((int) DB::table('ai_conversations')->where('id', $conversation->id)->value('messages_count'))->toBe(3);
});

test('E45-DB-04: one feedback per (message, user); erased users and deleted messages coexist as NULL and the anonymous rating survives', function () {
    $alice = e16User();
    $bob = e16User();
    $c = e45db_conversation($alice->id);
    $m = e45db_message($c, 1, ['role' => 'assistant']);

    e45db_feedback($m, $alice->id);
    e45db_feedback($m, $bob->id);                                            // مستخدم آخر لنفس الرسالة مسموح
    expect(e45db_violates(fn () => e45db_feedback($m, $alice->id)))->toBeTrue();

    // NULL يتعايش (فرادة MySQL/SQLite لا تعدّ NULL متساوية).
    e45db_feedback($m, null);
    e45db_feedback($m, null);
    e45db_feedback(null, $alice->id, ['rating' => 'down']);
    e45db_feedback(null, $alice->id, ['rating' => 'down']);

    $before = DB::table('ai_feedback')->count();
    DB::table('users')->where('id', $bob->id)->delete();
    expect(DB::table('ai_feedback')->count())->toBe($before)->and(DB::table('ai_feedback')->whereNotNull('message_id')->whereNull('user_id')->count())->toBeGreaterThanOrEqual(3);

    DB::table('ai_messages')->where('id', $m)->delete();
    expect(DB::table('ai_feedback')->count())->toBe($before)->and(DB::table('ai_feedback')->whereNotNull('message_id')->count())->toBe(0);
});

// ───────────────────────────── سياسة المفاتيح الأجنبية ─────────────────────────────

test('E45-DB-05: the live database agrees - owner deletion cascades, editor references are SET NULL, and feedback is never cascaded away', function () {
    $expected = [
        'ai_knowledge_articles' => ['created_by' => 'set null', 'reviewed_by' => 'set null'],
        'ai_knowledge_chunks' => ['article_id' => 'cascade'],
        'ai_conversations' => ['user_id' => 'cascade'],
        'ai_messages' => ['conversation_id' => 'cascade'],
        'ai_feedback' => ['message_id' => 'set null', 'user_id' => 'set null'],
        'ai_request_metrics' => [],
    ];

    foreach ($expected as $table => $policy) {
        $found = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            $found[$fk['columns'][0]] = strtolower((string) $fk['on_delete']);
        }

        ksort($found);
        ksort($policy);
        expect($found)->toBe($policy, $table);
    }

    // لا مفتاح أجنبي من جدول المقاييس: لا هوية فيه أصلًا.
    expect(Schema::hasColumn('ai_request_metrics', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('ai_request_metrics', 'body'))->toBeFalse()
        ->and(Schema::hasColumn('ai_request_metrics', 'message'))->toBeFalse();
});

test('E45-DB-06: deleting a user removes the conversations and messages, deleting a conversation removes its messages, and deleting an article removes its chunks', function () {
    $user = e16User();
    $c = e45db_conversation($user->id);
    e45db_message($c, 1);
    e45db_message($c, 2);
    $other = e45db_conversation(e16User()->id);
    e45db_message($other, 1);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('ai_conversations')->where('id', $c)->exists())->toBeFalse()
        ->and(DB::table('ai_messages')->where('conversation_id', $c)->count())->toBe(0)
        ->and(DB::table('ai_messages')->where('conversation_id', $other)->count())->toBe(1);

    $article = e45db_article();
    e45db_chunk($article, 0);
    e45db_chunk($article, 1);
    DB::table('ai_knowledge_articles')->where('id', $article)->delete();

    expect(DB::table('ai_knowledge_chunks')->where('article_id', $article)->count())->toBe(0);
});

test('E45-DB-07: an editor who is deleted leaves the article intact with NULL references', function () {
    $editor = e16User();
    $article = e45db_article(['created_by' => $editor->id, 'reviewed_by' => $editor->id]);

    DB::table('users')->where('id', $editor->id)->delete();

    $row = DB::table('ai_knowledge_articles')->where('id', $article)->first();
    expect($row)->not->toBeNull()->and($row->created_by)->toBeNull()->and($row->reviewed_by)->toBeNull();
});

// ───────────────────────────── النص والتشفير ─────────────────────────────

test('E45-DB-08: Arabic text and emoji round-trip up to the column limits (title 160, summary 400, tags 300, section 160, label 60, slug 120)', function () {
    $article = e45db_article();

    foreach (['ع', '🧩'] as $glyph) {
        DB::table('ai_knowledge_articles')->where('id', $article)->update([
            'title' => str_repeat($glyph, 160), 'summary' => str_repeat($glyph, 400), 'tags' => str_repeat($glyph, 300), 'source_label' => str_repeat($glyph, 60),
            'canonical_path' => '/'.str_repeat('a', 189),
        ]);
        $row = DB::table('ai_knowledge_articles')->where('id', $article)->first();

        expect($row->title)->toBe(str_repeat($glyph, 160))->and($row->summary)->toBe(str_repeat($glyph, 400))->and($row->tags)->toBe(str_repeat($glyph, 300))
            ->and($row->source_label)->toBe(str_repeat($glyph, 60))->and(mb_strlen($row->canonical_path))->toBe(190);

        $chunk = e45db_chunk($article, $glyph === 'ع' ? 10 : 11, ['section' => str_repeat($glyph, 160), 'body' => str_repeat($glyph.' ', 400), 'source_label' => str_repeat($glyph, 60)]);
        $stored = DB::table('ai_knowledge_chunks')->where('id', $chunk)->first();

        expect($stored->section)->toBe(str_repeat($glyph, 160))->and($stored->body)->toBe(str_repeat($glyph.' ', 400));
    }

    if (DB::getDriverName() === 'mysql') {
        // MySQL الصارم يرفض تجاوز الطول ولا يقصّ بصمت (SQLite لا يفرض الطول فيُتحقَّق منه هنا فقط).
        expect(e45db_violates(fn () => DB::table('ai_knowledge_articles')->where('id', $article)->update(['title' => str_repeat('ع', 161)])))->toBeTrue()
            ->and(e45db_violates(fn () => e45db_article(['slug' => str_repeat('a', 121)])))->toBeTrue();
    }
});

test('E45-DB-09: a message body is stored encrypted - plaintext never reaches the column - and a long Arabic reply with emoji round-trips whole', function () {
    $user = e16User();
    $conversation = app(ConversationService::class)->start($user);
    $reply = str_repeat('الجواهر نقاط مكافآت داخلية 💎🧩 ', 120);              // ≈ 3700 حرفًا

    expect(mb_strlen($reply))->toBeLessThanOrEqual(4000);

    $message = app(ConversationService::class)->append($conversation, AiMessage::ROLE_ASSISTANT, $reply, [['label' => 'الجواهر', 'url' => '/help/gems']], 'answered');
    $raw = (string) DB::table('ai_messages')->where('id', $message->id)->value('body');

    expect($raw)->not->toContain('الجواهر')->not->toContain('💎')->and(strlen($raw))->toBeGreaterThan(strlen($reply))
        ->and(AiMessage::query()->findOrFail($message->id)->body)->toBe($reply);

    $sources = json_decode((string) DB::table('ai_messages')->where('id', $message->id)->value('sources'), true);
    expect($sources[0]['url'])->toBe('/help/gems')->and($sources[0]['label'])->toBe('الجواهر');
});

// ───────────────────────────── الاحتفاظ ─────────────────────────────

test('E45-DB-10: pruning removes expired conversations with their messages, old metrics and old feedback, and keeps everything fresh', function () {
    $user = e16User();
    $old = e45db_conversation($user->id, ['expires_at' => now()->subDay(), 'last_message_at' => now()->subDays(31)]);
    $fresh = e45db_conversation($user->id, ['expires_at' => now()->addDays(29)]);
    $oldMessage = e45db_message($old, 1);
    e45db_message($fresh, 1);

    foreach ([['created_at' => now()->subDays(91), 'outcome' => 'answered'], ['created_at' => now()->subDays(5), 'outcome' => 'refused']] as $metric) {
        DB::table('ai_request_metrics')->insert($metric + ['public_id' => (string) Str::ulid(), 'audience' => 'member']);
    }

    e45db_feedback($oldMessage, $user->id, ['created_at' => now()->subDays(181)]);
    e45db_feedback(null, $user->id, ['created_at' => now()->subDays(2)]);

    $result = app(ConversationService::class)->prune();

    expect($result)->toBe(['conversations' => 1, 'metrics' => 1, 'feedback' => 1])
        ->and(DB::table('ai_conversations')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('ai_messages')->where('conversation_id', $old)->count())->toBe(0)
        ->and(DB::table('ai_conversations')->where('id', $fresh)->exists())->toBeTrue()
        ->and(DB::table('ai_messages')->where('conversation_id', $fresh)->count())->toBe(1)
        ->and(DB::table('ai_request_metrics')->count())->toBe(1)
        ->and(DB::table('ai_feedback')->count())->toBe(1);
});

test('E45-DB-11: the retention and counter columns hold the full unsigned ranges and the expiry column is mandatory', function () {
    DB::table('ai_request_metrics')->insert([
        'public_id' => (string) Str::ulid(), 'audience' => 'member', 'outcome' => 'answered', 'input_tokens' => 4294967295, 'output_tokens' => 4294967295, 'latency_ms' => 4294967295,
        'tool_calls' => 255, 'created_at' => now(),
    ]);
    $row = DB::table('ai_request_metrics')->first();

    expect((int) $row->input_tokens)->toBe(4294967295)->and((int) $row->latency_ms)->toBe(4294967295)->and((int) $row->tool_calls)->toBe(255)
        ->and(DB::table('ai_request_metrics')->sum('input_tokens'))->toBeGreaterThan(4294967294);

    $user = e16User();
    $id = e45db_conversation($user->id, ['messages_count' => 65535]);
    expect((int) DB::table('ai_conversations')->where('id', $id)->value('messages_count'))->toBe(65535);

    expect(e45db_violates(fn () => DB::table('ai_conversations')->insert(['public_id' => (string) Str::ulid(), 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()])))->toBeTrue();
});

// ───────────────────────────── الفهارس والأعمدة ─────────────────────────────

test('E45-DB-12: named indexes exist within the 64-character limit, and no float / decimal column was introduced', function () {
    $expected = [
        'ai_knowledge_articles' => ['ai_knowledge_articles_public_unique', 'ai_knowledge_articles_slug_unique', 'ai_knowledge_articles_lookup_index', 'ai_knowledge_articles_created_by_index', 'ai_knowledge_articles_reviewed_by_index'],
        'ai_knowledge_chunks' => ['ai_knowledge_chunks_position_unique'],
        'ai_conversations' => ['ai_conversations_public_unique', 'ai_conversations_user_recent_index', 'ai_conversations_expires_index'],
        'ai_messages' => ['ai_messages_public_unique', 'ai_messages_conversation_seq_unique'],
        'ai_feedback' => ['ai_feedback_message_user_unique', 'ai_feedback_user_index', 'ai_feedback_created_index'],
        'ai_request_metrics' => ['ai_request_metrics_public_unique', 'ai_request_metrics_created_index'],
    ];

    foreach ($expected as $table => $names) {
        $actual = collect(Schema::getIndexes($table))->pluck('name')->all();

        foreach ($names as $name) {
            expect($actual)->toContain($name);
        }

        foreach ($actual as $name) {
            expect(strlen((string) $name))->toBeLessThanOrEqual(64, "{$table}.{$name}");
        }
    }

    foreach (E45DB_TABLES as $table) {
        foreach (Schema::getColumns($table) as $column) {
            expect($column['type_name'])->not->toContain('float')->not->toContain('double')->not->toContain('decimal');
        }
    }

    // لا جدول للمساعد يحمل مبلغًا أو عملة أو رصيدًا: ليس جدولًا ماليًا.
    foreach (E45DB_TABLES as $table) {
        foreach (Schema::getColumnListing($table) as $name) {
            expect(preg_match('/(?:^|_)(?:amount|price|balance|currency|gems?|xp|points|stars)(?:_|$)/i', $name))->toBe(0, "{$table}.{$name}");
        }
    }
});

test('E45-DB-13: the assistant tables are not registered as financial history and nothing in the migration reaches a foreign table', function () {
    $guard = \App\Services\Database\FinancialHistoryGuard::HISTORY_TABLES;

    foreach (E45DB_TABLES as $table) {
        expect($guard)->not->toHaveKey($table);
    }
});

// ───────────────────────────── المسار الكامل ─────────────────────────────

test('E45-DB-14: a member question runs end to end on the real engine - ordered encrypted messages, a content-free metric, an anonymous rating, and a user-owned wipe', function () {
    $fake = e45_enable();
    e45_kb();
    $fake->answer('الجواهر نقاط مكافآت داخلية وليست نقودًا مباشرة.');
    $user = e45_member();

    $reply = e45_ask($this->actingAs($user), 'ما هي الجواهر؟')->assertOk()->json();
    expect($reply['outcome'])->toBe('answered');

    $conversation = AiConversation::query()->where('user_id', $user->id)->firstOrFail();
    $rows = DB::table('ai_messages')->where('conversation_id', $conversation->id)->orderBy('seq')->get();

    expect($rows->pluck('seq')->map(fn ($v) => (int) $v)->all())->toBe([1, 2])
        ->and($rows->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($rows[0]->body)->not->toContain('الجواهر')->and($rows[1]->body)->not->toContain('الجواهر');

    $metric = DB::table('ai_request_metrics')->first();
    expect($metric)->not->toBeNull()->and($metric->audience)->toBe('member')->and($metric->outcome)->toBe('answered')
        ->and(json_encode($metric, JSON_UNESCAPED_UNICODE))->not->toContain('الجواهر')->not->toContain((string) $user->email);

    $this->actingAs($user)->postJson(route('tatawwur.feedback.store'), ['message' => $reply['id'], 'rating' => 'up'])->assertOk();
    expect(DB::table('ai_feedback')->count())->toBe(1);

    $this->actingAs($user)->deleteJson(route('tatawwur.history.destroy'))->assertOk();

    expect(DB::table('ai_conversations')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('ai_messages')->count())->toBe(0)
        ->and(DB::table('ai_feedback')->count())->toBe(1)
        ->and(DB::table('ai_feedback')->whereNull('user_id')->count())->toBe(1)
        ->and(DB::table('ai_request_metrics')->count())->toBe(1);
});

test('E45-DB-15: the knowledge lifecycle runs on the real engine - the curated pack publishes, chunks are versioned, and retrieval returns indexed evidence', function () {
    e45_enable();
    e45_kb();

    $published = DB::table('ai_knowledge_articles')->where('status', 'published')->count();
    $chunks = DB::table('ai_knowledge_chunks')->count();

    expect($published)->toBe(count(\App\Services\AiAssistant\Knowledge\CuratedKnowledge::articles()))->and($chunks)->toBeGreaterThanOrEqual($published);

    // لا مقطع يتيم ولا مقطع بنسخة لا تطابق نسخة مقاله.
    expect(DB::table('ai_knowledge_chunks as c')->leftJoin('ai_knowledge_articles as a', 'a.id', '=', 'c.article_id')->whereNull('a.id')->count())->toBe(0)
        ->and(DB::table('ai_knowledge_chunks as c')->join('ai_knowledge_articles as a', 'a.id', '=', 'c.article_id')->whereColumn('c.article_version', '!=', 'a.version')->count())->toBe(0);

    $found = app(\App\Services\AiAssistant\Knowledge\AiKnowledgeRetriever::class)->retrieve('ما هي الجواهر؟', 'member');
    expect($found)->not->toBeEmpty();
});
