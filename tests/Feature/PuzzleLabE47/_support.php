<?php

use App\Models\PuzzleLabExercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| E47 - مساعدات مشتركة لاختبارات «مختبر الأحجيات». ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
*/

if (! function_exists('lab_exercise')) {
    /** تمرين صالح (ذاكرة أو تسلسل) بالحالة المطلوبة؛ يمرّ بحارس النموذج نفسه. */
    function lab_exercise(string $type = 'sequence', string $status = 'published', array $overrides = []): PuzzleLabExercise
    {
        $config = $type === 'memory'
            ? ['faces' => ['شمس', 'قمر', 'نجمة', 'غيمة']]
            : ['items' => ['الأول', 'الثاني', 'الثالث', 'الرابع']];

        $exercise = new PuzzleLabExercise($overrides + [
            'game_type' => $type,
            'level' => 'easy',
            'sort_order' => 1,
            'title' => 'تمرين '.($type === 'memory' ? 'الذاكرة' : 'التسلسل').' '.str()->random(4),
            'summary' => 'وصف قصير للتمرين',
            'instructions' => 'اقرأ التعليمات ثم العب.',
            'wrong_note' => 'ملاحظة تعليمية بعد الخطأ.',
            'success_explanation' => 'شرح موجز عند النجاح.',
            'game_config' => $config,
        ]);

        $exercise->forceFill(['status' => $status, 'published_at' => $status === 'published' ? now() : null])->save();

        return $exercise;
    }

    function lab_player(): User
    {
        return User::factory()->create();
    }

    function lab_admin(): User
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('administrator');

        return $user;
    }

    /** يبدأ جولة ويعيد بيانات البدء (JSON). */
    function lab_start(User $user, PuzzleLabExercise $exercise): array
    {
        return test()->actingAs($user)->postJson(route('puzzle-lab.rounds.start', $exercise))->assertOk()->json();
    }

    /** الترتيب الصحيح لرموز جولة تسلسل (يُستنتج بالنص لأن الرموز خاصة بالجولة) - الاختبار فقط يعرف الحل. */
    function lab_correct_tokens(PuzzleLabExercise $exercise, array $start): array
    {
        $byLabel = collect($start['items'])->pluck('idx', 'label');

        return array_map(fn (string $label) => $byLabel[$label], $exercise->items());
    }

    /** يقلب كل بطاقات جولة ذاكرة عبر نقطة flip ويعيد أزواج المطابقة الصحيحة. @return list<array{0:int,1:int}> */
    function lab_memory_pairs(User $user, PuzzleLabExercise $exercise, array $start): array
    {
        $byFace = [];

        foreach ($start['cards'] as $card) {
            $face = test()->actingAs($user)
                ->postJson(route('puzzle-lab.rounds.flip', $exercise), ['round' => $start['round'], 'card' => $card['id']])
                ->assertOk()->json('face');
            $byFace[$face][] = $card['id'];
        }

        return array_values($byFace);
    }

    /** عدد صفوف كل الجداول (لإثبات أن الجولات لا تكتب في قاعدة البيانات). @return array<string,int> */
    function lab_table_counts(): array
    {
        // مستقل عن المحرّك (SQLite/MySQL): أسماء كل الجداول من مخطط Laravel نفسه.
        return collect(Schema::getTables())->pluck('name')->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }
}
