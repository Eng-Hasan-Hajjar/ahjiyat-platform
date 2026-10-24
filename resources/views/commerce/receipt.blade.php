<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>إيصال شراء — {{ $receipt['reference'] }}</title>
    <style>
        :root { color-scheme: light; --ink: #0f172a; --mute: #475569; --line: #e2e8f0; --bg: #ffffff; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; line-height: 1.6; }
        main { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        .sheet { background: var(--bg); border: 1px solid var(--line); border-radius: 16px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        .sub { color: var(--mute); font-size: .85rem; margin: 0 0 16px; }
        dl { display: grid; grid-template-columns: 1fr; gap: 10px; margin: 0; }
        @media (min-width: 520px) { dl { grid-template-columns: 1fr 1fr; } }
        dt { color: var(--mute); font-size: .75rem; }
        dd { margin: 0; font-weight: 700; word-break: break-word; }
        .amount { font-size: 1.5rem; }
        .note { margin-top: 20px; padding-top: 12px; border-top: 1px dashed var(--line); color: var(--mute); font-size: .78rem; }
        .actions { margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap; }
        button, a.btn { font: inherit; cursor: pointer; border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 10px; padding: 8px 16px; text-decoration: none; font-size: .85rem; }
        button:focus-visible, a.btn:focus-visible { outline: 2px solid #6d28d9; outline-offset: 2px; }
        @media print { body { background: #fff; } .actions { display: none; } .sheet { border: 0; padding: 0; } }
    </style>
</head>
<body>
<main>
    {{-- E41: إيصال شراء بسيط قابل للطباعة. ليس فاتورة ضريبية ولا يدّعي امتثالًا ضريبيًا. لا أسرار ولا معرّفات مزوّد ولا ملاحظات داخلية ولا مسارات إثبات. --}}
    <article class="sheet" data-commerce-receipt>
        <h1>إيصال شراء</h1>
        <p class="sub">Receipt / Purchase reference — ليس فاتورة ضريبية</p>
        <dl>
            <div><dt>مرجع الشراء</dt><dd dir="ltr" data-receipt-reference>{{ $receipt['reference'] }}</dd></div>
            <div><dt>المنتج</dt><dd data-receipt-product>{{ $receipt['product'] }}</dd></div>
            <div><dt>المبلغ</dt><dd class="amount" dir="ltr" data-receipt-amount>{{ $receipt['amount'] }}</dd></div>
            <div><dt>العملة</dt><dd dir="ltr" data-receipt-currency>{{ $receipt['currency'] }}</dd></div>
            <div><dt>طريقة الدفع</dt><dd data-receipt-provider>{{ $receipt['provider'] }}</dd></div>
            <div><dt>الحالة</dt><dd data-receipt-status>{{ $receipt['status_label'] }}</dd></div>
            <div><dt>تاريخ الطلب</dt><dd>{{ $receipt['created_at']->format('Y-m-d H:i') }}</dd></div>
            @if ($receipt['paid_at'])<div><dt>تاريخ تأكيد الدفع</dt><dd>{{ $receipt['paid_at']->format('Y-m-d H:i') }}</dd></div>@endif
            @if ($receipt['refund'])<div><dt>الاسترداد</dt><dd data-receipt-refund>{{ $receipt['refund'] }}@if ($receipt['refunded_at']) — {{ $receipt['refunded_at']->format('Y-m-d H:i') }}@endif</dd></div>@endif
        </dl>
        <p class="note">هذا الإيصال إثبات شراء داخلي لمنتج رقمي. لا يُعدّ فاتورة ضريبية ولا يتضمن أي بيانات بطاقة أو حساب.</p>
        <div class="actions">
            <button type="button" onclick="window.print()">طباعة</button>
            <a class="btn" href="{{ route('commerce.purchases.index') }}">العودة إلى مشترياتي</a>
        </div>
    </article>
</main>
</body>
</html>
