<?php

use App\Models\AiConversation;
use App\Models\AiKnowledgeArticle;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AiAssistant\Providers\FakeAiProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/*
| E45 - مساعدات مشتركة لاختبارات «تطوّر AI». ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| SQLite وكاش array وطابور sync وجلسة array؛ **لا شبكة إطلاقًا**: Http::preventStrayRequests() ومزوّد «fake» الحتمي وحده.
| المعرفة تُزرع من الحزمة المنقّحة (ai:knowledge:seed --publish) أو بمقالات اختبارية صغيرة؛ لا قراءة لمجلد الوثائق.
*/

require_once __DIR__.'/../Social/SocialTestHelpers.php';

if (! function_exists('e45_enable')) {
    /** يفعّل المساعد بمزوّد fake. $config يتجاوز أي مفتاح (مثل guests_enabled أو streaming_enabled). يعيد المزوّد الاختباري لتصفيف السيناريوهات. */
    function e45_enable(array $config = []): FakeAiProvider
    {
        Http::preventStrayRequests();
        Cache::flush();
        RateLimiter::clear('tai');

        config(array_merge([
            'tatawwur_ai.enabled' => true,
            'tatawwur_ai.provider_enabled' => true,
            'tatawwur_ai.guests_enabled' => false,
            'tatawwur_ai.streaming_enabled' => false,
            'tatawwur_ai.provider' => 'fake',
            'tatawwur_ai.model' => 'fake-model',
            'tatawwur_ai.limits.user_per_minute' => 60,
            'tatawwur_ai.limits.guest_per_minute' => 30,
            'tatawwur_ai.limits.burst_per_ten_seconds' => 10,
            'tatawwur_ai.limits.user_per_day' => 2000,
            'tatawwur_ai.limits.guest_per_day' => 500,
        ], $config));

        return app(FakeAiProvider::class);
    }

    /** المقالات المنقّحة كلها منشورة (حزمة CuratedKnowledge) - مصدر معرفة واقعي للاختبارات. */
    function e45_kb(): void
    {
        Artisan::call('ai:knowledge:seed', ['--publish' => true]);
    }

    /** مقال اختباري منشور بمحتوى معروف. @return AiKnowledgeArticle */
    function e45_article(string $slug, string $title, string $body, array $over = []): AiKnowledgeArticle
    {
        $article = new AiKnowledgeArticle;
        $article->forceFill($over + [
            'slug' => $slug, 'locale' => 'ar', 'title' => $title, 'summary' => 'ملخص اختباري للمقال '.$title.' يشرح موضوعه باختصار.', 'body' => $body,
            'audience' => 'public', 'area' => 'general', 'tags' => null, 'source_label' => 'دليل المنصة', 'canonical_path' => '/puzzles', 'source_type' => 'curated',
            'status' => AiKnowledgeArticle::STATUS_DRAFT, 'version' => 1,
        ])->save();

        app(App\Services\AiAssistant\Knowledge\KnowledgePublisher::class)->publish($article);

        return $article->refresh();
    }

    /** POST /tatawwur/messages بصيغة JSON. */
    function e45_ask($test, string $message, array $extra = [])
    {
        return $test->postJson(route('tatawwur.messages.store'), ['message' => $message] + $extra);
    }

    function e45_member(array $attrs = []): User
    {
        return e16User($attrs);
    }

    function e45_conversation(User $user, int $messages = 0): AiConversation
    {
        $conversation = app(App\Services\AiAssistant\Conversation\ConversationService::class)->start($user);

        for ($i = 1; $i <= $messages; $i++) {
            app(App\Services\AiAssistant\Conversation\ConversationService::class)->append($conversation, $i % 2 ? AiMessage::ROLE_USER : AiMessage::ROLE_ASSISTANT, 'رسالة '.$i);
        }

        return $conversation->refresh();
    }

    /** عدّاد صفوف كل الجداول (عدا الجلسات/الكاش/الطوابير وجداول المساعد نفسه) لإثبات عدم وجود أي أثر جانبي خارج المساعد. */
    function e45_db_snapshot(bool $includeAssistant = false): array
    {
        $counts = [];

        foreach (Illuminate\Support\Facades\Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (in_array($table, ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'migrations'], true) || (! $includeAssistant && str_starts_with($table, 'ai_'))) {
                continue;
            }

            $counts[$table] = Illuminate\Support\Facades\DB::table($table)->count();
        }

        ksort($counts);

        return $counts;
    }
}
