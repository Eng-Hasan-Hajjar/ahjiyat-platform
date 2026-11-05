<?php

namespace App\Services\Cinematic\Media;

use App\Models\Cinematic\CinematicMediaAsset;
use App\Models\User;
use App\Services\Cinematic\CinematicException;
use finfo;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CIN-1 - إدخال الوسائط: فحص النوع الحقيقي (محتوى لا امتداد) والحجم وخصائص الفيديو، ثم تخزين خاص باسم عشوائي (ULID) بامتداد من النوع المتحقَّق.
 * لا يُدخَل أي ملف إلى المخزن قبل اجتياز كل الفحوص. الحدود كلها من config('cinematic_stories.media') قابلة للضبط.
 * لا تحويل ولا ترميز هنا: مسار مدعوم لملفات MP4 (H.264/AAC) جاهزة الترميز مسبقًا، بلا FFmpeg.
 */
class CinematicMediaService
{
    public function __construct(protected Mp4Inspector $inspector) {}

    /**
     * @param  string  $path  مسار الملف المؤقت المرفوع (يُقرأ فقط)
     * @return array{asset: CinematicMediaAsset, created: bool, warnings: list<string>}
     */
    public function ingest(string $path, string $role, string $originalName, ?User $by = null): array
    {
        if (! in_array($role, CinematicMediaAsset::ROLES, true)) {
            throw CinematicException::make('role_invalid', 'نوع الوسيط غير معروف.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw CinematicException::make('file_missing', 'الملف المرفوع غير متاح للقراءة.');
        }

        $size = (int) filesize($path);
        $limit = (int) config("cinematic_stories.media.{$role}.max_bytes");

        if ($size <= 0) {
            throw CinematicException::make('file_empty', 'الملف فارغ.');
        }

        if ($size > $limit) {
            throw CinematicException::make('file_too_large', 'حجم الملف يتجاوز الحد المسموح لهذا النوع ('.$this->humanBytes($limit).').');
        }

        $realMime = $this->realMime($path);

        [$mime, $meta, $warnings] = match ($role) {
            CinematicMediaAsset::ROLE_VIDEO => $this->checkVideo($path, $realMime),
            CinematicMediaAsset::ROLE_POSTER => $this->checkPoster($path, $realMime),
            CinematicMediaAsset::ROLE_SUBTITLE => $this->checkSubtitle($path),
            CinematicMediaAsset::ROLE_AUDIO => $this->checkAudio($realMime),
        };

        $hash = hash_file('sha256', $path);

        $existing = CinematicMediaAsset::query()->where('role', $role)->where('sha256', $hash)->first();

        if ($existing) {
            if ($existing->status !== CinematicMediaAsset::STATUS_READY) {
                $existing->forceFill(['status' => CinematicMediaAsset::STATUS_READY, 'retired_at' => null])->save();
            }

            return ['asset' => $existing, 'created' => false, 'warnings' => $warnings];
        }

        $disk = (string) config('cinematic_stories.media.disk', 'local');
        $directory = trim((string) config('cinematic_stories.media.directory', 'cinematic'), '/').'/'.$role;
        $name = Str::lower((string) Str::ulid()).'.'.$this->extensionFor($role, $mime);
        $stored = Storage::disk($disk)->putFileAs($directory, new File($path), $name);

        if (! is_string($stored) || $stored === '') {
            throw CinematicException::make('store_failed', 'تعذّر حفظ الملف في مخزن الوسائط.', 500);
        }

        $asset = new CinematicMediaAsset;
        $asset->forceFill([
            'role' => $role,
            'disk' => $disk,
            'path' => $stored,
            'original_name' => $this->safeName($originalName),
            'mime' => $mime,
            'size_bytes' => $size,
            'sha256' => $hash,
            'duration_ms' => $meta['duration_ms'] ?? null,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'video_codec' => $meta['video_codec'] ?? null,
            'audio_codec' => $meta['audio_codec'] ?? null,
            'status' => CinematicMediaAsset::STATUS_READY,
            'meta' => $warnings === [] ? null : ['warnings' => $warnings],
            'uploaded_by' => $by?->id,
        ])->save();

        return ['asset' => $asset, 'created' => true, 'warnings' => $warnings];
    }

    /** سحب ملف من الاستعمال (لا حذف فيزيائي: المشاهد المرتبطة تنتقل إلى «وسيط غير متاح» بأمان). */
    public function retire(CinematicMediaAsset $asset): CinematicMediaAsset
    {
        if ($asset->status !== CinematicMediaAsset::STATUS_RETIRED) {
            $asset->forceFill(['status' => CinematicMediaAsset::STATUS_RETIRED, 'retired_at' => now()])->save();
        }

        return $asset;
    }

    public function restore(CinematicMediaAsset $asset): CinematicMediaAsset
    {
        $asset->forceFill(['status' => CinematicMediaAsset::STATUS_READY, 'retired_at' => null])->save();

        return $asset;
    }

    /** المسار الفعلي للملف على القرص المحلي (للبث المحروس). null إن لم يكن موجودًا فعلًا. */
    public function absolutePath(CinematicMediaAsset $asset): ?string
    {
        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->path)) {
            return null;
        }

        return $disk->path($asset->path);
    }

    private function realMime(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<string>} */
    private function checkVideo(string $path, string $realMime): array
    {
        $cfg = (array) config('cinematic_stories.media.video');

        if ($realMime !== 'video/mp4') {
            throw CinematicException::make('video_type', 'نوع الملف الحقيقي ليس MP4 (H.264/AAC). الامتداد وحده لا يُعتمد.');
        }

        $info = $this->inspector->inspect($path);
        $warnings = [];

        if (array_intersect($info['brands'], (array) $cfg['brands']) === []) {
            throw CinematicException::make('video_brand', 'علامة ملف MP4 غير مدعومة (يُقبل MP4 قياسي لا QuickTime/3GP).');
        }

        if ($info['video_codec'] === null || ! in_array($info['video_codec'], (array) $cfg['video_codecs'], true)) {
            throw CinematicException::make('video_codec', 'ترميز الفيديو غير مدعوم؛ المطلوب H.264.');
        }

        if ($info['audio_codec'] !== null && ! in_array($info['audio_codec'], (array) $cfg['audio_codecs'], true)) {
            throw CinematicException::make('audio_codec', 'ترميز الصوت غير مدعوم؛ المطلوب AAC.');
        }

        if ($info['fragmented']) {
            throw CinematicException::make('video_fragmented', 'ملف MP4 المجزَّأ (fragmented) غير مدعوم؛ صدّره كـMP4 عادي.');
        }

        if ($info['width'] <= 0 || $info['height'] <= 0 || $info['width'] > (int) $cfg['max_width'] || $info['height'] > (int) $cfg['max_height']) {
            throw CinematicException::make('video_dimensions', 'أبعاد الفيديو خارج الحد المسموح ('.$cfg['max_width'].'×'.$cfg['max_height'].').');
        }

        if ($info['duration_ms'] > (int) $cfg['max_duration_seconds'] * 1000) {
            throw CinematicException::make('video_duration', 'مدة الفيديو تتجاوز الحد المسموح.');
        }

        if (! $info['faststart']) {
            if ((bool) $cfg['require_faststart']) {
                throw CinematicException::make('video_faststart', 'الفيديو غير مهيّأ للبدء السريع (faststart)؛ أعد تصديره بخيار faststart.');
            }

            $warnings[] = 'faststart_missing';
        }

        if ($info['audio_codec'] === null) {
            $warnings[] = 'no_audio_track';
        }

        return ['video/mp4', $info, $warnings];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<string>} */
    private function checkPoster(string $path, string $realMime): array
    {
        $cfg = (array) config('cinematic_stories.media.poster');

        if (! in_array($realMime, (array) $cfg['mimes'], true)) {
            throw CinematicException::make('poster_type', 'نوع الصورة الحقيقي غير مدعوم (JPEG أو PNG أو WebP فقط).');
        }

        $dims = @getimagesize($path);

        if ($dims === false || $dims[0] < 16 || $dims[1] < 16 || $dims[0] > (int) $cfg['max_width'] || $dims[1] > (int) $cfg['max_height']) {
            throw CinematicException::make('poster_dimensions', 'أبعاد الصورة غير صالحة أو خارج الحد المسموح.');
        }

        return [$realMime, ['width' => (int) $dims[0], 'height' => (int) $dims[1]], []];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<string>} */
    private function checkSubtitle(string $path): array
    {
        $raw = (string) (new \SplFileObject($path, 'rb'))->fread(min((int) filesize($path), (int) config('cinematic_stories.media.subtitle.max_bytes')));

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw CinematicException::make('subtitle_encoding', 'ملف الترجمة يجب أن يكون نصًّا بترميز UTF-8.');
        }

        if (! preg_match('/\AWEBVTT(?:[ \t\r\n]|\z)/', $raw)) {
            throw CinematicException::make('subtitle_format', 'ملف الترجمة ليس WebVTT صالحًا (يجب أن يبدأ بـWEBVTT).');
        }

        if (preg_match('/<\s*(script|iframe|object|embed)\b/i', $raw)) {
            throw CinematicException::make('subtitle_unsafe', 'ملف الترجمة يحتوي وسومًا غير مسموحة.');
        }

        return ['text/vtt', [], []];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<string>} */
    private function checkAudio(string $realMime): array
    {
        if (! in_array($realMime, (array) config('cinematic_stories.media.audio.mimes'), true)) {
            throw CinematicException::make('audio_type', 'نوع الصوت الحقيقي غير مدعوم (MP3 أو M4A فقط).');
        }

        return [$realMime, [], []];
    }

    private function extensionFor(string $role, string $mime): string
    {
        return match (true) {
            $role === CinematicMediaAsset::ROLE_VIDEO => 'mp4',
            $role === CinematicMediaAsset::ROLE_SUBTITLE => 'vtt',
            $mime === 'image/png' => 'png',
            $mime === 'image/webp' => 'webp',
            $mime === 'image/jpeg' => 'jpg',
            $mime === 'audio/mpeg' => 'mp3',
            default => 'm4a',
        };
    }

    private function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
        $base = trim($base) === '' ? 'media' : trim($base);

        return mb_substr($base, 0, 180);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
