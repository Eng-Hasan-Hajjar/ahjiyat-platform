<?php

namespace Database\Seeders;

use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChallengeParticipantSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->where('is_frozen', false)
            ->get();

        if ($users->isEmpty()) {
            $this->command->warn('لا يوجد مستخدمون مؤهّلون - شغّل UserSeeder أولاً.');

            return;
        }

        // التحديات "القادمة" (لم تبدأ بعد) ما إلها مشاركون منطقياً - نستثنيها.
        $challenges = Challenge::where('starts_at', '<=', now())->get();

        $showcaseEmails = ['yousef@ahjiyat.test', 'sara@ahjiyat.test', 'reem@ahjiyat.test'];

        foreach ($challenges as $challenge) {
            if ($challenge->participants()->count() > 0) {
                continue;
            }

            $participants = $users->random(min(fake()->numberBetween(6, 14), $users->count()));

            // نضمن وجود حسابات العرض التوضيحي بكل تحدٍ مفتوح حالياً، حتى تشوفها فوراً عند تسجيل الدخول.
            if ($challenge->is_active && $challenge->starts_at->isPast() && $challenge->ends_at->isFuture()) {
                $showcaseUsers = $users->whereIn('email', $showcaseEmails);
                $participants = $participants->merge($showcaseUsers)->unique('id');
            }

            foreach ($participants as $user) {
                $challenge->participants()->create([
                    'user_id' => $user->id,
                    'score' => fake()->numberBetween(0, 18),
                    'bonus_gems_awarded' => $challenge->ends_at->isPast() ? fake()->numberBetween(0, 200) : 0,
                ]);
            }
        }

        $this->command->info('تمت إضافة مشاركين بلوحات صدارة '.$challenges->count().' تحدٍ.');
    }
}