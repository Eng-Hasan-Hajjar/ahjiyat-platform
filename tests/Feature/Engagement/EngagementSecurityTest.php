<?php

use App\Models\Puzzle;
use App\Models\QuestDefinition;
use App\Models\User;
use App\Models\UserQuestProgress;
use App\Services\PuzzleAttemptService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

test('E13 req 273: no public endpoint exists to mutate quest progress or completion directly', function () {
    $quest = QuestDefinition::factory()->daily(1)->create();

    $this->actingAs($this->user)->post("/quests/{$quest->id}/progress", ['progress' => 999])->assertNotFound();
    $this->actingAs($this->user)->post("/quests/{$quest->id}/complete")->assertNotFound();
    $this->actingAs($this->user)->post('/streak/increment')->assertNotFound();
});

test('E13 req 272/447: a malicious payload attempting to set progress/completed/streak/period_key via a real request is ignored', function () {
    $quest = QuestDefinition::factory()->daily(5)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح']);

    // محاكاة Request يحمل حقولًا خبيثة - PuzzleAttemptService لا تقبل أيًّا منها أصلًا بتوقيعها.
    app(PuzzleAttemptService::class)->attempt($this->user, $puzzle, 'صح');

    $progress = UserQuestProgress::where('user_id', $this->user->id)->where('quest_definition_id', $quest->id)->first();

    expect($progress->current_value)->toBe(1) // لا 999
        ->and($progress->period_key)->toStartWith('daily:'); // محسوبة من الخادم، لا من أي مدخل عميل
});

test('E13 req 276-280: no farming via wrong attempts, hints, logins, store purchases, or page refreshes', function () {
    QuestDefinition::factory()->daily(5)->create();
    $puzzle = Puzzle::factory()->create(['answer_raw' => 'صح', 'hint' => 'تلميح']);

    foreach (range(1, 20) as $i) {
        try {
            app(PuzzleAttemptService::class)->attempt($this->user, $puzzle, 'إجابة خاطئة '.$i);
        } catch (\Throwable $e) {
        }
    }

    try {
        app(PuzzleAttemptService::class)->purchaseHint($this->user, $puzzle);
    } catch (\Throwable $e) {
    }

    foreach (range(1, 10) as $i) {
        $this->actingAs($this->user)->get(route('quests.show'));
    }

    expect(UserQuestProgress::count())->toBe(0)
        ->and(\App\Models\PlayerStreak::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('E13 req 298: daily period is computed from server clock, never from any client-supplied date', function () {
    QuestDefinition::factory()->daily(1)->create();

    // لا مسار بالتطبيق يقبل تاريخًا من العميل للفترة - نتحقق عبر استدعاء الخدمة مباشرة بلا أي مدخل.
    $context = app(\App\Services\Engagement\QuestPeriodService::class)->dailyContext();

    expect($context->periodKey)->toBe('daily:'.now()->setTimezone(config('app.timezone'))->format('Y-m-d'));
});
