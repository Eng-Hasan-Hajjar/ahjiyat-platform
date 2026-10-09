{{--
    E22: الرأس العام. **غلاف الرأس سائل** (عرض كامل بهامش آمن 16px جوال / 24px سطح مكتب)، منفصل عن غلاف المحتوى المقيَّد (max-w بـ<main>).
    - مصادق: شعار + تنقل أيقونات رئيسي (Home/Puzzles/Competitions/Teams/Friends/Messages) + «المزيد» + إجراءات (ثيم، جرس، محفظة، قائمة الحساب).
    - ضيف: شعار + روابط نصية قليلة + دخول/تسجيل (أبسط بكثير، بلا غلاف لاعب).
    - أقل من md: الأيقونات الرئيسية تنتقل للتنقل السفلي (layouts.partials.bottom-nav)، وتبقى الرسائل والإشعارات والحساب ظاهرة.
    لا عناصر بعرض ثابت ولا nowrap تتجاوز العرض: الأيقونات 40px بفواصل صغيرة (≈ 700px أقصى عرض مطلوب عند 1100px).

    E23: الرأس واعٍ بالتمرير (عرض فقط، بلا منطق نطاق). حالتان بصنف .app-header (انظر app.css): القمة شبه شفافة؛ وبعد عتبة 12px من التمرير تصير شبه معتمة
    (.app-header--scrolled) فلا يظهر محتوى الصفحة (مثل عنوان الدردشة) من تحت الرأس. المستمع passive وخفيف (مقارنة رقم واحد)، ولا يُخفى الرأس بالتمرير أبدًا.
    (ترتيب السمات: العادية أولًا ثم Alpine، فتبقى قابلة للقراءة بأي محلّل DOM قديم لا يحتمل @ في أسماء السمات.)
    الارتفاع مصدره --app-header-h (يستعمله sticky والدردشة)، وورقة قائمة الضيف مطلقة تحت الرأس فلا تغيّر ارتفاعه.
--}}
<header class="app-header sticky top-0 z-40" data-app-header data-scrolled="false"
    x-data="{ guestOpen: false, scrolled: false, threshold: 12 }" x-init="scrolled = window.scrollY > threshold"
    :data-scrolled="scrolled ? 'true' : 'false'" :class="{ 'app-header--scrolled': scrolled }"
    @scroll.window.passive="scrolled = window.scrollY > threshold" @keydown.escape.window="guestOpen = false">
    <div class="flex items-center gap-2 sm:gap-3 h-full w-full px-4 lg:px-6">
        <a href="{{ route('home') }}" aria-label="{{ $general['site_name'] }} - الرئيسية" class="flex items-center gap-2.5 shrink-0 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="h-8 sm:h-9 w-auto">
            @else
                <span aria-hidden="true" class="gem-facet w-8 h-8 sm:w-9 sm:h-9 grid place-items-center text-sm font-black text-white bg-gradient-to-br from-amethyst via-fuchsia-500 to-gold">✦</span>
            @endif
            <span class="hidden sm:inline text-lg lg:text-xl font-black text-gradient-gem">{{ $general['short_name'] }}</span>
        </a>

        @auth
            <nav aria-label="التنقل الرئيسي" class="hidden md:flex flex-1 min-w-0 items-center justify-center gap-1 lg:gap-2">
                @foreach ($menu['primary'] as $item)
                    <x-nav-icon :href="$item['url']" :icon="$item['icon']" :label="$item['label']" :active="\App\Support\NavigationMenu::isActive($item)">
                        @if (($item['badge'] ?? null) === 'chat')
                            <x-slot:badge><x-chat-badge /></x-slot:badge>
                        @endif
                    </x-nav-icon>
                @endforeach

                <x-menu align="start" width="w-72" label="المزيد من الأقسام">
                    <x-slot:trigger title="المزيد من الأقسام" class="grid place-items-center w-10 h-10 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition motion-reduce:transition-none">
                        <x-ui-icon name="grid" class="w-[1.35rem] h-[1.35rem]" />
                    </x-slot:trigger>

                    @foreach ($menu['more'] as $group)
                        <p class="px-4 pt-2 pb-1 text-[11px] font-black tracking-wide text-slate-500">{{ $group['title'] }}</p>
                        @foreach ($group['items'] as $item)
                            <x-menu-link :href="$item['url']" :icon="$item['icon']" :active="\App\Support\NavigationMenu::isActive($item)">{{ $item['label'] }}</x-menu-link>
                        @endforeach
                    @endforeach
                </x-menu>
            </nav>
            <div class="flex-1 md:hidden"></div>
        @else
            <nav aria-label="التنقل الرئيسي" class="hidden md:flex flex-1 min-w-0 items-center justify-center gap-4 lg:gap-6 text-sm font-bold text-slate-300">
                @foreach ($menu['guest'] as $item)
                    <a href="{{ $item['url'] }}" @if (\App\Support\NavigationMenu::isActive($item)) aria-current="page" @endif
                        class="rounded-lg px-1 py-1 transition motion-reduce:transition-none hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst {{ \App\Support\NavigationMenu::isActive($item) ? 'text-white' : '' }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="flex-1 md:hidden"></div>
        @endauth

        <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
            @if ($appearance['allow_theme_switch'])
                <x-theme-switcher />
            @endif

            @auth
                <x-notification-bell />

                @if ($navigation['show_gem_balance'])
                    <a href="{{ route('wallet.index') }}" title="محفظتي" aria-label="محفظتي: {{ number_format(auth()->user()->wallet?->available_balance ?? 0) }} جوهرة"
                        class="hidden sm:inline-flex rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <x-gem-badge :amount="auth()->user()->wallet?->available_balance ?? 0" />
                    </a>
                @endif

                <x-menu align="end" width="w-64" label="قائمة الحساب">
                    <x-slot:trigger title="حسابي" class="flex items-center gap-1 rounded-full p-0.5 pe-1.5 hover:bg-white/5 transition motion-reduce:transition-none">
                        <x-player-avatar :avatar="$navLoadout[\App\Models\StoreItem::SLOT_AVATAR]" :frame="$navLoadout[\App\Models\StoreItem::SLOT_FRAME]" :name="auth()->user()->name" size="sm" />
                        <x-ui-icon name="chevron-down" class="w-3.5 h-3.5 text-slate-400 hidden sm:block" />
                    </x-slot:trigger>

                    <div class="flex items-center gap-3 px-4 pt-2 pb-3 mb-1 border-b border-white/10">
                        <x-player-avatar :avatar="$navLoadout[\App\Models\StoreItem::SLOT_AVATAR]" :frame="$navLoadout[\App\Models\StoreItem::SLOT_FRAME]" :name="auth()->user()->name" />
                        <div class="min-w-0">
                            <p class="font-black text-white text-sm truncate">{{ auth()->user()->name }}</p>
                            @if ($navLevel)
                                <p class="text-[11px] font-bold text-amethyst">المستوى {{ $navLevel->level_number }}</p>
                            @endif
                        </div>
                    </div>

                    @foreach ($menu['account'] as $item)
                        <x-menu-link :href="$item['url']" :icon="$item['icon']" :active="\App\Support\NavigationMenu::isActive($item)">{{ $item['label'] }}</x-menu-link>
                    @endforeach

                    @can('admin.access')
                        <x-menu-link :href="url('/admin')" icon="shield">لوحة الإدارة</x-menu-link>
                    @endcan

                    <form method="POST" action="{{ route('logout') }}" class="mt-1 pt-1 border-t border-white/10">
                        @csrf
                        <button type="submit" role="menuitem" class="flex w-full items-center gap-3 px-4 py-2.5 text-sm font-bold text-rose hover:bg-rose/10 transition motion-reduce:transition-none focus:outline-none focus-visible:bg-rose/10">
                            <x-ui-icon name="logout" class="w-[1.15rem] h-[1.15rem] shrink-0 opacity-80" />
                            تسجيل الخروج
                        </button>
                    </form>
                </x-menu>
            @else
                <a href="{{ route('login') }}" class="hidden sm:inline-flex chip !py-1.5">دخول</a>
                @if ($access['allow_registration'] && $access['show_registration_cta'])
                    <a href="{{ route('register') }}" class="hidden md:inline-flex btn-gem !py-2 !px-4 text-sm">إنشاء حساب</a>
                @endif

                <button type="button" aria-label="فتح القائمة" title="القائمة" aria-controls="guest-menu" aria-expanded="false"
                    class="md:hidden grid place-items-center w-10 h-10 rounded-xl border border-white/10 bg-white/5 text-white shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"
                    @click="guestOpen = !guestOpen" :aria-expanded="guestOpen.toString()">
                    <x-ui-icon name="menu" x-show="!guestOpen" class="w-5 h-5" />
                    <x-ui-icon name="x" x-show="guestOpen" x-cloak class="w-5 h-5" />
                </button>
            @endauth
        </div>
    </div>

    @guest
        <div id="guest-menu" class="md:hidden absolute inset-x-0 top-full border-y border-white/10 bg-night-900 shadow-xl shadow-black/30" x-show="guestOpen" x-cloak x-transition.opacity.duration.150ms @click.outside="guestOpen = false">
            <nav aria-label="القائمة" class="px-4 py-3 flex flex-col gap-1 text-sm font-bold text-slate-300">
                @foreach ($menu['guest'] as $item)
                    <a href="{{ $item['url'] }}" @click="guestOpen = false" class="flex items-center gap-3 rounded-xl px-3 py-3 hover:bg-white/5 hover:text-white transition motion-reduce:transition-none">
                        <x-ui-icon :name="$item['icon']" class="w-5 h-5 opacity-80" />{{ $item['label'] }}
                    </a>
                @endforeach
                <div class="mt-2 pt-3 border-t border-white/10 flex flex-col gap-2">
                    <a href="{{ route('login') }}" class="chip justify-center text-center">دخول</a>
                    @if ($access['allow_registration'] && $access['show_registration_cta'])
                        <a href="{{ route('register') }}" class="btn-gem justify-center">إنشاء حساب</a>
                    @endif
                </div>
            </nav>
        </div>
    @endguest
</header>
