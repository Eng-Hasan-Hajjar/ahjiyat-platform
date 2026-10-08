@props(['icon' => 'info', 'title' => 'لا توجد بيانات', 'message' => '', 'action' => null, 'actionLabel' => 'ابدأ'])

{{--
    E22: حالة فارغة موحَّدة (أيقونة SVG + عنوان + شرح + إجراء واحد واضح). استبدلت مكوّنًا قديمًا بأنماط مضمَّنة ومتغيرات غير معرّفة (كان يُستعمل بموضع واحد فقط).
    الألوان من رموز الثيم فتصح بالفاتح والداكن. الأيقونة اسم من ui-icon (لا Emoji).
--}}
<div {{ $attributes->class('glass rounded-2xl px-6 py-10 text-center') }}>
    <span class="mx-auto mb-4 grid place-items-center w-14 h-14 rounded-2xl bg-amethyst/15 text-amethyst"><x-ui-icon :name="$icon" class="w-7 h-7" /></span>
    <h3 class="font-display font-black text-lg text-white">{{ $title }}</h3>
    @if ($message)
        <p class="text-sm text-slate-400 mt-1.5 max-w-md mx-auto">{{ $message }}</p>
    @endif
    @if ($action)
        <a href="{{ $action }}" class="btn-gem !py-2.5 !px-6 text-sm mt-5 inline-flex focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $actionLabel }}</a>
    @endif
</div>
