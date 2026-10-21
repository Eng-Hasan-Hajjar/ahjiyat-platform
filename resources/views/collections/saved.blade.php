@extends('layouts.app')

@section('title', 'مجموعات محفوظة')
@section('content_width', 'max-w-4xl')

{{-- E35: المجموعات التي حفظتَها. خاصة بك (user_id الجلسة). المجموعة التي لم تعد متاحة (خاصة/مخفية/مالك مقيَّد/حظر) تظهر بمكان بديل محايد بلا عنوان ولا مالك ولا سبب، ويمكن إزالتها. --}}
@section('content')
    <div class="space-y-6">
        <x-page-header title="مجموعات محفوظة" icon="inventory" subtitle="المجموعات العامة التي حفظتَها من المجتمع. لا يراها أحد غيرك." :back="route('collections.index')" backLabel="مجموعاتي" class="anim-fade-up !mb-0" />

        @if (session('success')) <p role="status" class="rounded-xl border border-emerald/30 bg-emerald/10 text-emerald px-4 py-2 text-sm font-bold">{{ session('success') }}</p> @endif
        @if (session('error')) <p role="alert" class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-2 text-sm font-bold">{{ session('error') }}</p> @endif

        @if ($items->isEmpty())
            <x-empty-state icon="inventory" title="لا مجموعات محفوظة بعد" message="افتح أي مجموعة عامة من «اكتشف» واضغط «احفظ المجموعة» لتظهر هنا." :action="route('discover.index')" actionLabel="اكتشف" />
        @else
            <p class="text-xs text-slate-400">{{ $items->total() }} من {{ $max }} مجموعة</p>
            <ul class="grid sm:grid-cols-2 gap-3 anim-fade-up" data-saved-collections>
                @foreach ($items as $item)
                    <li class="flex flex-col gap-2">
                        <div class="flex-1">
                            @if ($item['available'])
                                <a href="{{ route('collections.public.show', $item['public_id']) }}" class="glass rounded-2xl p-4 flex flex-col gap-1 h-full hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                    <span class="flex items-center gap-2 font-display font-black text-white break-words"><x-engagement-icon name="folder" class="w-5 h-5 text-amethyst shrink-0" />{{ $item['title'] }}</span>
                                    @if ($item['description']) <span class="text-xs text-slate-400 break-words">{{ $item['description'] }}</span> @endif
                                    <span class="mt-auto pt-2 text-[11px] font-bold text-slate-400">
                                        {{ number_format($item['visible_count']) }} أحجية · {{ number_format($item['saves_count']) }} حفظ
                                        @if ($item['owner_name']) · بقلم {{ $item['owner_name'] }} @endif
                                    </span>
                                </a>
                            @else
                                @include('engagement._unavailable')
                            @endif
                        </div>
                        <form method="POST" action="{{ route('collections.public.unsave', $item['public_id']) }}" class="text-center">
                            @csrf @method('DELETE')
                            <input type="hidden" name="from" value="saved">
                            <button type="submit" class="text-xs font-bold text-slate-400 hover:text-rose underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إزالة من المحفوظات</button>
                        </form>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-center">{{ $items->links() }}</div>
        @endif
    </div>
@endsection
