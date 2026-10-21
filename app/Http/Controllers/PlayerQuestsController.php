<?php

namespace App\Http\Controllers;

use App\Services\LiveOps\MissionCenterService;
use Illuminate\Support\Facades\Auth;

/**
 * مركز المهام (E34). المسار الأساسي /missions (missions.index) والمسار القديم /quests (quests.show) يخدمهما نفس المتحكّم والصفحة بلا تحويل
 * (توافق خلفي كامل: الروابط/الإشعارات/الاختبارات الموجودة تبقى 200). القراءة فقط عبر GET؛ المزامنة الذاتية القائمة (E13) تعيد تقييم بيانات اللعب
 * الحقيقية ولا تقبل أي قيمة من العميل، والتقدّم/المكافأة/السلسلة/الإسناد لا تأتي من الطلب أبدًا.
 */
class PlayerQuestsController extends Controller
{
    public function __construct(protected MissionCenterService $center) {}

    public function show()
    {
        return view('quests.show', $this->center->forUser(Auth::user()));
    }
}
