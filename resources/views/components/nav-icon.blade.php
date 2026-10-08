@props(['href', 'icon', 'label', 'active' => false])

{{--
    E22: عنصر تنقل رئيسي بأيقونة فقط (سطح المكتب). الاسم المتاح للقارئ aria-label، وتلميح مرئي يظهر عند المرور **وعند تركيز لوحة المفاتيح** (لا يعتمد على hover فقط).
    الحالة النشطة: خلفية + شريط ذهبي سفلي + aria-current. الهدف 40px على الأقل. شارة (مثل غير مقروء الدردشة) عبر الفتحة badge.
--}}
<a href="{{ $href }}" aria-label="{{ $label }}" @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'group relative grid place-items-center w-10 h-10 shrink-0 rounded-xl transition motion-reduce:transition-none',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst',
        'text-white bg-amethyst/15 after:absolute after:bottom-0.5 after:inset-x-3 after:h-0.5 after:rounded-full after:bg-gold' => $active,
        'text-slate-400 hover:text-white hover:bg-white/5' => ! $active,
    ]) }}>
    <x-ui-icon :name="$icon" class="w-[1.35rem] h-[1.35rem]" />

    @isset($badge)
        <span class="absolute -top-0.5 -end-0.5">{{ $badge }}</span>
    @endisset

    <span role="tooltip" aria-hidden="true"
        class="pointer-events-none absolute top-full mt-2 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-night-900 border border-white/10 px-2.5 py-1 text-[11px] font-bold text-white opacity-0 shadow-lg transition motion-reduce:transition-none group-hover:opacity-100 group-focus-visible:opacity-100 z-50">{{ $label }}</span>
</a>
