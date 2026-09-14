<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Services\CampaignNarrativeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Thin عمداً - يتحقق فقط من علاقة campaign↔step (IDOR) ثم يفوّض بالكامل
 * لـCampaignNarrativeService. لا Business Logic هون (لا فحص Kind، لا فحص
 * Unlock - كلاهما مسؤولية الخدمة). C3 تحتوي فقط complete() لأن markStarted()
 * قرار مقصود إبقاؤه Service-only حالياً (لا صفحة Step بعد لتستهلكه فعلياً).
 */
class CampaignStepController extends Controller
{
    public function __construct(protected CampaignNarrativeService $narrative) {}

    public function complete(Campaign $campaign, CampaignStep $step): RedirectResponse
    {
        // IDOR: لا نثق بمعرّف step وحده - يجب أن تنتمي فعلياً لنفس campaign
        // بالـURL عبر step→gate→stage→campaign_id، وليس عبر أي حقل يرسله العميل.
        abort_unless($step->gate->stage->campaign_id === $campaign->id, 404);

        try {
            $this->narrative->complete(Auth::user(), $step);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('success', 'تم إكمال هذه الخطوة.');
    }
}