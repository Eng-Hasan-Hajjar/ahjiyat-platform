<?php

require_once __DIR__.'/../Social/SocialTestHelpers.php';

use App\Models\CompetitiveEvent;
use App\Models\FriendChallenge;
use App\Models\PuzzleAttempt;
use App\Models\Puzzle;
use App\Models\User;
use App\Services\Competitive\CompetitiveEventService;
use App\Services\Competitive\CompetitiveOutcome;
use App\Services\Competitive\FriendChallengeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** مساعدات E17 (تُضمَّن بـrequire_once). الأحجية الافتراضية: نصية بلا تلميح، إجابتها E17_ANSWER. */
if (! defined('E17_ANSWER')) {
    define('E17_ANSWER', 'الجواب الصحيح');
}

if (! function_exists('e17Puzzle')) {
    function e17Puzzle(array $attrs = []): Puzzle
    {
        return Puzzle::factory()->create($attrs);
    }

    /** يثبّت الزمن عند لحظة معلومة (بالميلي ثانية) لتكون المدد والنقاط حتمية. */
    function e17Freeze(string $at = '2026-10-20 12:00:00.000'): Carbon
    {
        Carbon::setTestNow(Carbon::parse($at));

        return now();
    }

    function e17Forward(int $ms): void
    {
        Carbon::setTestNow(now()->copy()->addMilliseconds($ms));
    }

    /** @return array{0: User, 1: User} صديقان مقبولان */
    function e17Friends(): array
    {
        [$a, $b] = [e16User(['name' => 'Alice '.Str::random(4)]), e16User(['name' => 'Bob '.Str::random(4)])];
        e16Befriend($a, $b);

        return [$a, $b];
    }

    function e17Challenges(): FriendChallengeService
    {
        return app(FriendChallengeService::class);
    }

    function e17Events(): CompetitiveEventService
    {
        return app(CompetitiveEventService::class);
    }

    function e17Challenge(User $a, User $b, ?Puzzle $puzzle = null, bool $accept = true): FriendChallenge
    {
        $challenge = e17Challenges()->create($a, $b, $puzzle ?? e17Puzzle());

        if ($accept) {
            e17Challenges()->accept($b, $challenge);
        }

        return $challenge->refresh();
    }

    /** يلعب طرف تحدٍّ: ابدأ ثم انتظر $afterMs ثم أرسل. */
    function e17PlayChallenge(User $user, FriendChallenge $challenge, string $answer = E17_ANSWER, int $afterMs = 30000): CompetitiveOutcome
    {
        e17Challenges()->start($user, $challenge);
        e17Forward($afterMs);

        return e17Challenges()->submit($user, $challenge->refresh(), ['answer' => $answer]);
    }

    /** حدث تنافسي (منشور ومباشر افتراضيًا). الحالة تُضبط بـforceFill لأنها ليست fillable. */
    function e17Event(array $attrs = [], string $status = CompetitiveEvent::STATUS_PUBLISHED): CompetitiveEvent
    {
        $event = new CompetitiveEvent($attrs + [
            'title' => 'Event '.Str::random(5),
            'slug' => 'ev-'.Str::lower(Str::random(8)),
            'puzzle_id' => e17Puzzle()->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $event->forceFill(['status' => $status, 'published_at' => $status === CompetitiveEvent::STATUS_DRAFT ? null : now()])->save();

        return $event->refresh();
    }

    /** مشارك يسجّل ثم يلعب: ابدأ، انتظر $afterMs، أرسل. */
    function e17PlayEvent(User $user, CompetitiveEvent $event, string $answer = E17_ANSWER, int $afterMs = 30000): CompetitiveOutcome
    {
        e17Events()->register($user, $event);
        e17Events()->start($user, $event);
        e17Forward($afterMs);

        return e17Events()->submit($user, $event->refresh(), ['answer' => $answer]);
    }

    /** لقطة لكل ما لا يجوز أن تمسّه المنافسة: E16 (XP، عملة، مهام، سلسلة، إنجازات، مشتريات، محافظ) + كل محاولات الأحجيات ونقاط التحديات القديمة. */
    function e17Snapshot(): array
    {
        return [...e16Snapshot(), PuzzleAttempt::count(), (int) DB::table('challenge_participants')->sum('score'), (int) DB::table('challenge_participants')->sum('bonus_gems_awarded')];
    }
}
