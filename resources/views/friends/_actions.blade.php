@php
    use App\Services\Social\FriendRelation;

    /** @var \App\Models\User $other */
    /** @var FriendRelation $relation */
    $withBlock = $withBlock ?? false;
    $btn = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst';
@endphp

{{-- أزرار تعتمد على الحالة: لا يُعرض إجراء لا يملك المستخدم صلاحيته. كل تعديل POST/DELETE بـCSRF والطرف الآخر بـpublic_id. --}}
<div class="flex flex-wrap items-center gap-2">
    @if ($relation === FriendRelation::None)
        <form method="POST" action="{{ route('friends.requests.store', $other) }}">
            @csrf
            <button type="submit" class="btn-gem !py-2 !px-4 text-sm {{ $btn }}" aria-label="إرسال طلب صداقة إلى {{ $other->name }}">إضافة صديق</button>
        </form>
    @elseif ($relation === FriendRelation::OutgoingPending)
        <span class="chip !py-1 !px-3 text-xs">تم إرسال الطلب</span>
        <form method="POST" action="{{ route('friends.requests.cancel', $other) }}">
            @csrf @method('DELETE')
            <button type="submit" class="chip !py-1 !px-3 text-xs {{ $btn }}" aria-label="إلغاء طلب الصداقة المرسل إلى {{ $other->name }}">إلغاء الطلب</button>
        </form>
    @elseif ($relation === FriendRelation::IncomingPending)
        <form method="POST" action="{{ route('friends.requests.accept', $other) }}">
            @csrf
            <button type="submit" class="btn-gem !py-2 !px-4 text-sm {{ $btn }}" aria-label="قبول طلب صداقة {{ $other->name }}">قبول الطلب</button>
        </form>
        <form method="POST" action="{{ route('friends.requests.decline', $other) }}">
            @csrf
            <button type="submit" class="chip !py-1 !px-3 text-xs {{ $btn }}" aria-label="رفض طلب صداقة {{ $other->name }}">رفض</button>
        </form>
    @elseif ($relation === FriendRelation::Friends)
        <span class="chip !py-1 !px-3 text-xs !text-emerald-400">أصدقاء</span>
        <a href="{{ route('friends.challenges.create', $other) }}" class="chip !py-1 !px-3 text-xs {{ $btn }}" aria-label="تحدَّ {{ $other->name }}">تحدَّ هذا الصديق</a>
        <form method="POST" action="{{ route('friends.remove', $other) }}">
            @csrf @method('DELETE')
            <button type="submit" class="chip !py-1 !px-3 text-xs {{ $btn }}" aria-label="إزالة {{ $other->name }} من الأصدقاء">إزالة الصديق</button>
        </form>
    @elseif ($relation === FriendRelation::BlockedByMe)
        <span class="chip !py-1 !px-3 text-xs !text-rose-400">محظور</span>
        <form method="POST" action="{{ route('friends.blocks.destroy', $other) }}">
            @csrf @method('DELETE')
            <button type="submit" class="chip !py-1 !px-3 text-xs {{ $btn }}" aria-label="إلغاء حظر {{ $other->name }}">إلغاء الحظر</button>
        </form>
    @endif

    @if ($withBlock && $relation !== FriendRelation::Self && $relation !== FriendRelation::BlockedByMe)
        {{-- تأكيد الحظر بلوحة Alpine (لا confirm() الأصلية): يوضّح الأثر قبل التنفيذ --}}
        <div x-data="{ confirming: false }" class="relative">
            <button type="button" x-show="!confirming" @click="confirming = true"
                class="chip !py-1 !px-3 text-xs !text-rose-400 {{ $btn }}" aria-label="حظر {{ $other->name }}">حظر</button>
            <div x-show="confirming" x-cloak @keydown.escape="confirming = false"
                class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-slate-200 space-y-2" role="alertdialog" aria-label="تأكيد حظر {{ $other->name }}">
                <p>هل تريد حظر <strong class="text-white">{{ $other->name }}</strong>؟ سيُزال الصديق أو الطلب بينكما، ولن يستطيع أي منكما إرسال طلب للآخر.</p>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('friends.blocks.store', $other) }}">
                        @csrf
                        <button type="submit" class="btn-gem !py-1.5 !px-3 text-xs {{ $btn }}">تأكيد الحظر</button>
                    </form>
                    <button type="button" @click="confirming = false" class="chip !py-1 !px-3 text-xs {{ $btn }}">تراجع</button>
                </div>
            </div>
        </div>
    @endif
</div>
