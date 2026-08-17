<?php

namespace Database\Seeders;

use App\Models\GemTransaction;
use App\Models\Puzzle;
use App\Models\PuzzleAttempt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PuzzleAttemptSeeder extends Seeder
{
    /**
     * محاكاة سجل حل حقيقي (محاولات صحيحة وخاطئة + معاملات جواهر مطابقة)
     * لكل المستخدمين الموثّقين وغير المجمّدين، منتشر على الأيام منذ تسجيلهم،
     * حتى تكون لوحة الصدارة ومحفظة كل مستخدم فيها بيانات واقعية للاختبار.
     *
     * ملاحظة مهمة: نبني السجلات بـ forceFill بدل create() العادي، لأن
     * created_at/updated_at غير موجودين بـ $fillable لموديلي PuzzleAttempt
     * وGemTransaction (بالتصميم)، فلو استخدمنا create() العادي كان لارافيل
     * يتجاهل تاريخنا المخصص بصمت ويحط "الآن" لكل شي.
     */
    public function run(): void
    {
        $puzzles = Puzzle::where('is_active', true)->get();

        if ($puzzles->isEmpty()) {
            $this->command->warn('لا توجد أحجيات مفعّلة - شغّل PuzzleSeeder أولاً.');

            return;
        }

        $holdDays = (int) config('gems.pending_hold_days');

        $eligibleUsers = User::where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->where('is_frozen', false)
            ->get();

        foreach ($eligibleUsers as $user) {
            if (PuzzleAttempt::where('user_id', $user->id)->exists()) {
                continue;
            }

            $daysSinceJoined = max(1, now()->diffInDays($user->created_at));
            $activityLevel = fake()->numberBetween(30, 90);

            $puzzlesToTry = $puzzles->shuffle()->take(
                (int) ceil($puzzles->count() * $activityLevel / 100)
            );

            DB::transaction(function () use ($user, $puzzlesToTry, $daysSinceJoined, $holdDays) {
                foreach ($puzzlesToTry as $puzzle) {
                    $maxDaysAgo = max(0, $daysSinceJoined - 1);
                    $attemptDate = now()->subDays(fake()->numberBetween(0, $maxDaysAgo));

                    $willEventuallySolve = fake()->boolean(70);
                    $attemptsUsed = $willEventuallySolve
                        ? fake()->numberBetween(1, min(3, $puzzle->max_attempts))
                        : $puzzle->max_attempts;

                    for ($n = 1; $n <= $attemptsUsed; $n++) {
                        $isCorrect = $willEventuallySolve && $n === $attemptsUsed;

                        $attempt = new PuzzleAttempt();
                        $attempt->forceFill([
                            'user_id' => $user->id,
                            'puzzle_id' => $puzzle->id,
                            'attempt_number' => $n,
                            'is_correct' => $isCorrect,
                            'used_hint' => fake()->boolean(20),
                            'created_at' => $attemptDate,
                            'updated_at' => $attemptDate,
                        ]);
                        $attempt->save();

                        if ($isCorrect) {
                            $transaction = new GemTransaction();
                            $transaction->forceFill([
                                'user_id' => $user->id,
                                'amount' => $puzzle->gem_reward,
                                'type' => GemTransaction::TYPE_EARN_PENDING,
                                'reason' => "solved_puzzle:{$puzzle->id}",
                                'reference_type' => Puzzle::class,
                                'reference_id' => $puzzle->id,
                                'created_at' => $attemptDate,
                                'updated_at' => $attemptDate,
                            ]);
                            $transaction->save();

                            $user->wallet->increment('pending_balance', $puzzle->gem_reward);
                            $user->wallet->increment('lifetime_earned', $puzzle->gem_reward);
                        }
                    }
                }

                $releasable = GemTransaction::where('user_id', $user->id)
                    ->where('type', GemTransaction::TYPE_EARN_PENDING)
                    ->where('created_at', '<=', now()->subDays($holdDays))
                    ->sum('amount');

                if ($releasable > 0) {
                    GemTransaction::create([
                        'user_id' => $user->id,
                        'amount' => $releasable,
                        'type' => GemTransaction::TYPE_RELEASE_AVAILABLE,
                        'reason' => 'pending_hold_expired',
                    ]);

                    $user->wallet->decrement('pending_balance', $releasable);
                    $user->wallet->increment('available_balance', $releasable);
                }
            });
        }

        $this->command->info('تم إنشاء سجل محاولات وجواهر واقعي لـ '.$eligibleUsers->count().' مستخدم.');

        // أحجيات المنصة الـ 50 لا تكفي وحدها لتجاوز الحد الأدنى للاستبدال (10,000 جوهرة).
        // هذا الجزء يحاكي "تاريخ استخدام أطول بأشهر" لحسابات عرض محددة، كل مبلغ
        // مسجّل كمعاملة admin_adjustment صريحة - مو حل أحجية حقيقي.
        $demoBonuses = [
            'yousef@ahjiyat.test' => 15000,
            'sara@ahjiyat.test' => 12000,
            'reem@ahjiyat.test' => 20000,
            'anas@ahjiyat.test' => 13000, // فيه علم احتيال غير محلول (FraudFlagSeeder) - لاختبار الحجب رغم توفر الرصيد
        ];

        foreach ($demoBonuses as $email => $amount) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                continue;
            }

            $alreadyBonused = GemTransaction::where('user_id', $user->id)
                ->where('reason', 'seed_demo_balance')
                ->exists();

            if ($alreadyBonused) {
                continue;
            }

            GemTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => GemTransaction::TYPE_ADMIN_ADJUSTMENT,
                'reason' => 'seed_demo_balance',
            ]);

            $user->wallet->increment('available_balance', $amount);
            $user->wallet->increment('lifetime_earned', $amount);
        }

        $this->command->info('تمت إضافة رصيد عرض توضيحي لحسابات الاختبار الرئيسية.');
    }
}