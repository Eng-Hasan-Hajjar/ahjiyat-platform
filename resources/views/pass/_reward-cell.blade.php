{{--
    E40: خلية مكافأة واحدة (مسار مجاني أو مميز) داخل صف الشريحة.
    @var array|null $cell  مكافأة + state (claimed|claimable|locked_tier|locked_premium|expired) أو null (لا مكافأة)
    @var string $lane      free|premium
--}}
@php
    $typeLabels = ['cosmetic' => 'تجميلي', 'access' => 'وصول', 'digital' => 'رقمي'];
@endphp
@if ($cell === null)
    <div class="rounded-xl border border-dashed border-white/10 px-3 py-3 text-center text-xs text-slate-600 min-h-[4.5rem] grid place-items-center" aria-hidden="true">—</div>
@else
    <div class="rounded-xl border px-3 py-3 min-h-[4.5rem] flex items-center gap-3 min-w-0
        {{ $cell['state'] === 'claimed' ? 'border-emerald/30 bg-emerald/5' : ($cell['state'] === 'claimable' ? 'border-gold/50 bg-gold/10' : 'border-white/10 bg-white/[.03]') }}"
        data-pass-reward data-state="{{ $cell['state'] }}" data-lane="{{ $lane }}">
        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl overflow-hidden bg-white/5 text-slate-300">
            @if (! empty($cell['image']))
                <img src="{{ \Illuminate\Support\Facades\Storage::url($cell['image']) }}" alt="" class="w-full h-full object-cover" loading="lazy">
            @else
                <x-ui-icon :name="$lane === 'premium' ? 'sparkles' : 'gift'" class="w-5 h-5" />
            @endif
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-sm font-bold text-white leading-snug line-clamp-2 break-words">{{ $cell['item_name'] }}@if ($cell['quantity'] > 1) <span class="text-slate-400 font-normal">×{{ $cell['quantity'] }}</span>@endif</span>
            <span class="block text-[11px] text-slate-500">{{ $typeLabels[$cell['item_type']] ?? 'عنصر' }}</span>
        </span>
        <span class="shrink-0">
            @switch($cell['state'])
                @case('claimed')
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-emerald"><x-ui-icon name="check-circle" class="w-4 h-4" />تم</span>
                    @break
                @case('claimable')
                    @if ($claimOpen)
                        <form method="POST" action="{{ route('pass.collect', $cell['id']) }}">
                            @csrf
                            <button type="submit" class="btn-gem !py-1.5 !px-3 text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" aria-label="استلام {{ $cell['item_name'] }}">استلام</button>
                        </form>
                    @endif
                    @break
                @case('locked_premium')
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-400" title="تُفتح بالمسار المميز"><x-ui-icon name="lock" class="w-4 h-4" />مميز</span>
                    @break
                @case('expired')
                    <span class="text-xs font-bold text-slate-500">انتهت المهلة</span>
                    @break
                @default
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-500"><x-ui-icon name="lock" class="w-4 h-4" />مقفلة</span>
            @endswitch
        </span>
    </div>
@endif
