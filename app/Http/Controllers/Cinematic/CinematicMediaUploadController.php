<?php

namespace App\Http\Controllers\Cinematic;

use App\Http\Controllers\Controller;
use App\Models\Cinematic\CinematicMediaAsset;
use App\Services\Cinematic\CinematicException;
use App\Services\Cinematic\Media\CinematicMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CIN-1 - رفع وسائط المكتبة (إداري فقط: صلاحية cinematic-stories.media). لا يمر عبر Livewire/Filament FileUpload ليتحكم الخادم بالحدود كلها
 * (config/cinematic_stories.php) بدل سقف الرفع المؤقت العام. كل فحص (نوع حقيقي/حجم/خصائص فيديو) في CinematicMediaService قبل أي تخزين.
 * حدود PHP نفسها (upload_max_filesize/post_max_size) يضبطها مسؤول الاستضافة؛ الخطأ الناتج عنها يُعرض برسالة واضحة.
 */
class CinematicMediaUploadController extends Controller
{
    public function __construct(protected CinematicMediaService $media) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('cinematic-stories.media'), 403);

        $data = $request->validate(['role' => ['required', 'string', 'in:'.implode(',', CinematicMediaAsset::ROLES)]]);
        $file = $request->file('file');

        if ($file === null || ! $file->isValid()) {
            return response()->json(['message' => 'تعذّر استلام الملف؛ قد يتجاوز حد الرفع المضبوط في الخادم (upload_max_filesize / post_max_size).', 'reason' => 'upload_failed'], 422);
        }

        try {
            $result = $this->media->ingest((string) $file->getRealPath(), $data['role'], (string) $file->getClientOriginalName(), $request->user());
        } catch (CinematicException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->httpStatus);
        }

        $asset = $result['asset'];

        return response()->json([
            'id' => $asset->public_id,
            'role' => $asset->role,
            'name' => $asset->original_name,
            'size' => $asset->size_bytes,
            'duration_ms' => $asset->duration_ms,
            'created' => $result['created'],
            'warnings' => $result['warnings'],
        ], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store');
    }
}
