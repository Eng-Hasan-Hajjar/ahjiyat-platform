<?php

namespace App\Http\Controllers\Creators;

use App\Http\Controllers\Controller;
use App\Models\CreatorProfile;
use App\Services\Creators\CreatorException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E28 - أساس متحكمات المبدعين: ملف المبدع من الميدلوير، وتحويل أخطاء المجال المتوقَّعة لرسالة عربية بدل 500. */
abstract class CreatorController extends Controller
{
    protected function profile(Request $request): CreatorProfile
    {
        return $request->attributes->get('creator_profile');
    }

    /**
     * ينفّذ العملية؛ خطأ مجال متوقَّع (CreatorException) يرجع للصفحة برسالته، وإلا يمرّ النجاح عبر $redirect (يتلقى نتيجة العملية).
     */
    protected function perform(Closure $operation, string $success, ?Closure $redirect = null): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (CreatorException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return ($redirect ? $redirect($result) : back())->with('success', $success);
    }
}
