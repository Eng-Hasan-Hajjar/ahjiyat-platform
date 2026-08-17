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
        $admin = User::where('email', 'admin@ahjiyat.app')->first();

        if (! $admin) {
            $admin = new User();
            // forceFill لازم هون: role وemail_verified_at مو موجودين بـ $fillable
            // (وهذا مقصود بالموديل لأسباب أمان)، فلو استخدمنا create()/firstOrCreate()
            // العاديين كانوا ينحذفوا بصمت والحساب يطلع role=user غير موثّق -
            // يعني ما يقدر يفتح لوحة إدارة Filament إطلاقاً رغم إنه "أدمن" بالاسم.
            $admin->forceFill([
                'name' => 'مدير المنصة',
                'email' => 'admin@ahjiyat.app',
                'password' => Hash::make('change-me-now'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
            $admin->save();
        }

        Wallet::firstOrCreate(['user_id' => $admin->id]);

        $this->command->warn('تم إنشاء حساب المدير: admin@ahjiyat.app / change-me-now - غيّر كلمة المرور فوراً بعد أول دخول.');
    }
}