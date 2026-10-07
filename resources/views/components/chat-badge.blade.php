@php
    // E21: شارة غير المقروء الإجمالية. تُحسب **مرة واحدة لكل طلب** (التنقّل المكتبي والجوال يعرضانها معًا)، لا تكسر أي صفحة أبدًا (rescue)، وللموثَّق فقط.
    // الدردشة تملك عدّادها (لا إشعار لكل رسالة).
    $chatUnread = request()->attributes->get('chat_unread_total');

    if ($chatUnread === null) {
        $chatUnread = auth()->check() && auth()->user()->hasVerifiedEmail() ? rescue(fn () => app(\App\Services\Chat\ChatUnreadService::class)->total(auth()->user()), 0, false) : 0;
        request()->attributes->set('chat_unread_total', $chatUnread);
    }

    $chatCap = (int) config('chat.unread_cap', 99);
@endphp
@if ($chatUnread > 0)
    <span class="ms-1 rounded-full bg-gold text-slate-900 text-[10px] font-black px-1.5 py-0.5" aria-label="رسائل غير مقروءة">{{ $chatUnread > $chatCap ? $chatCap.'+' : $chatUnread }}</span>
@endif
