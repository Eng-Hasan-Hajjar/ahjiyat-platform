<?php

use App\Models\Cinematic\CinematicMediaAsset;
use App\Services\Cinematic\Media\CinematicMediaService;
use App\Services\Cinematic\Media\Mp4Inspector;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/_support.php';

/*
| CIN-1 - إدخال الوسائط: النوع الحقيقي لا الامتداد، حدود قابلة للضبط، خصائص الفيديو (H.264/AAC)، تخزين خاص، إزالة التكرار، السحب.
*/

beforeEach(fn () => cin_seed());

function cin_ingest(string $path, string $role = 'video', string $name = 'file.mp4'): array
{
    return app(CinematicMediaService::class)->ingest($path, $role, $name, null);
}

test('MED-01: a real H.264/AAC MP4 is verified from its content and stored privately under a random name', function () {
    $result = cin_ingest(cin_tiny_mp4(), 'video', '../../etc/passwd.mp4');
    $asset = $result['asset'];

    expect($result['created'])->toBeTrue()
        ->and($asset->role)->toBe('video')->and($asset->mime)->toBe('video/mp4')->and($asset->status)->toBe('ready')
        ->and($asset->video_codec)->toBe('avc1')->and($asset->audio_codec)->toBe('mp4a')
        ->and($asset->width)->toBe(64)->and($asset->height)->toBe(64)
        ->and($asset->duration_ms)->toBeGreaterThan(900)->toBeLessThan(1200)
        ->and($asset->original_name)->toBe('passwd.mp4')                       // لا مسارات في الاسم المعروض
        ->and($asset->path)->toStartWith('cinematic/video/')->and($asset->path)->not->toContain('passwd')
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue()
        ->and(strlen($asset->sha256))->toBe(64);
});

test('MED-02: the extension is never trusted - text, random bytes and truncated files named .mp4 are refused and nothing is stored', function () {
    $files = [
        'text' => cin_tmp('هذا نص وليس فيديو', 'mp4'),
        'random' => cin_tmp(random_bytes(6000), 'mp4'),
        'truncated' => cin_tmp(substr(base64_decode(cin_b64_mp4()), 0, 1500), 'mp4'),
    ];

    foreach ($files as $label => $path) {
        expect(cin_reason(fn () => cin_ingest($path)))->not->toBeNull("{$label} was accepted");
    }

    expect(CinematicMediaAsset::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('MED-03: only standard MP4 with H.264 video and AAC audio, inside the configured dimensions, duration and size, is accepted', function () {
    $cases = [
        'video_codec' => cin_synthetic_mp4(['vcodec' => 'hvc1']),
        'audio_codec' => cin_synthetic_mp4(['audio_object' => 0x6B]),
        'video_type' => cin_synthetic_mp4(['brand' => 'qt  ', 'brands' => ['qt  ']]),          // QuickTime: النوع الحقيقي video/quicktime
        'video_dimensions' => cin_synthetic_mp4(['width' => 2560, 'height' => 1440]),
        'video_duration' => cin_synthetic_mp4(['duration' => 3600 * 1000]),
        'video_fragmented' => cin_synthetic_mp4(['mvex' => true]),
    ];

    foreach ($cases as $reason => $bytes) {
        expect(cin_reason(fn () => cin_ingest(cin_tmp($bytes, 'mp4'))))->toBe($reason);
    }

    // المُصنَّع السليم يمر (يثبت أن الرفض أعلاه بسبب الخاصية لا بسبب بنية المُصنِّع).
    expect(cin_ingest(cin_tmp(cin_synthetic_mp4(), 'mp4'))['asset']->video_codec)->toBe('avc1');

    config(['cinematic_stories.media.video.brands' => ['mp42']]);
    expect(cin_reason(fn () => cin_ingest(cin_tiny_mp4())))->toBe('video_brand');      // قائمة العلامات قابلة للضبط
    config(['cinematic_stories.media.video.brands' => ['isom', 'mp42']]);

    config(['cinematic_stories.media.video.max_bytes' => 1000]);
    expect(cin_reason(fn () => cin_ingest(cin_tiny_mp4())))->toBe('file_too_large');
    expect(cin_reason(fn () => cin_ingest(cin_tmp('', 'mp4'))))->toBe('file_empty');
});

test('MED-04: a file whose metadata is not at the front is accepted with a warning by default and refused when faststart is required', function () {
    $late = cin_tmp(cin_synthetic_mp4(['moov_first' => false]), 'mp4');

    expect(cin_ingest($late)['warnings'])->toContain('faststart_missing');

    config(['cinematic_stories.media.video.require_faststart' => true]);
    expect(cin_reason(fn () => cin_ingest(cin_tmp(cin_synthetic_mp4(['moov_first' => false, 'duration' => 2100]), 'mp4'))))->toBe('video_faststart');
});

test('MED-05: posters, WebVTT subtitles and audio are checked by content, not by name', function () {
    expect(cin_ingest(cin_tiny_png(), 'poster', 'p.png')['asset']->mime)->toBe('image/png');
    expect(cin_reason(fn () => cin_ingest(cin_tmp('not an image at all', 'png'), 'poster', 'p.png')))->toBe('poster_type');

    $vtt = "WEBVTT\n\n00:00.000 --> 00:02.000\nمرحبًا بك في المكتبة\n";
    expect(cin_ingest(cin_tmp($vtt, 'vtt'), 'subtitle', 's.vtt')['asset']->mime)->toBe('text/vtt');
    expect(cin_reason(fn () => cin_ingest(cin_tmp("1\n00:00:00,000 --> 00:00:02,000\nSRT\n", 'vtt'), 'subtitle')))->toBe('subtitle_format');
    expect(cin_reason(fn () => cin_ingest(cin_tmp("WEBVTT\n\n00:00.000 --> 00:02.000\n<script>alert(1)</script>\n", 'vtt'), 'subtitle')))->toBe('subtitle_unsafe');
    expect(cin_reason(fn () => cin_ingest(cin_tmp("WEBVTT\n\n\xFF\xFE bad", 'vtt'), 'subtitle')))->toBe('subtitle_encoding');

    $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x00".str_repeat("\x00", 400);
    expect(cin_ingest(cin_tmp($mp3, 'mp3'), 'audio', 'a.mp3')['asset']->mime)->toBe('audio/mpeg');
    expect(cin_reason(fn () => cin_ingest(cin_tmp('نص', 'mp3'), 'audio')))->toBe('audio_type');

    expect(cin_reason(fn () => cin_ingest(cin_tiny_png(), 'document')))->toBe('role_invalid');
});

test('MED-06: the same content is stored once per role; re-uploading a retired file brings it back', function () {
    $first = cin_ingest(cin_tiny_mp4());
    $second = cin_ingest(cin_tiny_mp4(), 'video', 'copy.mp4');

    expect($second['created'])->toBeFalse()->and($second['asset']->id)->toBe($first['asset']->id)->and(CinematicMediaAsset::query()->count())->toBe(1);

    app(CinematicMediaService::class)->retire($first['asset']);
    expect($first['asset']->fresh()->status)->toBe('retired');

    cin_ingest(cin_tiny_mp4());
    expect($first['asset']->fresh()->status)->toBe('ready');
});

test('MED-07: the inspector reads structure only - it never shells out and it bounds its own work on hostile input', function () {
    $inspector = new Mp4Inspector;
    $info = $inspector->inspect(cin_tiny_mp4());

    expect($info['brand'])->toBe('isom')->and($info['faststart'])->toBeTrue()->and($info['tracks'])->toBe(2);

    // صندوق بحجم أكبر من الملف، وصندوق moov عملاق الإعلان: يُرفضان دون قراءة ضخمة أو دوران.
    $lying = pack('N', 5_000_000).'ftyp'.str_repeat('x', 40);
    expect(cin_reason(fn () => $inspector->inspect(cin_tmp($lying, 'mp4'))))->toBe('mp4_truncated');
    expect(cin_reason(fn () => $inspector->inspect(cin_tmp(str_repeat("\0", 100), 'mp4'))))->not->toBeNull();

    $source = (string) file_get_contents(app_path('Services/Cinematic/Media/Mp4Inspector.php'));
    expect(preg_match('/\b(exec|shell_exec|system|passthru|proc_open|popen|ffprobe|ffmpeg)\b\s*\(/', $source))->toBe(0);
});
