@extends('layouts.app')

@section('title', 'طلب الدعم '.$payment->reference)
@section('content_width', 'max-w-2xl')

{{--
    E32: صفحة طلب الدعم لصاحبه فقط. لا نعرض «تم الدفع/نجح» قبل التحقق: الحالة المرسَلة = «طلب الدعم بانتظار التحقق».
    تعليمات التحويل (من إعدادات المنصة) تظهر للطلب المفتوح فقط. لا تُعرض ملاحظات المراجع ولا رموز الرفض ولا مسار الإثبات. كل النصوص مهرّبة.
--}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="'طلب دعم '.$payment->reference" icon="gift" :back="route('support.history')" backLabel="طلبات دعمي" class="anim-fade-up !mb-0" />

        <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="summary-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="summary-title" class="font-display font-black text-white">ملخّص الطلب</h2>
                @include('support._status')
            </div>

            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-slate-400">رمز المرجع</dt><dd class="font-mono font-black text-white select-all" dir="ltr">{{ $payment->reference }}</dd></div>
                <div><dt class="text-slate-400">المبلغ</dt><dd class="font-black text-white">{{ $payment->amountLabel() }}</dd></div>
                <div class="col-span-2"><dt class="text-slate-400">البرنامج</dt><dd class="font-bold text-white break-words">{{ $payment->program?->title }}</dd></div>
                <div><dt class="text-slate-400">الظهور</dt><dd class="font-bold text-white">{{ $payment->is_anonymous ? 'مجهول' : 'باسمي' }}</dd></div>
                <div><dt class="text-slate-400">أُنشئ</dt><dd class="font-bold text-white">{{ $payment->created_at?->format('Y-m-d H:i') }}</dd></div>
            </dl>

            @if (filled($payment->user_message))
                <div class="rounded-xl border border-gold/30 bg-gold/10 text-gold px-4 py-3 text-sm font-bold">
                    <span class="block text-xs text-slate-300 mb-1">رسالة من الفريق</span>{{ $payment->user_message }}
                </div>
            @endif

            @switch($payment->status)
                @case('awaiting_payment')
                    <p class="text-sm text-slate-300">أتمم التحويل خارج المنصة ثم اضغط «حوّلت المبلغ». @if ($payment->expires_at)ينتهي الطلب تلقائيًا في <strong class="text-white">{{ $payment->expires_at->format('Y-m-d H:i') }}</strong> إن لم يُرسَل.@endif</p>
                    @break
                @case('submitted')
                    <div class="rounded-xl border border-amethyst/30 bg-amethyst/10 px-4 py-3 text-sm font-bold text-white" role="status">طلب الدعم بانتظار التحقق. سنُعلمك فور مراجعته؛ لا يُحتسب الدعم قبل ذلك.</div>
                    @break
                @case('verified')
                    <div class="rounded-xl border border-emerald/30 bg-emerald/10 px-4 py-3 text-sm font-bold text-emerald" role="status">تم التحقق وتأكيد دعمك. شكرًا لك! يظهر مستواك ضمن «الداعمون» (إلا إن اخترت الدعم المجهول).</div>
                    @break
                @case('rejected')
                    <p class="text-sm text-slate-300">لم يُقبل هذا الطلب. يمكنك إنشاء طلب جديد متى شئت.</p>
                    @break
                @case('refunded')
                    <p class="text-sm text-slate-300">سُجّل استرداد هذا الدعم. لا تُسحب مكافآت اللاعبين التي مُنحت سابقًا.</p>
                    @break
            @endswitch
        </section>

        @if ($instructions)
            <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="how-title">
                <h2 id="how-title" class="font-display font-black text-white">{{ $instructions['title'] }}</h2>

                @if (! $instructions['configured'])
                    <p class="text-sm text-slate-300">لم تُضبط بيانات التحويل بعد. تواصل مع الإدارة قبل التحويل.</p>
                @else
                    <ol class="list-decimal ps-5 space-y-1 text-sm text-slate-300">
                        <li>حوّل مبلغ <strong class="text-white">{{ $instructions['amount'] }}</strong>.</li>
                        <li>اكتب رمز المرجع <strong class="font-mono text-white" dir="ltr">{{ $instructions['reference'] }}</strong> في ملاحظة التحويل.</li>
                        <li>أرفق إثبات التحويل (صورة أو PDF) ثم اضغط «حوّلت المبلغ».</li>
                        <li>انتظر تحقق الفريق. بعد الموافقة يتحدّث مستواك ضمن الداعمين.</li>
                    </ol>
                    <dl class="glass rounded-xl p-4 text-sm space-y-2">
                        @if ($instructions['beneficiary'])<div class="flex justify-between gap-3"><dt class="text-slate-400">المستفيد</dt><dd class="font-bold text-white text-end break-words">{{ $instructions['beneficiary'] }}</dd></div>@endif
                        @if ($instructions['bank_name'])<div class="flex justify-between gap-3"><dt class="text-slate-400">البنك</dt><dd class="font-bold text-white text-end break-words">{{ $instructions['bank_name'] }}</dd></div>@endif
                        @if ($instructions['account'])<div><dt class="text-slate-400">الحساب / الآيبان</dt><dd class="font-mono font-bold text-white break-all whitespace-pre-line select-all" dir="ltr">{{ $instructions['account'] }}</dd></div>@endif
                    </dl>
                    @if ($instructions['reference_note'])<p class="text-xs text-slate-400 whitespace-pre-line">{{ $instructions['reference_note'] }}</p>@endif
                    @if ($instructions['extra_note'])<p class="text-xs text-slate-400 whitespace-pre-line">{{ $instructions['extra_note'] }}</p>@endif
                @endif
            </section>
        @endif

        @if ($payment->isOpen())
            <section class="puzzle-card !p-6 space-y-4 anim-fade-up" aria-labelledby="send-title">
                <h2 id="send-title" class="font-display font-black text-white">{{ $payment->status === 'submitted' ? 'إرسال إثبات إضافي' : 'إرسال التحويل' }}</h2>

                @if ($proofAvailable)
                    <p class="text-sm text-slate-300">إثبات مرفوع: <a class="font-bold text-amethyst hover:underline" href="{{ route('support.payments.proof', ['payment' => $payment->public_id]) }}">تنزيل ({{ strtoupper(pathinfo((string) $payment->proof_path, PATHINFO_EXTENSION)) }})</a></p>
                @endif

                @if ($payment->status === 'awaiting_payment')
                    <form method="POST" action="{{ route('support.payments.transferred', ['payment' => $payment->public_id]) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <label for="payer_note" class="block text-sm font-bold text-slate-300 mb-2">ملاحظة (اختياري): اسم المحوِّل / تاريخ التحويل</label>
                            <input id="payer_note" type="text" name="payer_note" maxlength="{{ (int) config('payments.checkout.note_max', 255) }}" value="{{ old('payer_note', $payment->payer_note) }}" class="input-gem">
                        </div>
                        <div>
                            <label for="proof" class="block text-sm font-bold text-slate-300 mb-2">إثبات التحويل (JPEG / PNG / WEBP / PDF، حتى {{ number_format((int) config('payments.proof.max_kb', 5120) / 1024, 0) }} م.ب)</label>
                            <input id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" class="input-gem">
                        </div>
                        <button type="submit" class="btn-gem w-full justify-center">حوّلت المبلغ</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('support.payments.proof.upload', ['payment' => $payment->public_id]) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <label for="proof" class="block text-sm font-bold text-slate-300 mb-2">استبدال الإثبات</label>
                            <input id="proof" type="file" name="proof" required accept="image/jpeg,image/png,image/webp,application/pdf" class="input-gem">
                        </div>
                        <button type="submit" class="btn-secondary">رفع الإثبات</button>
                    </form>
                @endif

                @if ($payment->status === 'awaiting_payment')
                    <form method="POST" action="{{ route('support.payments.cancel', ['payment' => $payment->public_id]) }}" onsubmit="return confirm('إلغاء طلب الدعم؟')">
                        @csrf
                        <button type="submit" class="btn-danger">إلغاء الطلب</button>
                    </form>
                @endif
            </section>
        @elseif ($proofAvailable)
            <p class="text-xs text-slate-500">الإثبات محفوظ بشكل خاص ولا يراه إلا المراجع المالي وأنت.</p>
        @endif
    </div>
@endsection
