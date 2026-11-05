<?php

use App\Models\Cinematic\CinematicChapter;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\Cinematic\CinematicRevision;
use App\Models\Cinematic\CinematicScene;
use App\Models\Cinematic\CinematicStage;
use App\Models\Cinematic\CinematicStory;
use App\Models\User;
use App\Services\Cinematic\CinematicAuthoringService;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\CinematicPlayService;
use App\Services\Cinematic\CinematicPublishingService;
use App\Services\Cinematic\Media\CinematicMediaService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Social/SocialTestHelpers.php';

/*
| CIN-1 - مساعدات مشتركة لاختبارات السلاسل السينمائية. ليست ملف اختبار (لا تنتهي بـTest.php) فتُحمَّل بـrequire_once.
| كل التهيئة بالخدمات الرسمية. الوسائط: ملف MP4 حقيقي صغير (H.264/AAC) مضمَّن بـbase64، وملفات اختبار سلبية تُبنى بنيويًا في الاختبار نفسه.
| لا شبكة ولا FFmpeg ولا بوابة دفع. القرص 'local' مُستبدَل بـStorage::fake فلا يُكتب شيء خارج مجلد مؤقت.
*/

if (! function_exists('cin_seed')) {
    function cin_b64_mp4(): string
    {
        return 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAX1bW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAA+gAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwAAAnp0cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAA+gAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAEAAAABAAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAAPoAAAAAAABAAAAAAHybWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAAwAAAAMABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAABnW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAV1zdGJsAAAAuXN0c2QAAAAAAAAAAQAAAKlhdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAEAAQABIAAAASAAAAAAAAAABFUxhdmM2MC4zMS4xMDIgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAAL2F2Y0MBQsAK/+EAF2dCwArZBCbARAAAAwAEAAADADA8SJkgAQAFaMuA5LIAAAAQcGFzcAAAAAEAAAABAAAAFGJ0cnQAAAAAAAAieAAAIngAAAAYc3R0cwAAAAAAAAABAAAABgAACAAAAAAUc3RzcwAAAAAAAAABAAAAAQAAABxzdHNjAAAAAAAAAAEAAAABAAAAAQAAAAEAAAAsc3RzegAAAAAAAAAAAAAABgAAA9AAAAAaAAAAHAAAABsAAAAaAAAAFAAAAChzdGNvAAAAAAAAAAYAAAcUAAAMQAAADL4AAA1AAAAOIwAADugAAAKldHJhawAAAFx0a2hkAAAAAwAAAAAAAAAAAAAAAgAAAAAAAAPoAAAAAAAAAAAAAAABAQAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAJGVkdHMAAAAcZWxzdAAAAAAAAAABAAAD6AAABAAAAQAAAAACHW1kaWEAAAAgbWRoZAAAAAAAAAAAAAAAAAAAH0AAACNAVcQAAAAAAC1oZGxyAAAAAAAAAABzb3VuAAAAAAAAAAAAAAAAU291bmRIYW5kbGVyAAAAAchtaW5mAAAAEHNtaGQAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAYxzdGJsAAAAfnN0c2QAAAAAAAAAAQAAAG5tcDRhAAAAAAAAAAEAAAAAAAAAAAABABAAAAAAH0AAAAAAADZlc2RzAAAAAAOAgIAlAAIABICAgBdAFQAAAAAAJPwAACT8BYCAgAUViFblAAaAgIABAgAAABRidHJ0AAAAAAAAJPwAACT8AAAAIHN0dHMAAAAAAAAAAgAAAAgAAAQAAAAAAQAAA0AAAABMc3RzYwAAAAAAAAAFAAAAAQAAAAEAAAABAAAAAgAAAAIAAAABAAAAAwAAAAEAAAABAAAABQAAAAIAAAABAAAABgAAAAEAAAABAAAAOHN0c3oAAAAAAAAAAAAAAAkAAADvAAAA4QAAAHsAAABkAAAAZgAAAGYAAABiAAAAqwAAAK8AAAAsc3RjbwAAAAAAAAAHAAAGJQAACuQAAAxaAAAM2gAADVsAAA49AAAO/AAAABpzZ3BkAQAAAHJvbGwAAAACAAAAAf//AAAAHHNiZ3AAAAAAcm9sbAAAAAEAAAAJAAAAAQAAAGJ1ZHRhAAAAWm1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAG1kaXJhcHBsAAAAAAAAAAAAAAAALWlsc3QAAAAlqXRvbwAAAB1kYXRhAAAAAQAAAABMYXZmNjAuMTYuMTAwAAAACGZyZWUAAAmObWRhdN4CAExhdmM2MC4zMS4xMDIAAjilUtNG7rP2j7fElXctEiRIkSSDatp2radq2nauM7Vxnatpnp2xTtisO1bTtXGdq4zlWU5VtOVbTYsTjsTlWUhAhAhAhElGSjKoyqUyVpTXXK6u7cwN2KUCUDAwMDAxs3LLLLLLKblllllllNyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy8AAACbQYF//9p3EXpvebZSLeWLNgg2SPu73gyNjQgLSBjb3JlIDE2NCByMzEwOCAzMWUxOWY5IC0gSC4yNjQvTVBFRy00IEFWQyBjb2RlYyAtIENvcHlsZWZ0IDIwMDMtMjAyMyAtIGh0dHA6Ly93d3cudmlkZW9sYW4ub3JnL3gyNjQuaHRtbCAtIG9wdGlvbnM6IGNhYmFjPTAgcmVmPTMgZGVibG9jaz0xOjA6MCBhbmFseXNlPTB4MToweDExMSBtZT1oZXggc3VibWU9NyBwc3k9MSBwc3lfcmQ9MS4wMDowLjAwIG1peGVkX3JlZj0xIG1lX3JhbmdlPTE2IGNocm9tYV9tZT0xIHRyZWxsaXM9MSA4eDhkY3Q9MCBjcW09MCBkZWFkem9uZT0yMSwxMSBmYXN0X3Bza2lwPTEgY2hyb21hX3FwX29mZnNldD0tMiB0aHJlYWRzPTIgbG9va2FoZWFkX3RocmVhZHM9MSBzbGljZWRfdGhyZWFkcz0wIG5yPTAgZGVjaW1hdGU9MSBpbnRlcmxhY2VkPTAgYmx1cmF5X2NvbXBhdD0wIGNvbnN0cmFpbmVkX2ludHJhPTAgYmZyYW1lcz0wIHdlaWdodHA9MCBrZXlpbnQ9NiBrZXlpbnRfbWluPTEgc2NlbmVjdXQ9NDAgaW50cmFfcmVmcmVzaD0wIHJjX2xvb2thaGVhZD02IHJjPWNyZiBtYnRyZWU9MSBjcmY9NDAuMCBxY29tcD0wLjYwIHFwbWluPTAgcXBtYXg9NjkgcXBzdGVwPTQgaXBfcmF0aW89MS40MCBhcT0xOjEuMDAAgAAAAVtliIQnDGADALplBhy3r38/4EWMzHo//iEyIAARCGN9X9AJOelkwYpLOVxKH+GoppXBbmbWnA8MGCBAFYUQUH7BCY5f6rsD9jw+IBgH/DUamKW/ny3gcAJQABAHCFAKh5FLIJCtj4A7sgLvj/PhrpTxTlyKffmPwmLBLWGTASCAAIgHgzD32mPOisGZHXw2+zNDsAfheUVzKBNu2cdnlJx9/SzOm1P/Sn02CzjAO8g3D3Gz/NcIf/4JIHhqgwluY49/U9P//9mCHAdIeTLQzj/HAwDIAeq8KPmEbxd1hgIh8MAhwoWfQ+0wuLjEsCPrNFEBx5CEAQYQLXENOCk5bVzuEQAHihCA4CuKwXj3uE7+wLzWGnmF8v/wxDXED9+F/l7Lt99IDAACAYUOEAJKI8OSFLGQXphWK8BaF/R8OLjAAx+uN55fD+W5UGIoSBD8ouiabFcZx4kCS7jBLgEAnNk9FUS6GUS7F2S+fb5//i/8/16441q5H/T9P/w/8/x5483qXWf9Pz/6f/P11xxfVak/9Pz/6f/f661xxpdJl0Yo8DLoxJxy6MUeGmZsMVzTTLmXM800yWaNll+Q/XguwQOEownWc6KqjGNQaOiiOg3QOoUG6DdU9FFYUrZsi3tmzYR30i4RtEuL7J7COzZsivyJHBWXMs52I885znUpUalKnnnnnnVOqdU6p5551CdSlTzzz7J1G+jfRRS/OpetKHY58bXYN8TuH4Kc6oebmnVPPzT83yVORIQdHy2KHAES8ori5mVnPPf5/6f/brXHF6ly5darS974qooD3IP/AGfQD/GYD30A/wA6E+4Ie/vohA+AHfcB/4MHfcg/8NhPuQP8Nge5A+bMB76APjME++gwkv4eI/jHEFP4+E7+I8n2P4eJH+PhLBjAEgxAgEQiAATCoWDbEAlMBwAAABZBmjhOLO9cEJFrOjqgl1y0O/1FsUKAAQIyh+Z/p/T9c+2u/0//5f/8b1rfXd/X254+v2/n+31HhGdeKBX/u/eopv3t+8kpvU3lKaFN+/fvvv3337997BUM4S379++gIBcNQWCoWCgARCAXCYAqEgAiEABMJhQAUACADgAAABhBmlQbjVhKFZ7D+PL9SL1hLq5+CGtYi1MA/DKEIBFCEG7f+N+nf6f/6P/5dXxvH3e+Tj5/X+Prh676qVANKx6f7U9P9iXLP/Z6fSSYScDAwMDMgbBkDAwNiwVDaFoigIBMJhYKhUJBIJRCIAmALBQJABcATCYWCwZ4BEUuA4AAAAAXQZpgRxqx0f1hbrmFYrq34a1qNVBZ9/AA/jKH5n8/2/fPlnr//D//Pd8eNJ9/nvj4/b/n/p8cV3lznXdAvw2bFVtmzpbYUiNmxs6IN2zZs2R2R2bNmzZYLBnCmzZs2UBELhsCxs2eULACQSBMIhYAZwqEgkCIABMKgCQARAcA/jKH5n7/x+M/bz3+n//L//jd6cevqvGa18/4/jzL7y29KBaG/eopv3037yjfvX3lKXU379++++99++6w32uLBigKAiEwuFgsGcKABALhcMgAoFgBIEABcJhULBtiERKYDgAAABZBmoBXG+CGtejVVjvrGbWOPgi1qdTAASYyi+UQilVnf/9n/8+pq5ckkWiSIJsYrVj0oZX3LVj0oZX3Xqx6amVi/qBhVcKmV9y1aelDK+69WPTUyvuvVj01Mr7r48cKrq+6+PHpq01yXx4rq01yX9WOFWmtF/ViurTWi/qxXVprRf1Yrq09KL+ruvq09KF9XctWnpQzqxvVp6amdXdfPp6amR916semplfctWOCGTyLViEMrQtWOFTK5L1EuplYEAHAAAAAEEGaoHcalDn0v6X1d+o1QpIBQDKL5lFUz19v54u7uSSSJIkSCBXPlWybchUGVbJtyFQZWm5tzgTldhGVpnNuRDAhpky3FQIabmdchgfabmW5DAhpuZbkMD7XPM65DA+1zzLchgQ1zzLchgQ1zzLc5tyGueZbOaBCufKtnNuQ1z5Vs5tyFc+VbObchXPlaZzbnFQZWmc25xXPlaZNucVBlaZNucVA+0yZ1xUD7TJnXFQIaZMtyGB9puZ1yGBDTc3A';
    }

    function cin_b64_png(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAACXBIWXMAAAABAAAAAQBPJcTWAAAAKUlEQVR4nO3NsQkAAAzDsPz/c6FPJJvAs5VLpm3vAAAAAAAAAAAAoNgDp+T0Ltwd/VwAAAAASUVORK5CYII=';
    }

    /** يفتح العلم، يزرع الأدوار والصلاحيات، ويستبدل القرص الخاص بمجلد مؤقت. */
    function cin_seed(array $config = []): void
    {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['cinematic_stories.enabled' => true] + $config);
        Storage::fake((string) config('cinematic_stories.media.disk', 'local'));
    }

    function cin_admin(): User
    {
        $user = e16User();
        $user->assignRole('administrator');

        return $user;
    }

    function cin_user(): User
    {
        return e16User();
    }

    function cin_authoring(): CinematicAuthoringService
    {
        return app(CinematicAuthoringService::class);
    }

    function cin_publishing(): CinematicPublishingService
    {
        return app(CinematicPublishingService::class);
    }

    function cin_play(): CinematicPlayService
    {
        return app(CinematicPlayService::class);
    }

    /** مسار ملف مؤقت بمحتوى معطى (يُحذف عند نهاية العملية). */
    function cin_tmp(string $content, string $ext = 'bin'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cin-'.bin2hex(random_bytes(6)).'.'.$ext;
        file_put_contents($path, $content);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }

    function cin_tiny_mp4(): string
    {
        return cin_tmp(base64_decode(cin_b64_mp4()), 'mp4');
    }

    function cin_tiny_png(): string
    {
        return cin_tmp(base64_decode(cin_b64_png()), 'png');
    }

    /** يبني صندوق ISO-BMFF. */
    function cin_box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /**
     * MP4 بنيوي مُصنَّع لاختبار الفاحص: يتحكم الاختبار بالعلامة والمدة والأبعاد والترميزات وترتيب moov/mdat.
     *
     * @param  array{brand?: string, brands?: list<string>, duration?: int, timescale?: int, width?: int, height?: int, vcodec?: string, acodec?: ?string, audio_object?: int, moov_first?: bool, mvex?: bool}  $o
     */
    function cin_synthetic_mp4(array $o = []): string
    {
        $o += ['brand' => 'isom', 'brands' => ['isom', 'avc1'], 'duration' => 2000, 'timescale' => 1000, 'width' => 320, 'height' => 180, 'vcodec' => 'avc1', 'acodec' => 'mp4a', 'audio_object' => 0x40, 'moov_first' => true, 'mvex' => false];
        $ftyp = cin_box('ftyp', str_pad($o['brand'], 4).pack('N', 512).implode('', array_map(fn ($b) => str_pad($b, 4), $o['brands'])));
        $mvhd = cin_box('mvhd', pack('N', 0).pack('N', 0).pack('N', 0).pack('N', $o['timescale']).pack('N', $o['duration']).str_repeat("\0", 80));
        $video = cin_box('trak', cin_box('tkhd', str_repeat("\0", 76).pack('N', $o['width'] << 16).pack('N', $o['height'] << 16)).cin_box('mdia', cin_box('hdlr', pack('N', 0).pack('N', 0).'vide'.str_repeat("\0", 12)).cin_box('minf', cin_box('stbl', cin_box('stsd', pack('N', 0).pack('N', 1).cin_box($o['vcodec'], str_repeat("\0", 78)))))));
        $audio = '';

        if ($o['acodec'] !== null) {
            $esds = cin_box('esds', pack('N', 0)."\x03\x19\0\0\0".chr(0x04).chr(0x11).chr($o['audio_object']).str_repeat("\0", 16));
            $audio = cin_box('trak', cin_box('tkhd', str_repeat("\0", 84)).cin_box('mdia', cin_box('hdlr', pack('N', 0).pack('N', 0).'soun'.str_repeat("\0", 12)).cin_box('minf', cin_box('stbl', cin_box('stsd', pack('N', 0).pack('N', 1).cin_box($o['acodec'], str_repeat("\0", 28).$esds))))));
        }

        $moov = cin_box('moov', $mvhd.$video.$audio.($o['mvex'] ? cin_box('mvex', '') : ''));
        $mdat = cin_box('mdat', str_repeat('x', 64));

        return $ftyp.($o['moov_first'] ? $moov.$mdat : $mdat.$moov);
    }

    /** أصل فيديو جاهز عبر خدمة الإدخال الرسمية (الملف الحقيقي الصغير). */
    function cin_video_asset(?User $by = null): CinematicMediaAsset
    {
        return app(CinematicMediaService::class)->ingest(cin_tiny_mp4(), 'video', 'scene.mp4', $by)['asset'];
    }

    function cin_poster_asset(?User $by = null): CinematicMediaAsset
    {
        return app(CinematicMediaService::class)->ingest(cin_tiny_png(), 'poster', 'poster.png', $by)['asset'];
    }

    /**
     * قصة بمرحلة وفصل. $o: premium_series (CreatorSeries) لقصة مميزة، title.
     *
     * @return array{admin: User, story: CinematicStory, revision: CinematicRevision, stage: CinematicStage, chapter: CinematicChapter}
     */
    function cin_story(array $o = []): array
    {
        $admin = $o['admin'] ?? cin_admin();
        $series = $o['premium_series'] ?? null;
        $story = cin_authoring()->createStory([
            'title' => $o['title'] ?? 'قصة تجريبية '.fake()->unique()->numerify('####'),
            'access_mode' => $series ? 'premium' : 'free',
            'creator_series_id' => $series?->id,
            'label_kind' => $o['label_kind'] ?? 'long',
        ], $admin);
        $revision = $story->revisions()->first();
        $stage = cin_authoring()->addStage($revision, ['title' => 'المرحلة الأولى', 'stable_key' => 'stage_1']);
        $chapter = cin_authoring()->addChapter($stage, ['title' => 'الفصل الأول', 'stable_key' => 'chapter_1']);

        return ['admin' => $admin, 'story' => $story, 'revision' => $revision->fresh(), 'stage' => $stage, 'chapter' => $chapter];
    }

    /** مشهد سرد بسيط. */
    function cin_narrative(CinematicChapter $chapter, string $key, array $data = []): CinematicScene
    {
        return cin_authoring()->addScene($chapter, $data + ['type' => 'narrative', 'title' => 'سرد '.$key, 'stable_key' => $key, 'config' => ['lines' => [['speaker' => '', 'text' => 'نص '.$key]]]]);
    }

    /** مشهد فيديو بوسيط جاهز (ونص بديل). */
    function cin_video_scene(CinematicChapter $chapter, string $key, array $data = [], ?CinematicMediaAsset $asset = null): CinematicScene
    {
        $scene = cin_authoring()->addScene($chapter, $data + ['type' => 'video', 'title' => 'فيديو '.$key, 'stable_key' => $key, 'config' => ['transcript' => [['speaker' => 'ياسمين', 'text' => 'حوار '.$key]]]]);

        return cin_authoring()->replaceMedia($scene, 'video', $asset ?? cin_video_asset());
    }

    /** مشهد تفاعل بحل خادمي. @param array<string, mixed> $public @param array<string, mixed> $solution */
    function cin_interaction(CinematicChapter $chapter, string $key, array $public, array $solution, array $data = []): CinematicScene
    {
        $scene = cin_authoring()->addScene($chapter, $data + ['type' => 'interaction', 'title' => 'تفاعل '.$key, 'stable_key' => $key, 'config' => ['interaction' => $public]]);
        $errors = cin_authoring()->setInteractionSolution($scene, $solution);

        if ($errors !== []) {
            throw new RuntimeException('cin_interaction: '.implode(' | ', $errors));
        }

        return $scene->fresh();
    }

    /** تعريف اختيار عنصر شائع للاختبارات. @return array{0: array<string, mixed>, 1: array<string, mixed>} [عام، حل] */
    function cin_choose(string $correct = 'b'): array
    {
        return [
            ['kind' => 'choose_item', 'prompt' => 'اختر العنصر الصحيح', 'items' => [['id' => 'a', 'label' => 'أ'], ['id' => 'b', 'label' => 'ب'], ['id' => 'c', 'label' => 'ج']]],
            ['correct' => $correct],
        ];
    }

    /** ينشر النسخة عبر المسار الرسمي (مراجعة ثم نشر). */
    function cin_publish(CinematicRevision $revision, ?User $by = null): CinematicRevision
    {
        cin_publishing()->submitForReview($revision->fresh());

        return cin_publishing()->publish($revision->fresh(), $by);
    }

    /** رمز السبب لاستثناء مجال متوقع، أو null إن لم يُرمَ. */
    function cin_reason(callable $call): ?string
    {
        try {
            $call();
        } catch (CinematicException $e) {
            return $e->reason;
        }

        return null;
    }

    /** بصمة الجداول الاقتصادية والمدفوعات: يجب ألا تتغير بأي إجراء سينمائي (لا جواهر ولا خبرة ولا محفظة ولا محاولات أحجيات ولا مدفوعات). @return array<string, string> */
    function cin_economy_snapshot(): array
    {
        $out = [];

        foreach (['gem_transactions', 'xp_transactions', 'wallets', 'puzzle_attempts', 'community_puzzle_attempts', 'inventory_transactions', 'creator_payouts', 'creator_series_accesses', 'digital_purchases'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $out[$table] = md5((string) \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson());
            }
        }

        return $out;
    }

    /** يبني فيديو بمدة محددة (مللي ثانية) عبر خدمة الإدخال الرسمية. */
    function cin_long_video_asset(int $durationMs = 100000): CinematicMediaAsset
    {
        $path = cin_tmp(cin_synthetic_mp4(['duration' => $durationMs, 'timescale' => 1000]), 'mp4');

        return app(CinematicMediaService::class)->ingest($path, 'video', 'long.mp4', null)['asset'];
    }

    /** تقدّم لاعب جاهز على النسخة المنشورة. @return array{0: \App\Models\Cinematic\CinematicProgress, 1: User} */
    function cin_begin(CinematicStory $story, ?User $user = null): array
    {
        $user ??= cin_user();

        return [cin_play()->begin($user, $story), $user];
    }

    /** حالة مشهد في state(): 'available' | 'locked' | 'completed'. */
    function cin_status(array $state, string $key): ?string
    {
        return collect($state['scenes'])->firstWhere('key', $key)['status'] ?? null;
    }

    /** تعريفات صالحة لكل أنواع التفاعل الثمانية: [عام، حل]. @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    function cin_defs(): array
    {
        $areas = [['id' => 'door', 'label' => 'الباب', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 40], ['id' => 'drawer', 'label' => 'الدرج', 'x' => 50, 'y' => 60, 'w' => 20, 'h' => 20]];
        $items = [['id' => 'circle', 'label' => 'دائرة', 'symbol' => '●'], ['id' => 'triangle', 'label' => 'مثلث', 'symbol' => '▲'], ['id' => 'square', 'label' => 'مربع', 'symbol' => '■']];

        return [
            'hotspot' => [['kind' => 'hotspot', 'prompt' => 'اضغط على المقبض', 'areas' => $areas], ['correct' => ['door']]],
            'container' => [['kind' => 'container', 'prompt' => 'افتح الدرج', 'areas' => $areas], ['correct' => 'drawer', 'requires_scenes' => ['riddle_one']]],
            'sequence' => [['kind' => 'sequence', 'prompt' => 'رتّب الأشكال', 'items' => $items], ['order' => ['circle', 'triangle', 'square']]],
            'drag_drop' => [['kind' => 'drag_drop', 'prompt' => 'ضع كل شكل في مكانه', 'items' => $items, 'targets' => [['id' => 'left', 'label' => 'يسار'], ['id' => 'mid', 'label' => 'وسط'], ['id' => 'right', 'label' => 'يمين']]], ['map' => ['circle' => 'left', 'triangle' => 'mid', 'square' => 'right']]],
            'choose_item' => [['kind' => 'choose_item', 'prompt' => 'أي مفتاح يفتح الباب؟', 'items' => $items], ['correct' => 'square']],
            'compare' => [['kind' => 'compare', 'prompt' => 'أي مخطط يطابق المشهد؟', 'options' => $items], ['correct' => 'triangle']],
            'reveal_code' => [['kind' => 'reveal_code', 'prompt' => 'اكشف الرمز', 'reveal_label' => 'الرمز'], ['code' => '7421', 'requires_scenes' => ['riddle_one']]],
            'flashlight' => [['kind' => 'flashlight', 'prompt' => 'ابحث عن الأثر المخفي', 'light_radius' => 12], ['targets' => [['id' => 'mark', 'x' => 40, 'y' => 40, 'w' => 10, 'h' => 10], ['id' => 'note', 'x' => 70, 'y' => 20, 'w' => 10, 'h' => 10]], 'required' => 1]],
        ];
    }
}
