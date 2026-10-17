{{--
    E30: شريط «برعاية». بلا رعاة = صفر إخراج (لا فراغ ولا تخطيط مكسور). النصوص تُهرَّب دائمًا؛ الرابط الخارجي مُنقَّى مسبقًا (http/https فقط) ويحمل rel="noopener noreferrer sponsored".
    الراعي الرئيسي أبرز بصريًا، والداعمون الآخرون صف أصغر. الشعار صورة (JPEG/PNG/WEBP فقط) بنص بديل. لا إعلانات ولا تتبّع نقرات هنا.
--}}
@if ($sponsors['any'])
    <section {{ $attributes->class('glass rounded-2xl px-4 py-3 md:px-5 md:py-4') }} aria-label="برعاية" data-sponsor-strip>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
            <span class="text-xs font-black text-slate-400">برعاية</span>

            @if ($sponsors['main'])
                @php($m = $sponsors['main'])
                <div class="flex items-center gap-3 min-w-0" data-main-sponsor>
                    @if ($m['logo_url'])
                        <img src="{{ $m['logo_url'] }}" alt="شعار {{ $m['name'] }}" class="w-10 h-10 rounded-xl object-cover bg-white/5 shrink-0" loading="lazy" width="40" height="40">
                    @endif
                    <div class="min-w-0">
                        <a href="{{ route('supporters.show', $m['slug']) }}" class="font-display font-black text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $m['name'] }}</a>
                        <x-status-badge tone="warning" icon="sparkles" small class="ms-2">الراعي الرئيسي</x-status-badge>
                        @if ($m['website'])
                            <a href="{{ $m['website'] }}" target="_blank" rel="noopener noreferrer sponsored" class="block text-[11px] text-slate-400 hover:text-white truncate" dir="ltr">{{ parse_url($m['website'], PHP_URL_HOST) }}</a>
                        @endif
                    </div>
                </div>
            @endif

            @foreach ($sponsors['supporting'] as $s)
                <div class="flex items-center gap-2 min-w-0" data-sponsor>
                    @if ($s['logo_url'])
                        <img src="{{ $s['logo_url'] }}" alt="شعار {{ $s['name'] }}" class="w-7 h-7 rounded-lg object-cover bg-white/5 shrink-0" loading="lazy" width="28" height="28">
                    @endif
                    <a href="{{ route('supporters.show', $s['slug']) }}" class="text-sm font-bold text-slate-200 hover:text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $s['name'] }}</a>
                </div>
            @endforeach

            <a href="{{ route('supporters.index') }}" class="ms-auto text-xs font-bold text-amethyst hover:underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">كل الداعمين</a>
        </div>
    </section>
@endif
