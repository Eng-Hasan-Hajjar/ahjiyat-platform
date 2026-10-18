<?php

namespace App\Services\Payments;

use App\Models\SponsorshipPayment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E32 - إثبات التحويل (ملف **خاص**). لا يُخزَّن على قرص علني أبدًا ولا يُقدَّم برابط مباشر: التنزيل عبر متحكم مخوَّل (PaymentProofController) فقط.
 *  - JPEG/PNG/WEBP/PDF بنوع المحتوى الفعلي (finfo): الصور تتطابق مع getimagesize ويُحدّ عدد البكسلات؛ PDF يبدأ بـ%PDF- ويُقدَّم كمستند خامل (attachment + nosniff) لا يُعرض HTML.
 *    SVG/HTML/التنفيذيات مرفوضة بالبناء (ليست بالقائمة البيضاء). حدّ حجم من الإعداد. اسم الملف يولّده الخادم (ULID + لاحقة من النوع المكتشف)؛ اسم العميل لا يدخل المسار ولا يُحفظ.
 *  - بصمة SHA-256 تُحفظ لاكتشاف إعادة استخدام الإثبات نفسه بين طلبات (مؤشر احتيال للمراجع).
 *  - الحذف لا يطال إلا ملفات داخل مجلد الإثباتات (ownedPath) ولا يقبل مسارًا من العميل.
 */
class PaymentProofService
{
    public function diskName(): string
    {
        return (string) config('payments.proof.disk', 'local');
    }

    public function disk(): Filesystem
    {
        $name = $this->diskName();

        // الإثبات بيانات مالية/شخصية: يُرفض أي قرص علني (الاسم أو إعداد الرؤية) بالبناء.
        if ($name === 'public' || config("filesystems.disks.{$name}.visibility") === 'public') {
            throw new PaymentException('إعداد تخزين الإثبات غير آمن: يجب أن يكون القرص خاصًا.');
        }

        return Storage::disk($name);
    }

    public function directory(): string
    {
        return trim((string) config('payments.proof.directory', 'payment-proofs'), '/');
    }

    /**
     * يتحقق من الملف ويخزّنه خاصًا. @return array{path: string, mime: string, size: int, sha256: string}
     *
     * @throws PaymentException
     */
    public function store(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new PaymentException('تعذّر رفع الملف، حاول مرة أخرى.');
        }

        $allowed = (array) config('payments.proof.mimes');
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();

        if ($path === '' || $size <= 0) {
            throw new PaymentException('الملف فارغ أو غير مقروء.');
        }

        if ($size > ((int) config('payments.proof.max_kb', 5120)) * 1024) {
            throw new PaymentException('حجم الملف أكبر من المسموح ('.(int) config('payments.proof.max_kb', 5120).' ك.ب).');
        }

        $mime = $this->detectMime($path);

        if (! isset($allowed[$mime])) {
            throw new PaymentException('صيغة الإثبات غير مدعومة. المسموح: JPEG أو PNG أو WEBP أو PDF.');
        }

        if ($mime === 'application/pdf') {
            if ((new \SplFileObject($path))->fread(5) !== '%PDF-') {
                throw new PaymentException('ملف PDF غير صالح.');
            }
        } else {
            $info = @getimagesize($path);

            if ($info === false || ($info['mime'] ?? '') !== $mime || ((int) $info[0] * (int) $info[1]) > (int) config('payments.proof.max_pixels', 40_000_000)) {
                throw new PaymentException('الصورة غير صالحة أو كبيرة الأبعاد.');
            }
        }

        $name = Str::lower((string) Str::ulid()).'.'.$allowed[$mime];
        $stored = $this->disk()->putFileAs($this->directory().'/'.now()->format('Y/m'), $file, $name, ['visibility' => 'private']);

        if ($stored === false) {
            throw new PaymentException('تعذّر حفظ الإثبات، حاول لاحقًا.');
        }

        return ['path' => (string) $stored, 'mime' => $mime, 'size' => $size, 'sha256' => (string) hash_file('sha256', $path)];
    }

    public function exists(SponsorshipPayment $payment): bool
    {
        return $payment->hasProof() && $this->ownedPath($payment->proof_path) && $this->disk()->exists((string) $payment->proof_path);
    }

    /** تنزيل خامل: attachment دائمًا (لا عرض مضمَّن) + nosniff + بلا تخزين مؤقت عام. */
    public function download(SponsorshipPayment $payment): StreamedResponse
    {
        abort_unless($this->exists($payment), 404);

        $extension = (string) ((array) config('payments.proof.mimes'))[(string) $payment->proof_mime] ?: 'bin';

        return $this->disk()->download((string) $payment->proof_path, 'proof-'.$payment->reference.'.'.$extension, [
            'Content-Type' => (string) $payment->proof_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
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
