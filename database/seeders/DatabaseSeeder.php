<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            PuzzleCategorySeeder::class,
            PuzzleSeeder::class,
            UserSeeder::class,
            PuzzleAttemptSeeder::class,
            ChallengeSeeder::class,
            ChallengeParticipantSeeder::class,
            RedemptionRequestSeeder::class,
            FraudFlagSeeder::class,
        ]);
    }
}