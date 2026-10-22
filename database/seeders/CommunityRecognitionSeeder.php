<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

/**
 * E37 - تعريفات الاعتراف المجتمعي الأساسية (مبدعون/منسّقون/مساهمون) كإنجازات عادية بلا مكافآت (xp_reward = 0؛ والحارس يمنع أي مكافأة لهذه الأنواع).
 * يقرأ config('reputation.recognition'). اختياري، يُشغَّل يدويًا (`db:seed --class=CommunityRecognitionSeeder`) وآمن لإعادة التشغيل (updateOrCreate بالمفتاح الداخلي).
 * لا يُعدّل إنجازًا صار مستعملًا (الحارس يمنع تغيير الشرط/الهدف بعد البدء): يتخطّاه. لا حظر إنتاج: لا بيانات تجريبية هنا، تعريفات فقط.
 */
class CommunityRecognitionSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach ((array) config('reputation.recognition', []) as $key => $definition) {
            [$name, $description, $conditionType, $target] = $definition;

            $existing = Achievement::query()->where('internal_key', $key)->first();

            if ($existing !== null && $existing->isUsed()) {
                $order++;

                continue;
            }

            Achievement::query()->updateOrCreate(['internal_key' => $key], [
                'name' => $name,
                'description' => $description,
                'category' => Achievement::CATEGORY_COMMUNITY,
                'condition_type' => $conditionType,
                'target_value' => $target,
                'xp_reward' => 0,
                'is_active' => true,
                'is_hidden' => false,
                'sort_order' => 300 + $order++,
            ]);
        }
    }
}
