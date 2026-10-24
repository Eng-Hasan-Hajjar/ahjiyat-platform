<?php

namespace App\Services\Commerce;

use App\Models\CommerceProviderEvent;
use App\Models\DigitalPurchase;
use Illuminate\Support\Facades\DB;

/**
 * E41 - مطابقة التجارة (**قراءة فقط افتراضيًا**). لا تكتب في السجل المالي أبدًا: لا حالة شراء، ولا حدث، ولا ledger، ولا مخزون/مميز، ولا نداء استرداد.
 * الاستثناء الوحيد الاختياري `--fix-derived`: يملأ أعمدة المرآة المشتقّة provider_status/provider_paid_at لشراء مستضاف **محقَّق** بحدث `paid` مطبَّق في ledger المزوّد (المصدر الثابت) حين تكون المرآة ناقصة. لا يمسّ status ولا الأحداث.
 * استعلام المزوّد (`--query`) اختياري ومحدود بالدفعة، ولا يُستدعى إلا لمزوّد يدعم الاستعلام ولشراء له جلسة؛ لا تُخترع حالة أبدًا، وفشل المزوّد يُبلَّغ كملاحظة فقط.
 * استعلامات تجميعية مقيَّدة بسقف الصفوف (limit) وسقف النتائج (reconcile.max_findings) مع علم truncated؛ بلا N+1. لا أسرار في أي مخرج.
 *
 * @phpstan-type Finding array{code: string, severity: string, subject: string, message: string}
 */
class CommerceReconciler
{
    public function __construct(protected CommercePaymentProviderRegistry $registry) {}

    /**
     * @param  array{provider?: ?string, purchase?: ?string, limit?: ?int, fix_derived?: bool, query?: bool}  $options
     * @return array{findings: list<array<string, string>>, counts: array<string, int>, fixed: int, truncated: bool, queried: int}
     */
    public function run(array $options = []): array
    {
        $provider = isset($options['provider']) && $options['provider'] !== '' ? (string) $options['provider'] : null;
        $purchaseRef = isset($options['purchase']) && $options['purchase'] !== '' ? (string) $options['purchase'] : null;
        $limit = max(1, min((int) ($options['limit'] ?? config('commerce.reconcile.default_limit', 500)), (int) config('commerce.reconcile.max_limit', 5000)));
        $max = max(1, (int) config('commerce.reconcile.max_findings', 200));
        $fixDerived = (bool) ($options['fix_derived'] ?? false);
        $queryProvider = (bool) ($options['query'] ?? false);

        $findings = [];
        $counts = [];
        $truncated = false;
        $fixed = 0;
        $queried = 0;

        $add = function (string $code, string $severity, string $subject, string $message) use (&$findings, &$counts, &$truncated, $max): void {
            $counts[$code] = ($counts[$code] ?? 0) + 1;

            if (count($findings) >= $max) {
                $truncated = true;

                return;
            }

            $findings[] = ['code' => $code, 'severity' => $severity, 'subject' => $subject, 'message' => $message];
        };

        $scoped = function () use ($provider, $purchaseRef) {
            return DB::table('digital_purchases as d')
                ->when($provider !== null, fn ($q) => $q->where('d.provider', $provider))
                ->when($purchaseRef !== null, fn ($q) => $q->where(fn ($w) => $w->where('d.public_id', $purchaseRef)->orWhere('d.reference_code', $purchaseRef)));
        };

        $now = now();

        // 1) شراء محقَّق (مسار مميز) لم يفتح المميز.
        $scoped()->where('d.status', DigitalPurchase::STATUS_VERIFIED)->where('d.product_type', DigitalPurchase::PRODUCT_PASS_PREMIUM)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('user_pass_progress as p')->whereColumn('p.premium_purchase_id', 'd.id')->whereNotNull('p.premium_unlocked_at'))
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('verified_purchase_not_unlocked', 'critical', 'purchase#'.$r->id, "شراء محقَّق {$r->reference_code} لم يفتح المميز"));

        // 2) مميز مفتوح بلا شراء محقَّق (لا يُحصر بمزوّد/شراء لأن الصف المخالف بلا شراء مرتبط).
        if ($provider === null && $purchaseRef === null) {
            DB::table('user_pass_progress as p')
                ->whereNotNull('p.premium_unlocked_at')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('digital_purchases as d')->whereColumn('d.id', 'p.premium_purchase_id')->where('d.status', DigitalPurchase::STATUS_VERIFIED))
                ->orderByDesc('p.id')->limit($limit)->get(['p.id'])
                ->each(fn ($r) => $add('premium_without_verified_purchase', 'critical', 'progress#'.$r->id, 'مميز مفتوح بلا شراء محقَّق مرتبط'));
        }

        // 3) المزوّد قال «مدفوع» والمحلي ما زال ينتظر (علم مراجعة قائم ⇒ معروف ويُعالَج؛ بلا علم ⇒ فجوة معالجة).
        $scoped()->where('d.status', DigitalPurchase::STATUS_AWAITING)->where('d.provider_status', CommerceProviderState::Paid->value)
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code', 'd.review_flag', 'd.review_resolved_at', 'd.review_resolution'])
            ->each(function ($r) use ($add): void {
                $known = $r->review_flag !== null && $r->review_resolved_at === null;
                // قرار «تجاهل» موثَّق (ملاحظة + تدقيق) من مراجع مخوَّل = معالجة معروفة خارج المنصة: يبقى ظاهرًا في التقرير كمعلومة ولا يُنذر إلى الأبد (وإلا يتعوّد التشغيل على إنذار دائم).
                $dismissed = $r->review_flag !== null && $r->review_resolved_at !== null && $r->review_resolution === 'dismissed';

                if ($dismissed) {
                    $add('provider_paid_dismissed', 'info', 'purchase#'.$r->id, "دفعة {$r->reference_code} عند المزوّد أُغلقت مراجعتها بقرار تجاهل موثَّق");

                    return;
                }

                $add($known ? 'provider_paid_under_review' : 'provider_paid_local_awaiting', $known ? 'info' : 'critical', 'purchase#'.$r->id, $known ? "دفعة {$r->reference_code} بانتظار قرار مراجعة مالية" : "المزوّد أبلغ «مدفوع» للطلب {$r->reference_code} والمحلي ما زال ينتظر");
            });

        // 4) محقَّق محليًا لكن المزوّد لا يؤكد الدفع.
        $scoped()->where('d.status', DigitalPurchase::STATUS_VERIFIED)->where('d.provider', '<>', 'manual')
            ->where(fn ($w) => $w->whereNull('d.provider_status')->orWhereNotIn('d.provider_status', [CommerceProviderState::Paid->value, CommerceProviderState::Refunded->value]))
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code', 'd.provider_status'])
            ->each(fn ($r) => $add('verified_provider_not_paid', 'critical', 'purchase#'.$r->id, "الطلب {$r->reference_code} محقَّق محليًا وحالة المزوّد ".($r->provider_status ?? 'غير معروفة')));

        // 5) مرجع مزوّد مكرر (مفروض بقيد فريد؛ فحص دفاعي).
        foreach (['provider_session_id' => 'session', 'provider_payment_reference' => 'payment', 'provider_refund_reference' => 'refund'] as $column => $label) {
            $qualified = 'd.'.$column; // اسم عمود ثابت من القائمة أعلاه (لا إدخال خارجي)
            $scoped()->whereNotNull($qualified)->groupBy('d.provider', $qualified)->havingRaw('COUNT(*) > 1')
                ->limit($limit)->get(['d.provider', DB::raw('COUNT(*) AS n')])
                ->each(fn ($r) => $add('duplicate_provider_'.$label.'_reference', 'critical', 'provider:'.$r->provider, "مرجع {$label} مكرر عبر {$r->n} طلبات"));
        }

        // 6) حدث مزوّد مكرر في السجل (مفروض بقيد فريد؛ فحص دفاعي) — لا معنى له عند حصر شراء واحد.
        if ($purchaseRef === null) {
            CommerceProviderEvent::query()
                ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
                ->selectRaw('provider, provider_event_id, COUNT(*) AS n')->groupBy('provider', 'provider_event_id')->havingRaw('COUNT(*) > 1')
                ->limit($limit)->get()
                ->each(fn ($r) => $add('duplicate_provider_event', 'critical', 'provider:'.$r->provider, "حدث مزوّد مكرر {$r->n} مرة"));
        }

        // 7) مبلغ/عملة الدفعة المطبَّقة لا يطابقان الطلب المحقَّق (يجب ألا يحدث: التطبيق الآلي يستلزم تطابقًا تامًا).
        $scoped()->join('commerce_provider_events as e', 'e.digital_purchase_id', '=', 'd.id')
            ->where('d.status', DigitalPurchase::STATUS_VERIFIED)->where('e.normalized_state', CommerceProviderState::Paid->value)->where('e.processing_result', CommerceProviderEvent::RESULT_APPLIED)
            ->where(fn ($w) => $w->whereColumn('e.amount_minor', '<>', 'd.amount_minor')->orWhereColumn('e.currency', '<>', 'd.currency'))
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('applied_amount_or_currency_mismatch', 'critical', 'purchase#'.$r->id, "الطلب {$r->reference_code} محقَّق بحدث لا يطابق مبلغه/عملته"));

        // 7b) عدم تطابق مبلغ/عملة ينتظر قرارًا.
        $scoped()->whereIn('d.review_flag', ['amount_mismatch', 'currency_mismatch'])->whereNull('d.review_resolved_at')
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code', 'd.review_flag'])
            ->each(fn ($r) => $add($r->review_flag.'_open', 'warning', 'purchase#'.$r->id, "الطلب {$r->reference_code}: ".(DigitalPurchase::REVIEW_FLAGS[$r->review_flag] ?? $r->review_flag).' ينتظر قرارًا'));

        // 8) المزوّد استرد والمحلي ما زال محقَّقًا.
        $scoped()->where('d.status', DigitalPurchase::STATUS_VERIFIED)->where('d.provider_status', CommerceProviderState::Refunded->value)
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('refunded_provider_local_verified', 'critical', 'purchase#'.$r->id, "المزوّد أبلغ استرداد الطلب {$r->reference_code} والمحلي ما زال محقَّقًا"));

        // 9) المحلي مسترد والمزوّد يقول مدفوع (استرداد محلي بلا تأكيد مزوّد).
        $scoped()->where('d.status', DigitalPurchase::STATUS_REFUNDED)->where('d.provider', '<>', 'manual')->where('d.provider_status', CommerceProviderState::Paid->value)
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('refunded_local_provider_paid', 'critical', 'purchase#'.$r->id, "الطلب {$r->reference_code} مسترد محليًا والمزوّد ما زال يقول «مدفوع»"));

        // 10) استرداد مطلوب لم يكتمل.
        $scoped()->where('d.status', DigitalPurchase::STATUS_VERIFIED)->whereNotNull('d.refund_requested_at')->where('d.refund_requested_at', '<', $now->copy()->subHour())
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code', 'd.refund_error'])
            ->each(fn ($r) => $add('refund_stalled', 'warning', 'purchase#'.$r->id, "استرداد الطلب {$r->reference_code} لم يكتمل".($r->refund_error ? ' ('.$r->refund_error.')' : '')));

        // 11) جلسة دفع مفتوحة قديمة / طلب مستضاف تأخر إنهاؤه.
        $stale = $now->copy()->subMinutes(max(1, (int) config('commerce.reconcile.stale_checkout_minutes', 120)));
        $scoped()->where('d.status', DigitalPurchase::STATUS_AWAITING)->where('d.provider', '<>', 'manual')->whereNull('d.review_flag')
            ->whereNotNull('d.checkout_expires_at')->where('d.checkout_expires_at', '<', $stale)
            ->where(fn ($w) => $w->whereNull('d.expires_at')->orWhere('d.expires_at', '>=', $now))
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('stale_open_checkout', 'info', 'purchase#'.$r->id, "جلسة دفع الطلب {$r->reference_code} منتهية منذ مدة والطلب ما زال مفتوحًا"));

        $scoped()->where('d.status', DigitalPurchase::STATUS_AWAITING)->whereNull('d.review_flag')->whereNotNull('d.expires_at')->where('d.expires_at', '<', $now->copy()->subHour())
            ->orderByDesc('d.id')->limit($limit)->get(['d.id', 'd.reference_code'])
            ->each(fn ($r) => $add('expiry_overdue', 'warning', 'purchase#'.$r->id, "الطلب {$r->reference_code} تجاوز مهلته ولم يُنهَ بعد"));

        // 12) استعلام المزوّد (اختياري، محدود): مقارنة فقط، لا كتابة.
        if ($queryProvider) {
            $queried = $this->queryProviders($scoped, $add);
        }

        // 13) إصلاح المرآة المشتقّة (اختياري).
        if ($fixDerived) {
            $fixed = $this->fixDerived($scoped, $limit);
        }

        return ['findings' => $findings, 'counts' => $counts, 'fixed' => $fixed, 'truncated' => $truncated, 'queried' => $queried];
    }

    /** @param  \Closure(): \Illuminate\Database\Query\Builder  $scoped */
    protected function queryProviders(\Closure $scoped, \Closure $add): int
    {
        $batch = max(1, (int) config('commerce.reconcile.sync_batch', 50));
        $rows = $scoped()->where('d.provider', '<>', 'manual')->whereNotNull('d.provider_session_id')
            ->whereIn('d.status', [DigitalPurchase::STATUS_AWAITING, DigitalPurchase::STATUS_VERIFIED, DigitalPurchase::STATUS_REFUNDED])
            ->orderByDesc('d.id')->limit($batch)->pluck('d.id');

        $count = 0;

        foreach (DigitalPurchase::query()->whereIn('id', $rows)->orderByDesc('id')->get() as $purchase) {
            if (! $this->registry->has($purchase->provider)) {
                continue;
            }

            $provider = $this->registry->get($purchase->provider);

            if (! $provider->supportsStatusQuery()) {
                continue;
            }

            $count++;

            try {
                $status = $provider->fetchStatus($purchase);
            } catch (\Throwable) {
                $add('provider_query_failed', 'info', 'purchase#'.$purchase->id, "تعذّر استعلام المزوّد للطلب {$purchase->reference_code}");

                continue;
            }

            if ($status === null) {
                continue;
            }

            if ($status->state->value !== (string) $purchase->provider_status && $status->state !== CommerceProviderState::Unknown) {
                $add('provider_query_differs', 'warning', 'purchase#'.$purchase->id, "حالة المزوّد {$status->state->value} ≠ المحلية ".($purchase->provider_status ?? 'غير معروفة')." للطلب {$purchase->reference_code}");
            }

            if ($status->amountMinor !== null && $status->amountMinor !== (int) $purchase->amount_minor) {
                $add('provider_query_amount_differs', 'critical', 'purchase#'.$purchase->id, "مبلغ المزوّد لا يطابق الطلب {$purchase->reference_code}");
            }

            if ($status->currency !== null && strtoupper($status->currency) !== strtoupper((string) $purchase->currency)) {
                $add('provider_query_currency_differs', 'critical', 'purchase#'.$purchase->id, "عملة المزوّد لا تطابق الطلب {$purchase->reference_code}");
            }
        }

        return $count;
    }

    /** يملأ المرآة المشتقّة فقط من حدث paid مطبَّق وثابت. لا يمسّ status ولا الأحداث ولا المميز. @param  \Closure(): \Illuminate\Database\Query\Builder  $scoped */
    protected function fixDerived(\Closure $scoped, int $limit): int
    {
        $ids = $scoped()->where('d.status', DigitalPurchase::STATUS_VERIFIED)->where('d.provider', '<>', 'manual')
            ->where(fn ($w) => $w->whereNull('d.provider_status')->orWhere('d.provider_status', CommerceProviderState::Pending->value))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('commerce_provider_events as e')->whereColumn('e.digital_purchase_id', 'd.id')
                ->where('e.normalized_state', CommerceProviderState::Paid->value)->where('e.processing_result', CommerceProviderEvent::RESULT_APPLIED))
            ->orderBy('d.id')->limit($limit)->pluck('d.id');

        $fixed = 0;

        foreach ($ids as $id) {
            $event = CommerceProviderEvent::query()->where('digital_purchase_id', $id)->where('normalized_state', CommerceProviderState::Paid->value)
                ->where('processing_result', CommerceProviderEvent::RESULT_APPLIED)->orderBy('id')->first();

            if ($event === null) {
                continue;
            }

            DigitalPurchase::query()->whereKey($id)->first()?->forceFill([
                'provider_status' => CommerceProviderState::Paid->value,
                'provider_paid_at' => $event->occurred_at ?? $event->received_at,
            ])->save();

            $fixed++;
        }

        return $fixed;
    }
}
