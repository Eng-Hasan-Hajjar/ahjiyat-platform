<?php

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../CinematicStories/_support.php';

/*
| مساعدات اختبارات «القوائم القابلة للبحث». ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| الوسائط هنا تُزرع بإدخال مباشر في الجدول (نفس أعمدة الإدخال الرسمي) لأن المطلوب اختبار البحث ضمن مئات السجلات، لا فحص ملفات حقيقية.
| لا شبكة ولا متصفح؛ فحص المتصفح الفعلي يجري خارج Pest (انظر دليل التسليم).
*/

if (! function_exists('ss_asset')) {
    /** يزرع ملف مكتبة جاهزًا (أو بحالة أخرى) ويُرجع النموذج. $o: status, at, w, h, duration, size, sha, mime */
    function ss_asset(string $role, string $name, array $o = []): CinematicMediaAsset
    {
        static $n = 0;
        $n++;

        $id = DB::table('cinematic_media_assets')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'role' => $role,
            'disk' => 'local',
            'path' => 'cinematic/'.$role.'/secret-path-'.$n,
            'original_name' => $name,
            'mime' => $o['mime'] ?? ['video' => 'video/mp4', 'poster' => 'image/png', 'subtitle' => 'text/vtt', 'audio' => 'audio/mpeg'][$role],
            'size_bytes' => $o['size'] ?? 2_500_000,
            'sha256' => $o['sha'] ?? hash('sha256', $role.'|'.$name.'|'.$n),
            'duration_ms' => $o['duration'] ?? null,
            'width' => $o['w'] ?? null,
            'height' => $o['h'] ?? null,
            'status' => $o['status'] ?? 'ready',
            'retired_at' => ($o['status'] ?? 'ready') === 'retired' ? now() : null,
            'created_at' => $o['at'] ?? now(),
            'updated_at' => now(),
        ]);

        return CinematicMediaAsset::query()->findOrFail($id);
    }

    /** يزرع $count ملفات حشو (أحدث من أي ملف زُرع قبلها) بأسماء لا تحوي الكلمات المبحوث عنها في الاختبارات. */
    function ss_filler(string $role, int $count, string $prefix = 'filler_'): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'public_id' => (string) Str::ulid(), 'role' => $role, 'disk' => 'local', 'path' => 'cinematic/'.$role.'/f'.$i.Str::random(6),
                'original_name' => $prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT).($role === 'video' ? '.mp4' : '.png'),
                'mime' => $role === 'video' ? 'video/mp4' : 'image/png', 'size_bytes' => 1000 + $i, 'sha256' => hash('sha256', $role.$prefix.$i.Str::random(8)),
                'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('cinematic_media_assets')->insert($chunk);
        }
    }

    /** يستدعي دالة بحث على مكوّن Livewire ويُرجع ما أرجعته. */
    function ss_call($component, string $method, string $term = '', int $offset = 0, string $current = '', array $context = []): array
    {
        $out = null;
        $component->call($method, $term, $offset, $current, $context)->assertReturned(function ($d) use (&$out) {
            $out = $d;

            return true;
        });

        return (array) $out;
    }

    function ss_user(array $permissions): User
    {
        $user = e16User();
        $user->givePermissionTo(array_merge(['admin.access'], $permissions));

        return $user;
    }
}
