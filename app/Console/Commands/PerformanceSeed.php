<?php

namespace App\Console\Commands;

use App\Models\ChatThread;
use App\Models\CurrencyTransaction;
use App\Models\Puzzle;
use App\Models\PuzzleCategory;
use App\Models\User;
use App\Services\Chat\ChatThreadService;
use App\Services\Economy\CurrencyRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * E24: بيانات أداء اصطناعية لقياس الاستعلامات والفهارس (EXPLAIN) وللاختبار الحِملي المحلي.
 *
 * حدود صارمة:
 *  - لا تعمل إلا في local/testing (حارس إنتاج صريح) ولا تُشغَّل ضمن DatabaseSeeder.
 *  - **تُضيف فقط**: كل مستخدم اصطناعي بريده perfN@perf.ahjiyat.test؛ لا تعدّل ولا تحذف أي صف موجود (بيانات QA-D1 تبقى كما هي).
 *  - --purge يحذف فقط ما أنشأته هي (بحسب نطاق البريد الاصطناعي)، ولا شيء غيره.
 *  - small هو المعتمد محليًا (1000 مستخدم افتراضيًا)؛ medium/large يتطلبان --allow-large صراحةً.
 * هذه البيانات لا تُمثّل سلوك لاعبين حقيقيين؛ هي لقياس شكل الخطط (index vs scan) لا لتقدير سعة الإنتاج.
 */
class PerformanceSeed extends Command
{
    protected $signature = 'performance:seed
        {profile=small : small|medium|large (الأحجام من config/performance.php)}
        {--users= : تجاوز عدد المستخدمين الاصطناعيين}
        {--allow-large : مطلوب لـ medium/large}
        {--purge : احذف فقط البيانات التي أنشأها هذا الأمر ثم اخرج}';

    protected $description = 'E24: يزرع بيانات أداء اصطناعية (local/testing فقط) أو يحذف ما زرعه. لا يمسّ بيانات QA الحالية.';

    private const EMAIL_DOMAIN = '@perf.ahjiyat.test';

    public function handle(ChatThreadService $threads, CurrencyRegistry $currencies): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('performance:seed يعمل فقط في بيئة local أو testing. (حارس الإنتاج)');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge();
        }

        $profile = (string) $this->argument('profile');
        $profiles = (array) config('performance.seed.profiles', []);

        if (! isset($profiles[$profile])) {
            $this->error('ملف غير معروف. المتاح: '.implode(', ', array_keys($profiles)));

            return self::FAILURE;
        }

        if ($profile !== 'small' && ! $this->option('allow-large')) {
            $this->error("الملف {$profile} كبير: أضف --allow-large صراحةً (لا يُنصح به على جهاز تطوير عادي).");

            return self::FAILURE;
        }

        if (User::query()->where('email', 'like', '%'.self::EMAIL_DOMAIN)->exists()) {
            $this->error('توجد بيانات اصطناعية سابقة. شغّل --purge أولًا (لا نكرّر ولا نعدّل).');

            return self::FAILURE;
        }

        $cfg = $profiles[$profile];
        $userCount = max(1, (int) ($this->option('users') ?: $cfg['users']));
        $perUser = max(1, (int) $cfg['attempts_per_user']);
        $messages = max(0, (int) $cfg['chat_messages']);
        $started = microtime(true);

        $puzzleIds = $this->ensurePuzzles();
        $currencyId = $currencies->defaultEarnedCurrency()->id;
        $hash = Hash::make('perf-seed-not-a-real-password');
        $now = now();
        $firstId = (int) (User::query()->max('id') ?? 0) + 1;

        // ---- المستخدمون + المحافظ + التقدّم (دفعات 500)
        foreach (array_chunk(range(1, $userCount), 500) as $chunk) {
            DB::transaction(function () use ($chunk, $hash, $now, $currencyId) {
                DB::table('users')->insert(array_map(fn (int $i) => [
                    'name' => 'لاعب اختبار '.$i, 'email' => 'perf'.$i.self::EMAIL_DOMAIN, 'email_verified_at' => $now, 'password' => $hash,
                    'role' => 'user', 'public_id' => (string) Str::ulid(), 'profile_visibility' => 'members',
                    'created_at' => $now->copy()->subDays(random_int(0, 90)), 'updated_at' => $now,
                ], $chunk));
            });
        }

        $ids = User::query()->where('email', 'like', '%'.self::EMAIL_DOMAIN)->orderBy('id')->pluck('id')->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk, $now, $currencyId, $puzzleIds, $perUser) {
                DB::table('wallets')->insert(array_map(fn ($id) => [
                    'user_id' => $id, 'currency_id' => $currencyId, 'pending_balance' => random_int(0, 80), 'available_balance' => random_int(0, 500),
                    'lifetime_earned' => random_int(0, 900), 'lifetime_redeemed' => 0, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));

                DB::table('player_progressions')->insert(array_map(fn ($id) => [
                    'user_id' => $id, 'total_xp' => random_int(0, 400), 'current_level' => 1, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));

                $attempts = [];
                foreach ($chunk as $id) {
                    foreach (array_slice($this->shuffled($puzzleIds), 0, min($perUser, count($puzzleIds))) as $n => $puzzleId) {
                        $attempts[] = [
                            'user_id' => $id, 'puzzle_id' => $puzzleId, 'attempt_number' => 1, 'is_correct' => random_int(1, 100) <= 60, 'used_hint' => false,
                            'time_taken_seconds' => random_int(5, 90), 'created_at' => $now->copy()->subHours(random_int(0, 24 * 30)), 'updated_at' => $now,
                        ];
                    }
                }
                DB::table('puzzle_attempts')->insert($attempts);

                // ~30% لديهم جواهر معلّقة (مرشَّحو مهمة التحرير)
                $tx = [];
                foreach ($chunk as $id) {
                    if (random_int(1, 100) <= 30) {
                        $tx[] = [
                            'user_id' => $id, 'currency_id' => $currencyId, 'amount' => random_int(5, 40), 'type' => CurrencyTransaction::TYPE_EARN_PENDING,
                            'reason' => 'perf_seed', 'created_at' => $now->copy()->subDays(random_int(0, 12)), 'updated_at' => $now,
                        ];
                    }
                }
                $tx !== [] && DB::table('currency_transactions')->insert($tx);

                // بصمات الأجهزة: 5% يشاركون مجموعة IP صغيرة (يحاكي شبكات مشتركة)
                DB::table('device_sightings')->insert(array_map(fn ($id) => [
                    'user_id' => $id, 'ip_address' => random_int(1, 100) <= 5 ? '10.9.0.'.random_int(1, 4) : '10.'.random_int(10, 250).'.'.random_int(0, 250).'.'.random_int(1, 250),
                    'device_hash' => hash('sha256', 'perf-ua-'.($id % 7)), 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));

                // إشعارات لـ ~20%
                $notes = [];
                foreach ($chunk as $id) {
                    if (random_int(1, 100) <= 20) {
                        foreach ([1, 2] as $k) {
                            $notes[] = [
                                'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\PerfSeedNotification', 'notifiable_type' => User::class, 'notifiable_id' => $id,
                                'data' => json_encode(['title' => 'perf', 'k' => $k]), 'read_at' => $k === 1 ? null : $now, 'created_at' => $now->copy()->subMinutes($k * 10), 'updated_at' => $now,
                                'type_key' => 'perf_seed', 'category' => 'system', 'idempotency_key' => 'perf:'.$id.':'.$k,
                            ];
                        }
                    }
                }
                $notes !== [] && DB::table('notifications')->insert($notes);
            });
        }

        // ---- رسائل الغرفة العامة
        if ($messages > 0) {
            $global = $threads->global();
            $rows = [];
            $t = $now->copy()->subMinutes($messages);

            for ($m = 0; $m < $messages; $m++) {
                $rows[] = [
                    'chat_thread_id' => $global->id, 'sender_id' => $ids[array_rand($ids)], 'body' => 'رسالة اختبار أداء رقم '.$m,
                    'created_at' => $t->copy()->addMinutes($m), 'updated_at' => $t->copy()->addMinutes($m),
                ];
                if (count($rows) === 1000) {
                    DB::table('chat_messages')->insert($rows);
                    $rows = [];
                }
            }
            $rows !== [] && DB::table('chat_messages')->insert($rows);

            $last = DB::table('chat_messages')->where('chat_thread_id', $global->id)->orderByDesc('id')->first(['id', 'created_at']);
            DB::table('chat_threads')->where('id', $global->id)->update(['last_message_id' => $last->id, 'last_message_at' => $last->created_at]);
        }

        $this->info(sprintf('تم: %d مستخدم اصطناعي، %d رسالة، في %.1f ثانية. للحذف: php artisan performance:seed --purge', $userCount, $messages, microtime(true) - $started));

        return self::SUCCESS;
    }

    /** @return list<int> */
    protected function ensurePuzzles(): array
    {
        $ids = Puzzle::query()->where('is_active', true)->limit(60)->pluck('id')->all();

        if (count($ids) >= 20) {
            return $ids;
        }

        if (PuzzleCategory::query()->count() === 0) {
            PuzzleCategory::factory()->create();
        }

        Puzzle::factory()->count(30)->create(['is_active' => true]);

        return Puzzle::query()->where('is_active', true)->limit(60)->pluck('id')->all();
    }

    /** @param  list<int>  $items @return list<int> */
    protected function shuffled(array $items): array
    {
        shuffle($items);

        return $items;
    }

    protected function purge(): int
    {
        $ids = User::query()->where('email', 'like', '%'.self::EMAIL_DOMAIN)->pluck('id')->all();

        if ($ids === []) {
            $this->info('لا بيانات اصطناعية لحذفها.');

            return self::SUCCESS;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::transaction(function () use ($chunk) {
                DB::table('chat_messages')->whereIn('sender_id', $chunk)->delete();
                DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $chunk)->delete();
                foreach (['device_sightings', 'currency_transactions', 'puzzle_attempts', 'player_progressions', 'wallets'] as $table) {
                    DB::table($table)->whereIn('user_id', $chunk)->delete();
                }
                DB::table('users')->whereIn('id', $chunk)->delete();
            });
        }

        // آخر رسالة في الغرفة العامة بعد حذف رسائلنا (لا تُترك مؤشرًا إلى رسالة محذوفة).
        $global = ChatThread::query()->where('type', ChatThread::TYPE_GLOBAL)->first();

        if ($global !== null) {
            $last = DB::table('chat_messages')->where('chat_thread_id', $global->id)->orderByDesc('id')->first(['id', 'created_at']);
            DB::table('chat_threads')->where('id', $global->id)->update(['last_message_id' => $last?->id, 'last_message_at' => $last?->created_at]);
        }

        $this->info('حُذف '.count($ids).' مستخدمًا اصطناعيًا وبياناتهم.');

        return self::SUCCESS;
    }
}
