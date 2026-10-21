<?php

namespace App\Http\Controllers;

use App\Services\LiveOps\Calendar\EventCalendarService;
use Illuminate\Http\Request;

/**
 * E34 - تقويم الأحداث العام: الآن / اليوم / هذا الأسبوع / قريبًا. قراءة فقط. للضيف لقطة عامة مشتركة بلا أي استعلام مستخدم؛ للمسجَّل
 * قسم "أجندتي" المحدود. لا مبالغ رعاية ولا بيانات خاصة في أي عنصر.
 */
class EventCalendarController extends Controller
{
    public function __construct(protected EventCalendarService $calendar) {}

    public function __invoke(Request $request)
    {
        $user = $request->user();

        return view('events.index', [
            'sections' => $this->calendar->sections(),
            'agenda' => $user ? $this->calendar->agendaFor($user) : collect(),
            'timezone' => $this->calendar->timezone(),
        ]);
    }
}
