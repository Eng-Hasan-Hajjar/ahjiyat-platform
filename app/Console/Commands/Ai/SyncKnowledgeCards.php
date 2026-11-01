<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use App\Services\AiAssistant\Knowledge\KnowledgeSafetyException;
use App\Services\AiAssistant\Local\Cards\CardLibrary;
use Illuminate\Console\Command;

/**
 * TI - يزامن «بطاقات المعرفة» (CardLibrary) كمسودات في جدول مقالات المعرفة نفسه، بالدورة التحريرية ذاتها لـE45 (مسودة ← مراجعة ← نشر).
 *  - الافتراضي: ينشئ المفقود فقط كمسودات ولا يلمس ما حرّره المحرر ولا ما نُشر.
 *  - --refresh: يعيد كتابة البطاقات «distilled» من الكود؛ المنشور المتغيّر يعود مسودة (لا يُخدَم محتوى غير مراجَع).
 *  - --publish: نشر صريح بعد فحص الأمان (المخالف لا ينشر ويُبلَّغ عنه). لا نشر ضمني ولا آلي من الكود.
 * لا يقرأ ملفات ولا شبكة ولا أسرارًا، ولا يمسّ جداول الاقتصاد.
 */
class SyncKnowledgeCards extends Command
{
    protected $signature = 'ai:knowledge:sync-cards {--refresh : أعد كتابة البطاقات المستخلصة من الكود} {--publish : انشر المسودات بعد فحص الأمان}';

    protected $description = 'TI: يزامن بطاقات معرفة ميزات المستخدم كمسودات (idempotent)، وينشرها صراحةً مع --publish فقط.';

    public function handle(KnowledgePublisher $publisher): int
    {
        $created = $updated = $unchanged = $published = 0;
        $blocked = [];

        foreach (CardLibrary::articles() as $item) {
            $editorial = [
                'slug' => $item['slug'],
                'locale' => 'ar',
                'title' => $item['title'],
                'summary' => $item['summary'],
                'body' => $item['body'],
                'audience' => $item['audience'],
                'area' => $item['area'],
                'tags' => $item['tags'],
                'source_label' => 'دليل المنصة',
                'canonical_path' => $item['canonical_path'],
                'source_type' => AiKnowledgeArticle::TYPE_DISTILLED,
                'source_reference' => $item['source_reference'],
            ];

            $article = AiKnowledgeArticle::query()->where('locale', 'ar')->where('slug', $item['slug'])->first();

            if ($article === null) {
                $article = new AiKnowledgeArticle;
                $article->forceFill($editorial + ['status' => AiKnowledgeArticle::STATUS_DRAFT, 'version' => 1])->save();
                $created++;
            } elseif ($this->option('refresh') && $article->source_type === AiKnowledgeArticle::TYPE_DISTILLED) {
                $article->fill($editorial);

                if ($article->isDirty()) {
                    $article->save();
                    $publisher->markEdited($article->refresh());
                    $updated++;
                } else {
                    $unchanged++;
                }
            } else {
                $unchanged++;
            }

            if ($this->option('publish') && $article->refresh()->status === AiKnowledgeArticle::STATUS_DRAFT) {
                try {
                    $publisher->publish($article);
                    $published++;
                } catch (KnowledgeSafetyException $e) {
                    $blocked[] = $article->slug.' ('.implode(',', $e->violations).')';
                }
            }
        }

        $this->info("بطاقات جديدة: {$created} · محدَّثة: {$updated} · بلا تغيير: {$unchanged} · منشورة الآن: {$published}");

        foreach ($blocked as $line) {
            $this->error('لم يُنشر: '.$line);
        }

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
