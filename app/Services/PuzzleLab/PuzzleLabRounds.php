<?php

namespace App\Services\PuzzleLab;

use App\GameEngine\GameTypeRegistry;
use App\GameEngine\Support\SequenceTokens;
use App\Models\Puzzle;
use App\Models\PuzzleLabExercise;
use App\Models\User;

/**
 * E47 - جولات «مختبر الأحجيات»: تدريب بلا حالة مخزَّنة وبلا أي أثر رسمي.
 *
 * المبدأ: الجولة = رمز عشوائي (nonce) يولّده الخادم عند البدء ولا يُخزَّن في أي مكان. كل ما يخصّ الجولة يُشتقّ عند الطلب بـHMAC من (مفتاح التطبيق + المستخدم + التمرين + nonce):
 *   - memory:   توزيع الأوجه على مواضع البطاقات (خلط حتمي). لا تُرسل الأوجه عند بدء الجولة؛ يكشف الخادم وجه البطاقة عند قلبها فقط (flip).
 *   - sequence: رموز معتمة لكل عنصر خاصة بالجولة (SequenceTokens بمفتاح جولة)؛ ترتيب الحل لا يصل للعميل ولا يمكن استنتاجه من ترتيب العرض.
 *
 * التحقق من النجاح يتم هنا على الخادم بالمدقّقات الرسمية نفسها (MemoryMatchValidator / SequenceMatchValidator) على أحجية عابرة في الذاكرة لا تُحفظ أبدًا.
 *
 * عمدًا لا تعتمد هذه الخدمة على: PuzzleAttemptService, GameSessionService, GemWalletService, ProgressionService, أي نموذج أو جدول محاولات/محافظ/مهام/حملات/ترتيب.
 * (يتحقق منه اختبار ثابت يفحص مصدر مجلد PuzzleLab والمتحكّم). لذلك لا يوجد مسار يمكن أن يمنح جواهر أو XP أو يحدّث تقدّمًا رسميًا من جولة تدريب.
 */
class PuzzleLabRounds
{
    public const NONCE_LENGTH = 24;

    /** طول رمز عنصر التسلسل الأقصى المقبول من العميل (الرموز 16 حرفًا؛ هامش صغير فقط). */
    private const MAX_TOKEN_LENGTH = 32;

    public function __construct(protected GameTypeRegistry $games) {}

    public function newNonce(): string
    {
        return substr(bin2hex(random_bytes(self::NONCE_LENGTH)), 0, self::NONCE_LENGTH);
    }

    public function isValidNonce(mixed $nonce): bool
    {
        return is_string($nonce) && (bool) preg_match('/^[a-f0-9]{'.self::NONCE_LENGTH.'}$/', $nonce);
    }

    /**
     * بيانات بدء الجولة للعميل. لا تحتوي أوجه بطاقات الذاكرة ولا ترتيب الحل.
     *
     * @return array<string, mixed>
     */
    public function start(PuzzleLabExercise $exercise, User $user): array
    {
        $nonce = $this->newNonce();
        $puzzle = $this->roundPuzzle($exercise, $user, $nonce);

        if ($exercise->game_type === PuzzleLabExercise::TYPE_MEMORY) {
            return [
                'round' => $nonce,
                'type' => PuzzleLabExercise::TYPE_MEMORY,
                'pairs' => intdiv(count((array) $puzzle->game_config['cards']), 2),
                'cards' => array_map(fn (array $card) => ['id' => $card['id']], (array) $puzzle->game_config['cards']),
            ];
        }

        return [
            'round' => $nonce,
            'type' => PuzzleLabExercise::TYPE_SEQUENCE,
            'items' => $this->shuffledItemsNotSolved($puzzle),
        ];
    }

    /**
     * وجه بطاقة ذاكرة واحدة (يُكشف عند القلب فقط). null إن لم تكن الجولة/البطاقة صالحة.
     *
     * @return array{card:int, face:string}|null
     */
    public function flip(PuzzleLabExercise $exercise, User $user, mixed $nonce, mixed $card): ?array
    {
        if ($exercise->game_type !== PuzzleLabExercise::TYPE_MEMORY || ! $this->isValidNonce($nonce) || ! (is_int($card) || (is_string($card) && ctype_digit($card)))) {
            return null;
        }

        $cardId = (int) $card;
        $cards = (array) $this->roundPuzzle($exercise, $user, (string) $nonce)->game_config['cards'];

        foreach ($cards as $entry) {
            if ($entry['id'] === $cardId) {
                return ['card' => $cardId, 'face' => (string) $entry['face']];
            }
        }

        return null;
    }

    /**
     * التحقق السيرفري من نتيجة الجولة. لا يُرجع الحل أبدًا: ملاحظة تعليمية عند الخطأ، وشرح موجز عند النجاح.
     * null إن كان الطلب غير صالح الشكل (جولة غير معروفة/مدخلات مشوّهة).
     *
     * @param  array<string, mixed>  $input
     * @return array{correct:bool, note:?string, explanation:?string}|null
     */
    public function check(PuzzleLabExercise $exercise, User $user, mixed $nonce, array $input): ?array
    {
        if (! $this->isValidNonce($nonce)) {
            return null;
        }

        $payload = $this->sanitizedSubmission($exercise, $input);

        if ($payload === null) {
            return null;
        }

        $puzzle = $this->roundPuzzle($exercise, $user, (string) $nonce);
        $correct = $this->games->validatorFor($puzzle)->check($puzzle, $payload + ['_context' => ['user_id' => $user->id]])->correct;

        return [
            'correct' => $correct,
            'note' => $correct ? null : $this->wrongNote($exercise),
            'explanation' => $correct ? (filled($exercise->success_explanation) ? (string) $exercise->success_explanation : null) : null,
        ];
    }

    /** ملاحظة الخطأ: ما كتبه المحرّر، وإلا جملة عامة لنوع التمرين (لا تكشف الحل أبدًا). */
    public function wrongNote(PuzzleLabExercise $exercise): string
    {
        if (filled($exercise->wrong_note)) {
            return (string) $exercise->wrong_note;
        }

        return $exercise->game_type === PuzzleLabExercise::TYPE_MEMORY
            ? 'ليست كل البطاقات متطابقة بعد. تذكّر مواضع الأوجه التي رأيتها وابدأ بالأوجه التي تثق بمكانها.'
            : 'الترتيب ليس صحيحًا بعد. ابحث عن العنصر الذي يأتي أولًا بالتأكيد ثم الذي يليه، وراجع ما يربط العناصر ببعضها.';
    }

    /**
     * أحجية عابرة (غير محفوظة) تمثّل الجولة: تُبنى بالتطبيع الرسمي للمحرك ثم يُخصَّص مفتاحها بالجولة. لا تُحفظ ولا تُسجَّل، وتزول بنهاية الطلب.
     * المفتاح نصي (لا autoincrement) كي لا يتحوّل إلى 0 بالتحويل الرقمي، ويدخل في رموز التسلسل فيصير خاصًا بالجولة.
     */
    protected function roundPuzzle(PuzzleLabExercise $exercise, User $user, string $nonce): Puzzle
    {
        $definition = $this->games->definitionFor($exercise->game_type);
        $roundKey = 'lab:'.$user->getKey().':'.$exercise->slug.':'.$nonce;

        $puzzle = new Puzzle;
        $puzzle->setIncrementing(false);
        $puzzle->setKeyType('string');
        $puzzle->forceFill([
            'id' => $roundKey,
            'game_type' => $exercise->game_type,
            'renderer' => $definition->renderer(),
            'validation_type' => $definition->validationType(),
            'score_mode' => $definition->scoreMode(),
            'time_limit_seconds' => null,   // لا مؤقت خسارة في التدريب
            'is_active' => false,
            'gem_reward' => 0,
            'xp_reward' => 0,
            'game_config' => $exercise->game_type === PuzzleLabExercise::TYPE_MEMORY
                ? ['faces' => $exercise->faces()]
                : ['items' => $exercise->items()],
        ]);

        $this->games->prepareForSave($puzzle);

        if ($exercise->game_type === PuzzleLabExercise::TYPE_MEMORY) {
            $puzzle->game_config = $this->withShuffledCards((array) $puzzle->game_config, $roundKey);
        }

        return $puzzle;
    }

    /**
     * يعيد توزيع الأوجه على مواضع البطاقات خلطًا حتميًا مشتقًّا من مفتاح الجولة (يتغيّر بكل جولة، ولا يمكن التنبؤ به بلا مفتاح التطبيق).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function withShuffledCards(array $config, string $roundKey): array
    {
        $faces = array_column((array) $config['cards'], 'face');

        for ($i = count($faces) - 1; $i > 0; $i--) {
            $j = hexdec(substr(hash_hmac('sha256', 'shuffle:'.$roundKey.':'.$i, (string) config('app.key')), 0, 8)) % ($i + 1);
            [$faces[$i], $faces[$j]] = [$faces[$j], $faces[$i]];
        }

        $config['cards'] = array_map(fn (string $face, int $id) => ['id' => $id, 'face' => $face], $faces, array_keys($faces));

        return $config;
    }

    /** عناصر مخلوطة برموز الجولة، مع ضمان ألا يكون العرض الأول هو الترتيب الصحيح نفسه. @return list<array{idx:string,label:string}> */
    protected function shuffledItemsNotSolved(Puzzle $puzzle): array
    {
        $expected = SequenceTokens::expectedOrder($puzzle);
        $items = SequenceTokens::shuffledItems($puzzle);

        for ($try = 0; $try < 5 && array_column($items, 'idx') === $expected; $try++) {
            $items = SequenceTokens::shuffledItems($puzzle);
        }

        if (array_column($items, 'idx') === $expected) {
            $items = array_reverse($items);
        }

        return array_values($items);
    }

    /**
     * يطبّق شكل الإدخال المسموح فقط ويرمي ما سواه (لا مصفوفات متداخلة ولا أحجام غير معقولة) قبل وصوله للمدقّق الرسمي.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    protected function sanitizedSubmission(PuzzleLabExercise $exercise, array $input): ?array
    {
        $limit = PuzzleLabExercise::MAX_ENTRIES;

        if ($exercise->game_type === PuzzleLabExercise::TYPE_MEMORY) {
            $matches = $input['matches'] ?? null;

            if (! is_array($matches) || count($matches) > $limit) {
                return null;
            }

            $clean = [];

            foreach ($matches as $pair) {
                if (! is_array($pair) || count($pair) !== 2) {
                    return null;
                }

                foreach ($pair as $id) {
                    if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                        return null;
                    }
                }

                $clean[] = array_map('intval', array_values($pair));
            }

            return ['matches' => $clean];
        }

        $order = $input['order'] ?? null;

        if (! is_array($order) || count($order) > $limit) {
            return null;
        }

        foreach ($order as $token) {
            if (! is_string($token) || strlen($token) > self::MAX_TOKEN_LENGTH) {
                return null;
            }
        }

        return ['order' => array_values($order)];
    }
}
