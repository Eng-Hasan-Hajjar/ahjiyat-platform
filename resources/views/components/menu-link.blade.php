@props(['href', 'icon' => null, 'active' => false, 'danger' => false])

{{-- E22: عنصر قائمة (رابط). أيقونة اختيارية، حالة نشطة، ولون خطر لتسجيل الخروج. هدف لمس ≥ 40px. --}}
<a href="{{ $href }}" role="menuitem" @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'flex items-center gap-3 px-4 py-2.5 text-sm font-bold transition motion-reduce:transition-none focus:outline-none focus-visible:bg-white/10',
        'text-white bg-amethyst/10' => $active,
        'text-slate-300 hover:bg-white/5 hover:text-white' => ! $active && ! $danger,
        'text-rose hover:bg-rose/10' => $danger,
    ]) }}>
    @if ($icon)
        <x-ui-icon :name="$icon" class="w-[1.15rem] h-[1.15rem] shrink-0 opacity-80" />
    @endif
    <span class="min-w-0 truncate">{{ $slot }}</span>
</a>
