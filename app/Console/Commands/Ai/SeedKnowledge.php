<?php

namespace App\Console\Commands\Ai;

use App\Models\AiKnowledgeArticle;
use App\Services\AiAssistant\Knowledge\CuratedKnowledge;
use App\Services\AiAssistant\Knowledge\KnowledgePublisher;
use App\Services\AiAssistant\Knowledge\KnowledgeSafetyException;
use Illuminate\Console\Command;

/**
 * E45 - يزرع المقالات المنقّحة يدويًا (CuratedKnowledge) كمسودات. لا استيراد آلي لمجلد الوثائق ولا قراءة ملفات: المحتوى مكتوب بالكود ومراجَع.
 *  - الافتراضي: ينشئ المفقود فقط كمسودات (لا يلمس ما حرّره المحرر).
 *  - --refresh: يعيد كتابة المقالات «distilled» من المصدر؛ المقال المنشور المتغيّر يعود مسودة (لا يُخدَم محتوى غير مراجَع).
 *  - --publish: ينشر المسودات من هذه الحزمة بعد فحص الأمان (المخالف لا ينشر ويُبلَّغ عنه). نشر صريح، لا ضمني.
 */
class SeedKnowledge extends Command
{
    protected $signature = 'ai:knowledge:seed {--refresh : أعد كتابة المقالات المستخلصة من المصدر} {--publish : انشر المسودات بعد فحص الأمان}';

    protected $description = 'E45: يزرع مقالات دليل المنصة المنقّحة يدويًا كمسودات (idempotent)، وينشرها صراحةً مع --publish.';

    public function handle(KnowledgePublisher $publisher): int
    {
        $created = $updated = $unchanged = $published = 0;
        $blocked = [];

        foreach (CuratedKnowledge::articles() as $item) {
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

        $this->info("مقالات جديدة: {$created} · محدَّثة: {$updated} · بلا تغيير: {$unchanged} · منشورة الآن: {$published}");

        foreach ($blocked as $line) {
            $this->error('لم يُنشر: '.$line);
        }

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }
}
