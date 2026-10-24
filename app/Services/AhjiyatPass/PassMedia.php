<?php

namespace App\Services\AhjiyatPass;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E40 - بانر المسار (صورة علنية): نوع المحتوى الفعلي (finfo) من قائمة بيضاء JPEG/PNG/WEBP فقط (لا SVG)، تطابق getimagesize، حدّ الحجم والبكسلات والعرض الأدنى،
 * اسم ملف عشوائي من الخادم (لا اسم العميل). الحذف لا يطال إلا ملفات داخل مجلد البانرات.
 */
class PassMedia
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('ahjiyat_pass.banner.disk', 'public'));
    }

    public function directory(): string
    {
        return trim((string) config('ahjiyat_pass.banner.directory', 'ahjiyat-pass'), '/');
    }

    /** @throws PassException */
    public function store(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new PassException('upload_failed', 'تعذّر رفع الصورة، حاول مرة أخرى.');
        }

        $allowed = (array) config('ahjiyat_pass.banner.mimes', []);
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if ($path === '' || $size <= 0) {
            throw new PassException('upload_empty', 'الملف فارغ أو غير مقروء.');
        }

        if ($size > ((int) config('ahjiyat_pass.banner.max_kb', 2048)) * 1024) {
            throw new PassException('upload_size', 'حجم الصورة أكبر من المسموح.');
        }

        $mime = $this->detectMime($path);

        if (! isset($allowed[$mime])) {
            throw new PassException('upload_type', 'صيغة الصورة غير مدعومة. المسموح: JPEG أو PNG أو WEBP.');
        }

        $info = @getimagesize($path);

        if ($info === false || ($info['mime'] ?? '') !== $mime
            || ((int) $info[0] * (int) $info[1]) > (int) config('ahjiyat_pass.banner.max_pixels', 16_000_000)
            || (int) $info[0] < (int) config('ahjiyat_pass.banner.min_width', 600)) {
            throw new PassException('upload_dimensions', 'الصورة غير صالحة أو أبعادها خارج المسموح.');
        }

        $name = Str::lower((string) Str::ulid()).'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directory(), $file, $name, ['visibility' => 'public']);

        if ($stored === false) {
            throw new PassException('upload_failed', 'تعذّر حفظ الصورة، حاول لاحقًا.');
        }

        return (string) $stored;
    }

    public function ownedPath(?string $path): bool
    {
        return ! blank($path) && ! str_contains($path, '..') && ! str_contains($path, "\0") && str_starts_with($path, $this->directory().'/');
    }

    public function delete(?string $path): void
    {
        if ($this->ownedPath($path) && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    protected function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mime) ? $mime : '';
    }
}
