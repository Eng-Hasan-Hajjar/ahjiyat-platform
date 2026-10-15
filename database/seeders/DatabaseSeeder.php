<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use RefusesProduction;

    public function run(): void
    {
        // E25: الحزمة الكاملة تشمل حسابات تجريبية بكلمة مرور معروفة؛ ممنوعة بالإنتاج.
        $this->refuseInProduction('php artisan db:seed (الحزمة الكاملة)');

        $this->call([
            AdminUserSeeder::class,
            RolesAndPermissionsSeeder::class,
            PuzzleCategorySeeder::class,
            PuzzleSeeder::class,
            UserSeeder::class,
            PuzzleAttemptSeeder::class,
            ChallengeSeeder::class,
            ChallengeParticipantSeeder::class,
            RedemptionRequestSeeder::class,
            FraudFlagSeeder::class,

               
            ProgressionSeeder::class,
        ]);
    }
}