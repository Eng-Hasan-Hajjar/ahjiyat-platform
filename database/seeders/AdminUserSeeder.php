<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@ahjiyat.app'],
            [
                'name' => 'مدير المنصة',
                'password' => Hash::make('change-me-now'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        Wallet::firstOrCreate(['user_id' => $admin->id]);

        $this->command->warn('تم إنشاء حساب المدير: admin@ahjiyat.app / change-me-now - غيّر كلمة المرور فوراً بعد أول دخول.');
    }
}
