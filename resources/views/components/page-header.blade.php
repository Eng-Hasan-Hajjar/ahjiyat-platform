@props(['title', 'subtitle' => null, 'icon' => null, 'back' => null, 'backLabel' => 'رجوع'])

{{--
    E22: ترويسة صفحة موحَّدة (عنوان + وصف + أيقونة + رجوع اختياري + إجراءات بالفتحة actions). تُستعمل بصفحات اللاعب لتوحيد الإيقاع البصري. الألوان من رموز الثيم.
--}}
<header {{ $attributes->class('mb-6') }}>
    @if ($back)
        <a href="{{ $back }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-400 hover:text-white transition motion-reduce:transition-none mb-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            <x-ui-icon name="chevron-right" class="w-4 h-4" />{{ $backLabel }}
        </a>
    @endif
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            @if ($icon)
                <span class="grid place-items-center w-11 h-11 shrink-0 rounded-2xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$icon" class="w-6 h-6" /></span>
            @endif
            <div class="min-w-0">
                <h1 class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="text-sm text-slate-400 mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
        </div>
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</header>
