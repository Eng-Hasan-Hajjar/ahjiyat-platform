<?php

namespace App\Services\Sponsorship;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E30 - شعارات الداعمين على قرص التخزين الحالي (config/sponsorship.php logo.disk). لا بنية تخزين جديدة ولا حزم.
 *  - JPEG/PNG/WEBP فقط بالنوع الفعلي للمحتوى (finfo + getimagesize متطابقان)؛ SVG مرفوض بالبناء (ليس بالقائمة). حدّ حجم من الإعداد.
 *  - الاسم يولّده المخدّم (UUID + لاحقة من النوع المكتشف) داخل مجلد الشعارات؛ اسم العميل لا يدخل المسار إطلاقًا.
 *  - الحذف لا يطال إلا ملفات داخل مجلد الشعارات (ownedPath) ولا يقبل مسارًا من العميل.
 */
class SupporterLogoService
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('sponsorship.logo.disk'));
    }

    public function url(?string $path): ?string
    {
        return blank($path) ? null : $this->disk()->url($path);
    }

    public function directory(): string
    {
        return trim((string) config('sponsorship.logo.directory'), '/');
    }

    /** @throws SponsorshipException */
    public function store(UploadedFile $file): string
    {
        $allowed = (array) config('sponsorship.logo.mimes');
        $path = $file->getRealPath();
        $mime = $path ? (string) mime_content_type($path) : '';
        $info = $path ? @getimagesize($path) : false;

        if (! isset($allowed[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw new SponsorshipException('صيغة الشعار غير مدعومة. المسموح: JPEG أو PNG أو WEBP.');
        }

        if ($file->getSize() > ((int) config('sponsorship.logo.max_kb')) * 1024) {
            throw new SponsorshipException('حجم الشعار أكبر من المسموح.');
        }

        $name = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directory(), $file, $name);

        if ($stored === false) {
            throw new SponsorshipException('تعذّر حفظ الشعار، حاول لاحقًا.');
        }

        return $stored;
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
}
