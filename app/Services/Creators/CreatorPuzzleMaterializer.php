<?php

namespace App\Services\Creators;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\SequenceTokens;
use App\Models\CreatorPuzzleRevision;
use App\Models\Puzzle;

/**
 * E28 - يحوّل نسخة تأليف إلى أحجية قانونية (Puzzle) بالمحرك القائم نفسه: لا محرك ثانٍ ولا مدقّق ثانٍ.
 *  - renderer/validation_type/score_mode/game_type تُشتقّ حصرًا من Definition النوع (GameTypeRegistry) لا من المبدع.
 *  - الحفظ يمرّ بـPuzzle::saving → GameTypeRegistry::prepareForSave (تطبيع solution_data/cards) كأي أحجية، وبحارس النموذج الذي يفرض origin=community
 *    ومكافأة 0/XP 0/غير مفعّلة/بلا يومية على كل أحجية مجتمع.
 *  - بعد الحفظ فحص ذاتي: المدقّق الرسمي نفسه يجب أن يقبل حلّ المبدع، وإلا يفشل الاعتماد (rollback) فلا يُنشر ما لا يُحلّ.
 */
class CreatorPuzzleMaterializer
{
    public function __construct(protected GameTypeRegistry $games) {}

    /** أحجية غير محفوظة (للمعاينة وللمادّة): مُطبَّعة بالمحرك. */
    public function build(CreatorPuzzleRevision $revision): Puzzle
    {
        $cfg = CreatorContent::kindConfig((string) $revision->kind) ?? throw new CreatorException('نوع الأحجية غير مدعوم.');
        $content = (array) $revision->content;
        $definition = $this->games->definitionFor($cfg['game_type']);

        $puzzle = new Puzzle;
        $puzzle->fill([
            'puzzle_category_id' => $revision->puzzle_category_id,
            'title' => $revision->title,
            'type' => $cfg['type'],
            'difficulty' => $revision->difficulty,
            'prompt' => $content['prompt'] ?? '',
            'hint' => $content['hint'] ?? null,
            'max_attempts' => (int) config('creators.puzzle.max_attempts'),
            'gem_reward' => (int) config('creators.puzzle.gem_reward'),
            'xp_reward' => (int) config('creators.puzzle.xp_reward'),
            'is_active' => false,
            'game_type' => $cfg['game_type'],
            'renderer' => $definition->renderer(),
            'validation_type' => $definition->validationType(),
            'score_mode' => $definition->scoreMode(),
        ]);

        switch ($revision->kind) {
            case 'text':
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                break;
            case 'image':
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                $puzzle->image_path = $revision->image_path;
                break;
            case 'multiple_choice':
                $puzzle->choices = array_values((array) ($content['choices'] ?? []));
                $puzzle->answer_raw = (string) ($content['answer'] ?? '');
                break;
            case 'sequence':
                $puzzle->game_config = ['items' => array_values((array) ($content['items'] ?? []))];
                break;
            case 'memory':
                $puzzle->game_config = ['faces' => array_values((array) ($content['faces'] ?? []))];
                break;
        }

        $puzzle->forceFill(['origin' => Puzzle::ORIGIN_COMMUNITY]);
        $this->games->prepareForSave($puzzle);

        return $puzzle;
    }

    /** @throws CreatorException */
    public function materialize(CreatorPuzzleRevision $revision): Puzzle
    {
        $puzzle = $this->build($revision);
        $puzzle->save();

        if (! $this->solvesWithOfficialValidator($puzzle, (array) $revision->content, (string) $revision->kind)) {
            throw new CreatorException('الحل الذي أدخله المبدع لا يجتاز التحقق الرسمي للأحجية.');
        }

        return $puzzle;
    }

    /** هل يقبل المدقّق الرسمي حلّ المبدع نفسه؟ (تستعمله المعاينة أيضًا لتحذير المراجع). */
    public function solvesWithOfficialValidator(Puzzle $puzzle, array $content, string $kind): bool
    {
        $payload = match ($kind) {
            'sequence' => ['order' => SequenceTokens::expectedOrder($puzzle)],
            'memory' => ['matches' => $this->memoryPairs($puzzle)],
            default => ['answer' => (string) ($content['answer'] ?? '')],
        };

        return $this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => null]])->correct;
    }

    /** @return list<array{0:int,1:int}> */
    protected function memoryPairs(Puzzle $puzzle): array
    {
        $byFace = [];

        foreach ((array) ($puzzle->game_config['cards'] ?? []) as $card) {
            $byFace[$card['face']][] = $card['id'];
        }

        return array_values(array_map(fn ($ids) => [$ids[0], $ids[1] ?? $ids[0]], $byFace));
    }
}
