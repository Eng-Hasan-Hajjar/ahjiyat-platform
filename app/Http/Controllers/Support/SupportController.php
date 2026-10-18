<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SponsorshipProgram;
use App\Services\Payments\SupportCatalog;
use App\Services\Sponsorship\SponsorshipTargets;

/**
 * E32 - مدخل الدعم العام (قراءة فقط): البرامج النشطة الآن فقط. برنامج مسودّة/مغلق/ملغى/منتهٍ ⇒ 404 (لا بدء طلب). لا بيانات دفع هنا؛ زر «ادعم» يقود لصفحة الدفع (تتطلب تسجيل الدخول).
 * أحجيات المجتمع والسلاسل ليست أهدافًا للرعاية أصلًا (SponsorshipTargets)، فلا يوجد دعم لها.
 */
class SupportController extends Controller
{
    public function __construct(protected SupportCatalog $catalog, protected SponsorshipTargets $targets) {}

    public function index()
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $programs = $this->catalog->programs();

        return view('support.index', [
            'programs' => $programs,
            'cards' => $this->catalog->cards($programs),
            'targets' => $this->targets,
            'enabled' => (bool) config('payments.enabled', true),
        ]);
    }

    public function show(SponsorshipProgram $program)
    {
        abort_unless((bool) config('sponsorship.enabled', true) && $program->isActiveNow(), 404);

        $programs = collect([$program]);

        return view('support.program', [
            'program' => $program,
            'card' => $this->catalog->cards($programs)[(int) $program->id],
            'targetLabel' => $this->targets->label($program),
            'targetUrl' => $this->targets->url($program),
            'enabled' => (bool) config('payments.enabled', true),
        ]);
    }
}
