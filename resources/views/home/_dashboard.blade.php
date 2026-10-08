{{--
    E22: لوحة اللاعب. التسلسل: ما يهمني الآن (urgent) ← تقدّمي وحالتي ← الاستكشاف. كلها بيانات قراءة فقط (PlayerDashboardService + متغيرات HomeController القائمة)؛
    لا محتوى رسائل خاصة (عدّاد فقط)، ولا تكرار لمركز الإشعارات. تفاصيل المستوى والمهام من خدماتها القائمة بلا حساب جديد.
--}}
@php
    $u = auth()->user();
    $dLoadout = app(\App\Services\PlayerIdentity\CosmeticLoadoutService::class)->loadoutFor($u);
    $verified = $u->hasVerifiedEmail();
    $hasContinue = $featuredSeason && $featuredSeasonCurrentStep;
    $primary = match (true) {
        $hasContinue => ['أكمل من حيث توقفت', route('campaigns.steps.show', [$featuredSeason->campaign, $featuredSeasonCurrentStep]), 'puzzle'],
        (bool) $dailyPuzzle => ['حلّ أحجية اليوم', route('puzzles.show', $dailyPuzzle), 'puzzle'],
        default => ['تصفّح الأحجيات', route('puzzles.index'), 'puzzle'],
    };
    $cardCount = count($dashboard['urgent']) + count($dashboard['info']) + ($featuredSeason ? 1 : 0) + ($dailyPuzzle ? 1 : 0);
@endphp

<div class="space-y-8">
    {{-- 1) الهوية والتقدّم + الإجراء الأهم --}}
    <section class="glass rounded-3xl p-5 sm:p-7 anim-fade-up" aria-label="ملخّصي">
        <div class="flex flex-col lg:flex-row lg:items-center gap-5">
            <div class="flex items-center gap-4 min-w-0 flex-1">
                <a href="{{ route('players.show', $u) }}" aria-label="ملفي الشخصي" class="shrink-0 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                    <x-player-avatar :avatar="$dLoadout[\App\Models\StoreItem::SLOT_AVATAR]" :frame="$dLoadout[\App\Models\StoreItem::SLOT_FRAME]" :name="$u->name" size="xl" />
                </a>
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-slate-400">مرحبًا بعودتك</p>
                    <h1 class="font-display font-black text-2xl sm:text-3xl text-white truncate">{{ $u->name }}</h1>
                    @if ($myCurrentLevel)
                        <div class="mt-2 max-w-sm">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-bold text-white">المستوى {{ $myCurrentLevel->level_number }} · {{ $myCurrentLevel->name }}</span>
                                <span class="text-slate-400">{{ $myNextLevel ? $myProgressPercent.'%' : 'أعلى مستوى' }}</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuenow="{{ $myProgressPercent }}" aria-valuemin="0" aria-valuemax="100" aria-label="التقدّم نحو المستوى التالي">
                                <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $myProgressPercent }}%"></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <a href="{{ $primary[1] }}" class="btn-gem !py-3 !px-6 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon :name="$primary[2]" class="w-5 h-5" /> {{ $primary[0] }}</a>
                <a href="{{ route('competitions.index') }}" class="chip !py-2.5 inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trophy" class="w-4 h-4" /> المنافسات</a>
            </div>
        </div>
    </section>

    {{-- 2) حالة سريعة (ست بطاقات كحد أقصى) --}}
    <section aria-label="حالتي السريعة" class="grid grid-cols-2 lg:grid-cols-3 gap-3 anim-fade-up d-1">
        <x-stat-card label="المستوى" :value="$myCurrentLevel?->level_number ?? 1" icon="sparkles" tone="amethyst" :href="route('progress.show')" :hint="$myNextLevel ? $myProgressPercent.'% للتالي' : 'أعلى مستوى'" />
        <x-stat-card label="مهام اليوم" :value="$myQuestsCompletedToday.'/'.$myQuestsTotalToday" icon="quests" :href="route('quests.show')" />
        <x-stat-card label="سلسلة الأيام" :value="$myStreak?->current_streak ?? 0" icon="fire" tone="gold" :href="route('quests.show')" />
        <x-stat-card label="رصيد الجواهر" :value="number_format($dashboard['wallet'])" icon="wallet" tone="gold" :href="route('wallet.index')" />
        @if ($dashboard['team'])
            <x-stat-card label="فريقي" :value="$dashboard['team']['model']?->name ?? 'فريقي'" icon="team" :href="$dashboard['team']['url']" />
        @else
            <x-stat-card label="الفرق" value="انضم لفريق" icon="team" :href="route('teams.index')" />
        @endif
        @if ($verified)
            <x-stat-card label="رسائل غير مقروءة" :value="$dashboard['unread_chat'] > (int) config('chat.unread_cap', 99) ? config('chat.unread_cap', 99).'+' : $dashboard['unread_chat']" icon="chat" :tone="$dashboard['unread_chat'] > 0 ? 'emerald' : 'default'" :href="route('messages.index')" />
        @else
            <x-stat-card label="الأصدقاء" value="وثّق بريدك" icon="friends" :href="route('profile.edit')" />
        @endif
    </section>

    {{-- 3) ما يهمني الآن --}}
    <section aria-labelledby="focus-title" class="anim-fade-up d-2">
        <x-section-header title="ما يهمني الآن" id="focus-title" />

        @if ($cardCount === 0)
            <x-empty-state icon="puzzle" title="ابدأ رحلتك" message="لا شيء عاجل الآن. اختر أحجية وابدأ باكتساب الخبرة والجواهر." action-label="تصفّح الأحجيات" :action="route('puzzles.index')" />
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($dashboard['urgent'] as $card)
                    <article class="rounded-2xl border border-gold/40 bg-gold/5 p-5 flex flex-col gap-3" data-card="{{ $card['type'] }}">
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-gold/15 text-gold"><x-ui-icon :name="$card['icon']" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-black text-white">{{ $card['title'] }}</p>
                                @if ($card['type'] === 'competition')
                                    <p class="text-xs text-slate-300 mt-0.5">حيّة الآن · تنتهي {{ $card['ends_at']->locale('ar')->diffForHumans() }}</p>
                                @else
                                    <p class="text-xs text-slate-300 mt-0.5">{{ $card['body'] }}</p>
                                @endif
                            </div>
                        </div>
                        <a href="{{ $card['url'] }}" class="btn-gem !py-2 !px-5 text-sm self-start focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $card['cta'] }}</a>
                    </article>
                @endforeach

                @if ($featuredSeason)
                    <article class="puzzle-card flex flex-col gap-3" data-card="continue">
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="calendar" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-black text-white truncate">{{ $featuredSeason->title }}</p>
                                <p class="text-xs text-slate-400 mt-0.5">{{ $hasContinue ? 'الخطوة الحالية: '.(data_get($featuredSeasonCurrentStep, 'title') ?: 'تابع رحلتك') : 'موسم مباشر الآن' }}</p>
                            </div>
                        </div>
                        <div class="w-full h-1.5 rounded-full bg-white/10 overflow-hidden" role="progressbar" aria-valuenow="{{ $featuredSeasonPercentage }}" aria-valuemin="0" aria-valuemax="100" aria-label="تقدّمي بالموسم">
                            <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $featuredSeasonPercentage }}%"></div>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs text-slate-400">{{ $featuredSeasonPercentage }}% مكتمل</span>
                            <a href="{{ $hasContinue ? route('campaigns.steps.show', [$featuredSeason->campaign, $featuredSeasonCurrentStep]) : route('seasons.index') }}" class="chip !py-1.5 !px-4 text-xs !text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $hasContinue ? 'أكمل' : 'افتح الموسم' }}</a>
                        </div>
                    </article>
                @endif

                @if ($dailyPuzzle)
                    <article class="puzzle-card flex flex-col gap-3" data-card="daily">
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-amethyst/15 text-amethyst"><x-ui-icon name="puzzle" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-black text-white">أحجية اليوم</p>
                                <p class="text-xs text-slate-400 mt-0.5">اكسب <span class="text-gold font-black">{{ $dailyPuzzle->gem_reward }}</span> جوهرة</p>
                            </div>
                        </div>
                        <a href="{{ route('puzzles.show', $dailyPuzzle) }}" class="chip !py-1.5 !px-4 text-xs !text-amethyst self-start focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">حلّها الآن</a>
                    </article>
                @endif

                @foreach ($dashboard['info'] as $card)
                    <article class="puzzle-card flex flex-col gap-3" data-card="{{ $card['type'] }}">
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-white/5 text-slate-300"><x-ui-icon :name="$card['icon']" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-black text-white truncate">{{ $card['title'] }}</p>
                                @if ($card['type'] === 'competition')
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $card['live'] ? 'انتهيتَ منها · تنتهي '.$card['ends_at']->locale('ar')->diffForHumans() : 'تبدأ '.$card['starts_at']->locale('ar')->diffForHumans() }}</p>
                                @else
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $card['body'] }}</p>
                                @endif
                            </div>
                        </div>
                        <a href="{{ $card['url'] }}" class="chip !py-1.5 !px-4 text-xs self-start focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $card['cta'] }}</a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- 4) الاستكشاف --}}
    @if ($home['show_categories'] && $categories->isNotEmpty())
        <section aria-labelledby="explore-title" class="anim-fade-up d-3">
            <x-section-header title="استكشف الأحجيات" id="explore-title" :href="route('puzzles.index')" link-label="كل الأحجيات" />
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                @foreach ($categories as $category)
                    <a href="{{ route('puzzles.category', $category) }}" class="glass rounded-2xl px-4 py-3 flex items-center justify-between gap-2 transition hover:border-amethyst/40 motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <span class="font-bold text-white text-sm truncate">{{ $category->name }}</span>
                        <span class="text-xs text-slate-400 shrink-0">{{ $category->puzzles_count }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <x-ad-slot name="home_inline_primary" />
</div>
