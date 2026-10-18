<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CheckoutRequest;
use App\Models\SponsorshipProgram;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Payments\PaymentRules;
use App\Services\Payments\SponsorshipPaymentService;
use App\Services\Sponsorship\SponsorshipMoney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * E32 - صفحة الدفع (3 خطوات) وإنشاء طلب الدعم. للمستخدم المصادَق والموثّق فقط (الضيف: متابعة P1). كل شيء يُعاد التحقق منه في الخادم:
 * البرنامج من المسار، المبلغ من النص المُرسَل بعد التحليل، العملة من القائمة البيضاء، الطريقة من السجل. حقول مثل status/accounting/user_id/tier لا تُقرأ أبدًا.
 */
class CheckoutController extends Controller
{
    public function __construct(protected SponsorshipPaymentService $payments, protected PaymentProviderRegistry $providers) {}

    public function create(SponsorshipProgram $program)
    {
        abort_unless((bool) config('sponsorship.enabled', true) && $program->isActiveNow(), 404);

        $keys = $this->providers->enabledKeys();
        $methods = [];

        foreach ($keys as $key) {
            $provider = $this->providers->provider($key);
            $methods[$key] = ['label' => $provider->label(), 'currencies' => PaymentRules::currencies($provider)];
        }

        $lists = array_map(fn (array $m) => $m['currencies'], array_values($methods));
        $currencies = $lists === [] ? [] : array_values(array_unique(array_merge(...$lists)));
        $default = in_array(SponsorshipMoney::accountingCurrency(), $currencies, true) ? SponsorshipMoney::accountingCurrency() : ($currencies[0] ?? null);

        return view('support.checkout', [
            'program' => $program,
            'methods' => $methods,
            'currencies' => $currencies,
            'currencyOptions' => SponsorshipMoney::options(),
            'defaultCurrency' => old('currency', $default),
            'suggestions' => $default === null ? [] : PaymentRules::suggestions($default),
            'allowCustom' => (bool) config('payments.checkout.allow_custom_amount', true),
            'minLabel' => $default === null ? null : SponsorshipMoney::format(PaymentRules::minMinor(), $default),
            'requestKey' => old('request_key', (string) Str::ulid()),
            'defaultName' => (string) auth()->user()?->name,
            'enabled' => (bool) config('payments.enabled', true) && $methods !== [],
        ]);
    }

    public function store(CheckoutRequest $request, SponsorshipProgram $program): RedirectResponse
    {
        abort_unless((bool) config('sponsorship.enabled', true), 404);

        $input = $request->validated();

        try {
            $payment = $this->payments->createRequest(
                $request->user(),
                $program,
                (string) $input['amount'],
                (string) $input['currency'],
                $input['visibility'] === 'anonymous',
                isset($input['display_name']) ? (string) $input['display_name'] : null,
                isset($input['provider']) && $input['provider'] !== '' ? (string) $input['provider'] : null,
                isset($input['request_key']) ? (string) $input['request_key'] : null,
            );
        } catch (PaymentException $e) {
            return back()->withInput($request->except(['request_key']))->with('error', $e->getMessage());
        }

        return redirect()->route('support.payments.show', ['payment' => $payment->public_id])
            ->with('success', 'تم إنشاء طلب الدعم. أتمم التحويل ثم أرسل الإثبات؛ لن يُحتسب الدعم قبل تحقق الفريق.');
    }
}
