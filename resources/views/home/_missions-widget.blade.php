{{--
    E34: ودجت "اليوم" المدمج بلوحة اللاعب: 2-3 مهام أولوية + السلسلة + أقرب حدث + رابط "عرض الكل". قراءة فقط (لا صفوف تقدّم، لا مزامنة).
    @var array $widget  (MissionCenterService::widgetFor)
--}}
<section data-missions-widget aria-labelledby="today-widget-h" class="glass rounded-3xl p-5 sm:p-6 anim-fade-up d-1">
    <div class="flex items-center justify-between gap-3 mb-3">
        <h2 id="today-widget-h" class="font-display font-black text-lg text-white">اليوم</h2>
        <a href="{{ route('missions.index') }}" class="text-sm font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">عرض كل المهام ←</a>
    </div>

    @if ($widget['missions']->isEmpty())
        <p class="text-sm text-slate-400">لا مهام قيد التقدّم الآن. يمكنك متابعة اللعب أو تصفّح الأحداث.</p>
    @else
        <ul class="space-y-3">
            @foreach ($widget['missions'] as $row)
                <li>
                    <div class="flex items-center justify-between text-sm mb-1">
                        <span class="font-bold text-white truncate">{{ $row['quest']->name }}</span>
                        <span class="text-xs font-bold text-slate-400 shrink-0">{{ min($row['progress']->current_value, $row['progress']->target_value_snapshot) }} / {{ $row['progress']->target_value_snapshot }}</span>
                    </div>
                    <div class="w-full h-1.5 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $row['progress']->target_value_snapshot }}" aria-valuenow="{{ min($row['progress']->current_value, $row['progress']->target_value_snapshot) }}" aria-label="تقدُّم {{ $row['quest']->name }}">
                        <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $row['percent'] }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-400">
        <span>السلسلة الحالية: <span class="font-bold text-gold">{{ $widget['streak'] }}</span> يوم</span>
        @if ($widget['next_event'])
            <span>أقرب حدث: <a href="{{ route('events.index') }}" class="font-bold text-white hover:text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $widget['next_event']->title }}</a></span>
        @else
            <a href="{{ route('events.index') }}" class="font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">تقويم الأحداث</a>
        @endif
    </div>
</section>
