@if ($show)
    <div x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" class="relative shrink-0">
        <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true"
            aria-label="الإشعارات{{ $unread > 0 ? ' - '.$unread.' غير مقروء' : '' }}"
            class="relative grid place-items-center w-9 h-9 rounded-xl border border-white/10 bg-white/5 text-white hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" />
            </svg>
            @if ($unread > 0)
                <span data-bell-badge
                    class="absolute -top-1 -end-1 min-w-[1.1rem] h-[1.1rem] px-1 grid place-items-center rounded-full bg-rose text-[10px] font-black text-white leading-none">{{ $unread > 99 ? '99+' : $unread }}</span>
            @endif
        </button>

        {{-- سطح معتم عمدًا (bg-night-900) لا .glass: خلفية .glass شفافة 96% وتعتمد على backdrop-filter، واللوحة داخل
             شريط تنقل له backdrop-filter أيضًا فلا يستطيع العنصر المتداخل أخذ عينة من خلفه - فيظهر نص الصفحة من تحتها.
             bg-night-900 له تجاوز بالثيم الفاتح بـapp.css (var(--color-bg)) فتبقى معتمة بالثيمين. --}}
        <div x-show="open" x-cloak x-transition role="menu"
            class="fixed inset-x-3 top-16 sm:absolute sm:inset-x-auto sm:top-auto sm:end-0 sm:mt-2 sm:w-80 bg-night-900 rounded-2xl border border-white/10 shadow-2xl shadow-black/40 z-50 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/10">
                <span class="font-black text-white text-sm">الإشعارات</span>
                @if ($unread > 0)
                    <span class="text-[11px] font-bold text-amethyst">{{ $unread }} غير مقروء</span>
                @endif
            </div>

            @forelse ($recent as $n)
                @php($type = \App\Services\Notifications\NotificationType::tryFrom((string) $n->type_key))
                <form method="POST" action="{{ route('notifications.open', $n->id) }}">
                    @csrf
                    <button type="submit" role="menuitem"
                        class="w-full text-start flex gap-3 px-4 py-3 hover:bg-white/5 focus:outline-none focus-visible:bg-white/10 transition {{ $n->read_at === null ? 'bg-amethyst/5' : '' }}">
                        <span class="text-lg leading-none mt-0.5" aria-hidden="true">{{ $type?->icon() ?? '🔔' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold {{ $n->read_at === null ? 'text-white' : 'text-slate-400' }} truncate">{{ $n->data['title'] ?? '' }}</span>
                            <span class="block text-[11px] text-slate-500 mt-0.5">{{ $n->created_at->locale('ar')->diffForHumans() }}</span>
                        </span>
                        @if ($n->read_at === null)
                            <span class="w-2 h-2 rounded-full bg-amethyst mt-1.5 shrink-0" aria-label="غير مقروء"></span>
                        @endif
                    </button>
                </form>
            @empty
                <p class="px-4 py-6 text-center text-sm text-slate-400">لا توجد إشعارات بعد.</p>
            @endforelse

            <a href="{{ route('notifications.index') }}"
                class="block text-center text-xs font-bold text-amethyst hover:text-white border-t border-white/10 px-4 py-3 transition">عرض كل الإشعارات</a>
        </div>
    </div>
@endif
