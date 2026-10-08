{{--
    E22: التنقل السفلي للجوال (< md) للمصادَقين فقط: 4 مداخل رئيسية + «المزيد» (ورقة سفلية تضم كل الأقسام الثانوية وقائمة الحساب). أيقونة + اسم قصير.
    الرسائل تحمل شارة غير المقروء، والجرس والحساب يبقيان بالشريط العلوي (لا يُخفيان). كل الوجهات من NavigationMenu نفسها (لا قائمة موازية).
    الحاضنة ثابتة بأسفل الشاشة مع safe-area؛ والجسم يحجز لها مساحة (pb) فلا يغطي المحتوى ولا التذييل.
--}}
@auth
    <div x-data="{ sheet: false }" @keydown.escape.window="sheet = false" class="md:hidden">
        <div x-show="sheet" x-cloak x-transition.opacity @click="sheet = false" aria-hidden="true" class="fixed inset-0 z-40 bg-black/50"></div>

        <div x-show="sheet" x-cloak x-transition role="dialog" aria-label="المزيد" aria-modal="false"
            class="fixed inset-x-0 z-50 max-h-[72vh] overflow-y-auto bg-night-900 rounded-t-3xl border border-white/10 px-4 pt-4 pb-5 shadow-2xl shadow-black/50"
            style="bottom: calc(4rem + env(safe-area-inset-bottom));">
            @foreach ($menu['more'] as $group)
                <p class="px-1 pb-2 pt-1 text-[11px] font-black tracking-wide text-slate-500">{{ $group['title'] }}</p>
                <div class="grid grid-cols-3 gap-2 mb-3">
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['url'] }}" @click="sheet = false" @if (\App\Support\NavigationMenu::isActive($item)) aria-current="page" @endif
                            class="flex flex-col items-center gap-1.5 rounded-2xl px-1 py-3 text-[11px] font-bold text-center leading-tight transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ \App\Support\NavigationMenu::isActive($item) ? 'bg-amethyst/15 text-white' : 'text-slate-300 bg-white/5 hover:bg-white/10' }}">
                            <x-ui-icon :name="$item['icon']" class="w-6 h-6" />
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach

            <p class="px-1 pb-2 pt-1 text-[11px] font-black tracking-wide text-slate-500">حسابي</p>
            <div class="flex flex-col gap-1">
                @foreach ($menu['account'] as $item)
                    <x-menu-link :href="$item['url']" :icon="$item['icon']" :active="\App\Support\NavigationMenu::isActive($item)" class="rounded-xl" @click="sheet = false">{{ $item['label'] }}</x-menu-link>
                @endforeach
                @can('admin.access')
                    <x-menu-link :href="url('/admin')" icon="shield" class="rounded-xl">لوحة الإدارة</x-menu-link>
                @endcan
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-bold text-rose hover:bg-rose/10 transition motion-reduce:transition-none focus:outline-none focus-visible:bg-rose/10">
                        <x-ui-icon name="logout" class="w-[1.15rem] h-[1.15rem] shrink-0 opacity-80" />
                        تسجيل الخروج
                    </button>
                </form>
            </div>
        </div>

        <nav aria-label="التنقل السريع" class="fixed bottom-0 inset-x-0 z-50 bg-night-900 border-t border-white/10" style="padding-bottom: env(safe-area-inset-bottom);">
            <ul class="flex">
                @foreach ($menu['bottom'] as $item)
                    @php $active = \App\Support\NavigationMenu::isActive($item); @endphp
                    <li class="flex-1 min-w-0">
                        <a href="{{ $item['url'] }}" @if ($active) aria-current="page" @endif
                            class="relative flex flex-col items-center justify-center gap-0.5 h-16 text-[11px] font-bold transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amethyst {{ $active ? 'text-white' : 'text-slate-400' }}">
                            <span class="relative">
                                <x-ui-icon :name="$item['icon']" class="w-6 h-6" />
                                @if (($item['badge'] ?? null) === 'chat')
                                    <span class="absolute -top-1 -end-2"><x-chat-badge /></span>
                                @endif
                            </span>
                            <span class="truncate max-w-full px-1">{{ $item['label'] }}</span>
                            @if ($active)
                                <span aria-hidden="true" class="absolute top-0 inset-x-6 h-0.5 rounded-full bg-gold"></span>
                            @endif
                        </a>
                    </li>
                @endforeach
                <li class="flex-1 min-w-0">
                    <button type="button" @click="sheet = !sheet" :aria-expanded="sheet.toString()" aria-haspopup="dialog" aria-label="المزيد من الأقسام"
                        class="relative flex w-full flex-col items-center justify-center gap-0.5 h-16 text-[11px] font-bold transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amethyst" :class="sheet ? 'text-white' : 'text-slate-400'">
                        <x-ui-icon name="grid" class="w-6 h-6" />
                        <span>المزيد</span>
                    </button>
                </li>
            </ul>
        </nav>
    </div>
@endauth
