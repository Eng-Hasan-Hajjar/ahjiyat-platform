<?php

use App\Services\Cinematic\CinematicReadinessChecker;
use App\Services\Cinematic\Demo\StageAddonInstaller;

require_once __DIR__.'/_plm34_support.php';

/*
| PLM-3/4 - جدول الوسائط المبدئي (docs/PLM-STAGES-03-04/MEDIA-TABLE-PRELIMINARY.csv) يطابق القالبين وفاحص الجاهزية الفعلي.
|  - PLM34M-01: صف لكل مشهد له خانة وسيط، بترتيب القالب، بخانات الربط الفعلية، وكل اسم ملف نهائي «بانتظار تسليم محمد» وكل حالة «غير مربوط».
|  - PLM34M-02: عمود «مطلوب للنشر» يطابق ما يمنعه فاحص الجاهزية فعلًا (فيديو بلا ملف / فحص بلا صورة) وما يحذّر منه فقط وما لا يحذّر منه.
|  - PLM34M-03: المشاهد المضافة لحاجة التسليم موسومة بذلك في الجدول وفي القالب، والصور المدمجة مربوطة بخانة الصورة الوحيدة، ولا يدّعي الجدول وجود وسيط.
*/

beforeEach(fn () => cin_seed());

/** @return list<array<string, string>> */
function plm34m_rows(): array
{
    $path = base_path('docs/PLM-STAGES-03-04/MEDIA-TABLE-PRELIMINARY.csv');
    $raw = (string) file_get_contents($path);
    expect(str_starts_with($raw, "\xEF\xBB\xBF"))->toBeTrue('CSV بترميز UTF-8 مع BOM ليفتح بالعربية في Excel');
    $raw = substr($raw, 3);
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $head = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];

    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null] || $r === []) {
            continue;
        }

        $rows[] = array_combine($head, $r);
    }

    fclose($fh);

    return $rows;
}

test('PLM34M-01: the media table has one row per scene that takes a file, in template order, with the real link slots, every final filename awaiting Mohammad and nothing marked linked', function () {
    $rows = plm34m_rows();
    $expected = [];

    foreach ([3, 4] as $stage) {
        foreach (plm34_scenes($stage) as $scene) {
            if (($scene['production']['media_slot'] ?? null) !== null) {
                $expected[] = $scene;
            }
        }
    }

    expect(count($rows))->toBe(count($expected))->and(count($rows))->toBe(23)->and(array_column($rows, 'scene_key'))->toBe(array_column($expected, 'stable_key'));

    foreach ($rows as $i => $row) {
        $scene = $expected[$i];
        expect($row['خانة الربط'])->toBe($scene['production']['media_slot'])->and($row['نوع الوسيط'])->toBe($scene['production']['media_kind'])->and($row['اسم الملف النهائي'])->toBe('بانتظار تسليم محمد')
            ->and($row['حالة الربط'])->toBe('غير مربوط')->and($row['م'])->toBe((string) ($i + 1))->and($row['المرحلة'])->toBe(str_starts_with($scene['stable_key'], 'stage3_') ? 'المرحلة الثالثة' : 'المرحلة الرابعة')
            ->and($row['المشهد'])->toBe($scene['title'])->and($row['شرط الفتح/الظهور'])->not->toBe('');
    }

    // خانتا الربط الفعليتان في المحرّر.
    expect(array_unique(array_column($rows, 'خانة الربط')))->toEqualCanonicalizing(['الفيديو', 'صورة المشهد (خلفية التفاعل)']);

    // لا ادعاء بوجود وسيط: لا مسار ولا امتداد ملف ولا رابط في الجدول، ولا مجلد وسائط في المشروع.
    $blob = (string) file_get_contents(base_path('docs/PLM-STAGES-03-04/MEDIA-TABLE-PRELIMINARY.csv')).(string) file_get_contents(base_path('docs/PLM-STAGES-03-04/MEDIA-TABLE-PRELIMINARY.md'));
    expect($blob)->not->toMatch('/\.(mp4|mov|webm|png|jpe?g|webp|vtt|mp3|wav)\b/i')->not->toContain('http')->and($blob)->toContain('بانتظار تسليم محمد')->and($blob)->not->toContain('مربوط بالملف');
});

test('PLM34M-02: the "required for publishing" column is exactly what the readiness checker blocks on, and what it only warns about is marked optional', function () {
    $ctx = plm34_install(4);
    $check = app(CinematicReadinessChecker::class)->check($ctx['revision']);
    $mine = fn (array $rows) => collect($rows)->filter(fn ($r) => str_starts_with((string) $r['scene'], 'stage3_') || str_starts_with((string) $r['scene'], 'stage4_'));

    $blockVideo = $mine($check['blockers'])->where('code', 'video_missing')->pluck('scene')->sort()->values()->all();
    $blockImage = $mine($check['blockers'])->where('code', 'scene_image_missing')->pluck('scene')->sort()->values()->all();
    $warnImage = $mine($check['warnings'])->where('code', 'scene_image_missing')->pluck('scene')->sort()->values()->all();
    $rows = collect(plm34m_rows());
    $required = fn (string $kind) => $rows->where('نوع الوسيط', $kind)->filter(fn ($r) => str_starts_with($r['مطلوب للنشر'], 'نعم'))->pluck('scene_key')->sort()->values()->all();
    $warnOnly = $rows->where('نوع الوسيط', 'صورة')->filter(fn ($r) => str_starts_with($r['مطلوب للنشر'], 'لا — تحذير'))->pluck('scene_key')->sort()->values()->all();
    $silent = $rows->where('نوع الوسيط', 'صورة')->filter(fn ($r) => str_starts_with($r['مطلوب للنشر'], 'لا — لا يمنع'))->pluck('scene_key')->sort()->values()->all();

    expect($blockVideo)->toBe($rows->where('نوع الوسيط', 'فيديو')->pluck('scene_key')->sort()->values()->all())->and($required('فيديو'))->toBe($blockVideo)
        ->and($blockImage)->toBe($required('صورة'))->and($warnImage)->toBe($warnOnly)
        ->and($blockImage)->toBe(['stage3_clock_inspect', 'stage3_corridor_inspect', 'stage3_recorder_lever', 'stage4_doors_inspect', 'stage4_photo_inspect', 'stage4_stone_inspect'])
        ->and($warnOnly)->toBe(['stage3_time_lock'])->and($silent)->toBe(['stage3_knight_puzzle', 'stage3_plates_choice', 'stage4_door_match']);

    // الوسيط لا يظهر مطلوبًا لما لا يطلبه الفاحص: مشاهد الاستنتاج والسرد وبطاقات الانتقال بلا صور ولا فيديو.
    $none = ['stage3_end', 'stage4_evidence_room', 'stage4_inference_q1', 'stage4_inference_q2', 'stage4_inference_q3', 'stage4_inference_result', 'stage4_end'];
    expect($rows->pluck('scene_key')->intersect($none)->all())->toBe([])
        ->and($mine($check['blockers'])->whereIn('scene', $none)->where('code', '!=', 'unlock_rule_invalid')->all())->toBe([]);
});

test('PLM34M-03: handoff-added scenes and combined comparison images are flagged in the table exactly as in the templates', function () {
    $rows = collect(plm34m_rows());
    $added = collect([3, 4])->flatMap(fn ($s) => plm34_scenes($s))->where('production.link_action', 'scene_added_for_handoff')->pluck('stable_key')->all();

    expect($rows->filter(fn ($r) => str_starts_with($r['إجراء الربط'], 'مشهد أضافه القالب'))->pluck('scene_key')->all())->toBe($added)->and($added)->toBe(['stage3_holder_video', 'stage3_latch_video', 'stage4_lamp_video'])
        ->and($rows->filter(fn ($r) => str_starts_with($r['إجراء الربط'], 'ربط ملف بمشهد موجود'))->count())->toBe(20);

    expect($rows->filter(fn ($r) => str_starts_with($r['صورة مقارنة مدمجة'], 'نعم'))->pluck('scene_key')->all())->toBe(['stage3_plates_choice', 'stage4_door_match', 'stage4_photo_inspect'])
        ->and($rows->where('صورة مقارنة مدمجة', 'لا')->count())->toBe(20);

    // المشهدان المعلّقان على الوصلة يعلنان ذلك في شرط الفتح، ولا يظهر هذا الشرط في غيرهما.
    expect($rows->filter(fn ($r) => str_contains($r['شرط الفتح/الظهور'], 'معلّق:'))->pluck('scene_key')->all())->toBe(['stage3_corridor_video', 'stage4_photo_inspect']);
});
