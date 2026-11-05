<?php

namespace App\Services\Cinematic\Demo;

use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicStory;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicReadinessChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * CIN-1 - وسيلة الإدخال الأولية لمحتوى المرحلة الأولى («المكتبة الغامضة») من تعريف JSON مبني على وثيقة ياسمين.
 *
 * حدود صارمة:
 *  - بيئات local/testing فقط. في أي بيئة أخرى (إنتاج، staging) ترفض الخدمة قبل أي كتابة.
 *  - تُنشئ مسودة فقط ولا تنشر شيئًا أبدًا، ولا تربط أي وسيط، ولا تُنشئ فيديو. مشاهد الفيديو تبقى «بانتظار الإنتاج» وفحص الجاهزية يمنع نشرها.
 *  - لا تلمس الاقتصاد (جواهر/خبرة) ولا المدفوعات ولا السلاسل التقليدية.
 *  - إعادة التشغيل آمنة: إن وُجدت القصة (بالـslug) لا يُعدَّل شيء.
 */
final class Stage1DemoInstaller
{
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public const FORMAT = 'cinematic-stage-definition/1';

    public function __construct(
        protected CinematicAuthoringService $authoring,
        protected CinematicReadinessChecker $readiness,
    ) {}

    public static function definitionPath(): string
    {
        return database_path('demo'.DIRECTORY_SEPARATOR.'cinematic'.DIRECTORY_SEPARATOR.'library-mystery-stage-1.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $path = self::definitionPath();

        if (! File::isFile($path)) {
            throw CinematicException::make('demo_definition_missing', 'ملف تعريف المحتوى التجريبي غير موجود.', 500);
        }

        $definition = File::json($path);

        if (! is_array($definition) || ($definition['format'] ?? null) !== self::FORMAT || ! is_array($definition['chapters'] ?? null)) {
            throw CinematicException::make('demo_definition_invalid', 'ملف تعريف المحتوى التجريبي غير صالح.', 500);
        }

        return $definition;
    }

    /**
     * @return array{story: CinematicStory, revision: CinematicRevision, created: bool, readiness: array<string, mixed>}
     */
    public function install(): array
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw CinematicException::make('demo_forbidden', 'تثبيت المحتوى التجريبي مسموح في بيئة التطوير المعزولة فقط.', 403);
        }

        $definition = self::definition();
        $slug = (string) $definition['story']['slug'];
        $existing = CinematicStory::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            $revision = $existing->revisions()->orderByDesc('id')->first();

            return ['story' => $existing, 'revision' => $revision, 'created' => false, 'readiness' => $this->readiness->check($revision)];
        }

        return DB::transaction(function () use ($definition) {
            $story = $this->authoring->createStory((array) $definition['story'], null);
            $revision = $story->revisions()->orderBy('id')->firstOrFail();
            $stage = $this->authoring->addStage($revision, (array) $definition['stage']);

            foreach ($definition['chapters'] as $chapterDef) {
                $chapter = $this->authoring->addChapter($stage, [
                    'stable_key' => $chapterDef['stable_key'],
                    'title' => $chapterDef['title'],
                    'summary' => $chapterDef['summary'] ?? null,
                ]);

                foreach ($chapterDef['scenes'] as $sceneDef) {
                    $scene = $this->authoring->addScene($chapter, [
                        'stable_key' => $sceneDef['stable_key'],
                        'type' => $sceneDef['type'],
                        'title' => $sceneDef['title'],
                        'duration_hint_seconds' => $sceneDef['duration_hint_seconds'] ?? null,
                        'awaiting_media' => (bool) ($sceneDef['awaiting_media'] ?? false),
                        'unlock_rule' => $sceneDef['unlock_rule'] ?? [],
                        'config' => $sceneDef['config'] ?? [],
                    ]);

                    if (is_array($sceneDef['solution'] ?? null)) {
                        $errors = $this->authoring->setInteractionSolution($scene, $sceneDef['solution']);

                        if ($errors !== []) {
                            throw CinematicException::make('demo_solution_invalid', "حل المشهد {$sceneDef['stable_key']} غير صالح: ".implode(' | ', $errors), 500);
                        }
                    }
                }
            }

            $revision = $revision->fresh();

            return ['story' => $story, 'revision' => $revision, 'created' => true, 'readiness' => $this->readiness->check($revision)];
        });
    }
}
