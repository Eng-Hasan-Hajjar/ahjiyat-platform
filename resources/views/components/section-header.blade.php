@props(['title', 'href' => null, 'linkLabel' => 'عرض الكل'])

{{-- E22: ترويسة قسم (عنوان + رابط «عرض الكل» اختياري). --}}
<div {{ $attributes->class('flex items-center justify-between gap-3 mb-4') }}>
    <h2 class="font-display font-black text-lg md:text-xl text-white">{{ $title }}</h2>
    @if ($href)
        <a href="{{ $href }}" class="inline-flex items-center gap-1 text-sm font-bold text-amethyst hover:underline rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            {{ $linkLabel }} <x-ui-icon name="chevron-left" class="w-3.5 h-3.5" />
        </a>
    @endif
</div>
