<?php

namespace App\Http\Controllers\Creators;

use App\Http\Requests\Creators\CreatorApplyRequest;
use App\Services\Creators\CreatorApplicationService;
use App\Services\Creators\CreatorEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** E28 - طلب الانضمام للمبدعين: نبذة واحدة فقط (لا بيانات حساسة). بوابة auth+verified+account.active بالمسار؛ الأهلية والتكرار بالخدمة. */
class CreatorApplicationController extends CreatorController
{
    public function __construct(protected CreatorApplicationService $applications, protected CreatorEligibility $eligibility) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return view('creator.apply', [
            'profile' => $this->eligibility->profileFor($user),
            'denial' => $this->eligibility->applyDenial($user),
        ]);
    }

    public function store(CreatorApplyRequest $request): RedirectResponse
    {
        return $this->perform(
            fn () => $this->applications->apply($request->user(), (string) $request->validated('bio')),
            'تم إرسال طلبك، وسنراجعه قريبًا.',
            fn () => redirect()->route('creator.apply'),
        );
    }
}
