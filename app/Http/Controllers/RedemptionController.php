<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRedemptionRequest;
use App\Services\RedemptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RedemptionController extends Controller
{
    public function __construct(protected RedemptionService $redemptions) {}

    public function index()
    {
        $user = Auth::user();
        $requests = $user->redemptionRequests()->latest()->paginate(15);
        $eligibility = $this->redemptions->checkEligibility($user);

        return view('redemption.index', compact('requests', 'eligibility'));
    }

    public function create()
    {
        $eligibility = $this->redemptions->checkEligibility(Auth::user());

        return view('redemption.create', compact('eligibility'));
    }

    public function store(StoreRedemptionRequest $request): RedirectResponse
    {
        try {
            $this->redemptions->requestRedemption(
                Auth::user(),
                (int) $request->integer('gems_amount'),
                $request->string('reward_description')
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('redemption.index')
            ->with('success', 'تم إرسال طلب الاستبدال وهو الآن قيد المراجعة.');
    }
}
