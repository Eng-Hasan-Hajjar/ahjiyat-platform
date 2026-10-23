@extends('layouts.app')

@section('title', 'أرباحي')

@section('content')
    @php
        $fmt = fn (int $minor) => \App\Services\CreatorFinance\CreatorMoney::format($minor, $currency);
    @endphp
    {{-- E39: كل القيم مهرَّبة ({{ }}). لا ملاحظات داخلية ولا هوية مسؤول؛ رسالة الرفض العامة فقط. لا وجهة تحويل تُجمع هنا. --}}
    <div class="space-y-8">
        <x-page-header title="أرباحي" icon="chart" subtitle="حصصك من فترات الإيراد المُقفلة وطلبات الدفع." :back="route('creator.dashboard')" backLabel="الاستوديو" class="anim-fade-up !mb-0" />

        @if (session('success'))
            <div class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-3 text-sm font-semibold" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm font-semibold" role="alert">{{ session('error') }}</div>
        @endif

        @if ($account === null)
            <x-empty-state icon="chart" title="التسييل غير مُفعَّل لحسابك بعد" message="يفعّل فريق المنصة التسييل للمبدعين المعتمدين. تواصل مع الدعم لمعرفة موعد انضمامك." />
        @else
            @if ($account->isOnHold())
                <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm font-semibold text-slate-200" data-earnings-hold>طلبات الدفع متوقفة مؤقتًا على حسابك. تواصل مع الدعم لمعرفة التفاصيل. استحقاقاتك تُسجَّل كالمعتاد.</div>
            @endif
            @unless ($account->isEnabled())
                <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm font-semibold text-slate-200" data-earnings-paused>التسييل موقوف مؤقتًا لحسابك؛ لا استحقاقات جديدة ولا طلبات دفع حتى استئنافه.</div>
            @endunless

            <section aria-label="أرصدتك" class="grid grid-cols-1 sm:grid-cols-3 gap-3 anim-fade-up">
                <x-stat-card label="رصيد متاح" :value="$fmt((int) $account->available_minor)" icon="wallet" tone="gold" />
                <x-stat-card label="محجوز لدفعة" :value="$fmt((int) $account->reserved_minor)" icon="lock" />
                <x-stat-card label="إجمالي ما صُرف" :value="$fmt((int) $account->paid_lifetime_minor)" icon="check-circle" />
            </section>

            <section aria-label="طلب دفعة" class="rounded-2xl border border-white/10 bg-white/5 p-5 anim-fade-up">
                <h2 class="font-display font-black text-white mb-1">طلب دفعة</h2>
                <p class="text-xs text-slate-400 mb-4">الدفع يدوي خارج المنصة. أقل مبلغ {{ $fmt($min_payout_minor) }}. لا نطلب منك هنا رقم حساب أو بيانات بنكية؛ سيتواصل معك الفريق عبر قناة الدعم عند الاعتماد.</p>
                @if ($blocked_reason)
                    <p class="text-sm text-slate-300" data-payout-blocked>{{ $blocked_reason }}</p>
                @else
                    <form method="POST" action="{{ route('creator.earnings.requests.store') }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div>
                            <label for="amount" class="block text-xs font-bold text-slate-300 mb-1">المبلغ</label>
                            <input id="amount" name="amount" type="text" inputmode="decimal" dir="ltr" autocomplete="off" required maxlength="20" value="{{ old('amount') }}" class="input w-40" placeholder="0.00">
                        </div>
                        <div>
                            <label for="method" class="block text-xs font-bold text-slate-300 mb-1">وسيلة الدفع</label>
                            <select id="method" name="method" class="input" required>
                                @foreach ($methods as $code => $label)
                                    <option value="{{ $code }}" @selected(old('method') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn-gem !py-2 !px-5 text-sm">إرسال الطلب</button>
                    </form>
                @endif
            </section>

            <section aria-label="طلبات الدفع" class="anim-fade-up">
                <x-section-header title="طلبات الدفع" />
                @forelse ($payouts as $payout)
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 mb-3" data-payout-row>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-black text-white">{{ $fmt((int) $payout->amount_minor) }}</span>
                            <span class="text-xs font-bold text-slate-300">{{ $payout->statusLabel() }}</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">{{ $methods[$payout->method_code] ?? $payout->method_code }} · {{ $payout->requested_at?->format('Y-m-d H:i') }}</p>
                        @if ($payout->status === 'paid' && $payout->external_reference)
                            <p class="text-xs text-slate-300 mt-1">مرجع الصرف: <span dir="ltr">{{ $payout->external_reference }}</span></p>
                        @endif
                        @if ($payout->status === 'rejected' && $payout->decision_message)
                            <p class="text-sm text-slate-200 mt-2 whitespace-pre-line">{{ $payout->decision_message }}</p>
                        @endif
                        @if ($payout->status === 'requested')
                            <form method="POST" action="{{ route('creator.earnings.requests.cancel', $payout->public_id) }}" class="mt-3">
                                @csrf
                                <button type="submit" class="btn-secondary !py-1.5 !px-4 text-xs">إلغاء الطلب</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-slate-400">لا طلبات دفع بعد.</p>
                @endforelse
            </section>

            <section aria-label="كشوف الفترات" class="anim-fade-up">
                <x-section-header title="كشوف الفترات" />
                @forelse ($statements as $row)
                    <a href="{{ route('creator.earnings.statement', $row->period->public_id) }}" class="block rounded-2xl border border-white/10 bg-white/5 p-4 mb-3 hover:border-amethyst/40 transition" data-statement-row>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-black text-white">{{ $row->period->label }}</span>
                            <span class="font-black text-gold">{{ $fmt((int) $row->allocated_minor) }}</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">{{ $row->period->starts_at?->format('Y-m-d') }} → {{ $row->period->ends_at?->format('Y-m-d') }} · {{ number_format($row->qualified_units) }} وحدة · {{ number_format($row->unique_players) }} لاعب</p>
                    </a>
                @empty
                    <p class="text-sm text-slate-400">لا كشوف بعد. تظهر هنا الفترات التي أُقفلت وتخصك.</p>
                @endforelse
                <div class="flex justify-center mt-6">{{ $statements->links() }}</div>
            </section>
        @endif
    </div>
@endsection
