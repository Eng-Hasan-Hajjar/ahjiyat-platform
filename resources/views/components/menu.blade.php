@props(['align' => 'end', 'width' => 'w-64', 'label'])

{{--
    E22: قائمة منسدلة قابلة لإعادة الاستعمال (قائمة «المزيد» وقائمة الحساب). زر حاضن بـaria-label وaria-expanded/aria-haspopup، يغلق بـEscape والنقر خارجها.
    السطح المنسدل معتم عمدًا (bg-night-900 ثم تجاوز الثيم الفاتح بـapp.css) لأن .glass شفاف ولا يصلح داخل شريط له backdrop-filter. الموضع منطقي (start/end) فيصحّ بـRTL.
--}}
<div x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" class="relative shrink-0">
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="menu" aria-label="{{ $label }}"
        {{ $trigger->attributes->class(['focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst']) }}>
        {{ $trigger }}
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu" aria-label="{{ $label }}"
        class="absolute top-full mt-2 {{ $align === 'start' ? 'start-0' : 'end-0' }} {{ $width }} max-w-[calc(100vw-1.5rem)] max-h-[75vh] overflow-y-auto bg-night-900 rounded-2xl border border-white/10 shadow-2xl shadow-black/40 py-2 z-50 motion-reduce:transition-none">
        {{ $slot }}
    </div>
</div>
