<?php

namespace App\Services\Creators;

use App\Models\CreatorProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E28 - رفع صور أحجيات المبدعين على قرص التخزين الحالي (config/creators.php media.disk). لا بنية تخزين جديدة.
 *  - الصيغ JPEG/PNG/WEBP فقط، بالنوع الفعلي للمحتوى (finfo + getimagesize) لا باسم الملف ولا ترويسة العميل. SVG مرفوض بالبناء (ليس بقائمة الأنواع).
 *  - اسم عشوائي يولّده المخدّم (UUID + لاحقة من النوع المكتشف) داخل مجلد المالك: اسم العميل لا يدخل بالمسار إطلاقًا (لا اجتياز مسارات).
 *  - الملكية: الحذف/الاستبدال لا يطال إلا ملفات داخل مجلد المبدع نفسه (ownedBy) ولا يقبل مسارًا من العميل أصلًا (المسار يأتي من النسخة المحفوظة).
 *  - التنظيف: أي استبدال/حذف نسخة يحذف ملفها؛ المتبقي اليتيم (فشل نادر) يعالجه الأمر creators:prune-media.
 */
class CreatorMediaService
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('creators.media.disk'));
    }

    /** رابط عرض صورة (قرص الوسائط المضبوط). */
    public function url(?string $path): ?string
    {
        return blank($path) ? null : $this->disk()->url($path);
    }

    public function directoryFor(CreatorProfile $owner): string
    {
        return trim((string) config('creators.media.directory'), '/').'/'.$owner->id;
    }

    /** @throws CreatorException */
    public function store(UploadedFile $file, CreatorProfile $owner): string
    {
        $allowed = (array) config('creators.media.mimes');
        $path = $file->getRealPath();
        $mime = $path ? (string) mime_content_type($path) : '';
        $info = $path ? @getimagesize($path) : false;

        if (! isset($allowed[$mime]) || $info === false || ! isset($allowed[$info['mime'] ?? '']) || $info['mime'] !== $mime) {
            throw new CreatorException('صيغة الصورة غير مدعومة. المسموح: JPEG أو PNG أو WEBP.');
        }

        $name = Str::uuid()->toString().'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directoryFor($owner), $file, $name);

        if ($stored === false) {
            throw new CreatorException('تعذّر حفظ الصورة، حاول لاحقًا.');
        }

        return $stored;
    }

    public function ownedBy(?string $path, CreatorProfile $owner): bool
    {
        if (blank($path) || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, $this->directoryFor($owner).'/');
    }

    /** يحذف ملف المالك فقط (تجاهل صامت لغيره/لغير الموجود). */
    public function delete(?string $path, CreatorProfile $owner): void
    {
        if ($this->ownedBy($path, $owner) && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    /** نسخة مستقلة من ملف المالك لنسخة جديدة (كل نسخة تملك ملفها، فحذف مسودة لا يمسّ المنشور). */
    public function copy(?string $path, CreatorProfile $owner): ?string
    {
        if (! $this->ownedBy($path, $owner) || ! $this->disk()->exists($path)) {
            return null;
        }

        $target = $this->directoryFor($owner).'/'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);

        return $this->disk()->copy($path, $target) ? $target : null;
    }
}
