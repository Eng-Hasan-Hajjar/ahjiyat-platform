@extends('layouts.app')

@section('title', 'استوديو المبدع')

@section('content')
    <div class="space-y-8">
        <x-page-header title="استوديو المبدع" icon="palette" subtitle="أنشئ أحجيات المجتمع وتابع حالتها وأرقام لعبها." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creators.show', $profile->slug) }}" class="btn-secondary !py-2 !px-4 text-sm">صفحتي العامة</a>
                @if (config('creator_monetization.enabled', true)) {{-- E39: رابط ثابت بلا استعلام؛ الصفحة نفسها تشرح حالة التسييل. --}}
                    <a href="{{ route('creator.earnings') }}" class="btn-secondary !py-2 !px-4 text-sm">أرباحي</a>
                @endif
                @if (config('creator_series.enabled', true)) {{-- E30: رابط واحد فقط للوحة السلاسل؛ لا أي استعلام إضافي بلوحة الأحجيات. --}}
                    <a href="{{ route('creator.series.index') }}" class="btn-secondary !py-2 !px-4 text-sm">سلاسلي</a>
                @endif
                <a href="{{ route('creator.puzzles.create') }}" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="plus" class="w-4 h-4" />أحجية جديدة</a>
            </x-slot:actions>
        </x-page-header>

        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400 anim-fade-up">
            أحجيات المجتمع بلا جوائز اقتصادية: لا جواهر ولا نقاط خبرة لك أو للاعبين، وتظهر دائمًا بوسم «من المجتمع».
        </div>

        <section aria-label="أرقام أحجياتك" class="grid grid-cols-2 lg:grid-cols-5 gap-3 anim-fade-up">
            <x-stat-card label="أحجيات منشورة" :value="number_format($stats['published'])" icon="puzzle" />
            <x-stat-card label="لاعبون" :value="number_format($stats['players'])" icon="friends" />
            <x-stat-card label="محاولات" :value="number_format($stats['attempts'])" icon="chart" />
            <x-stat-card label="نسبة الإكمال" :value="$stats['completion_rate'] === null ? '—' : $stats['completion_rate'].'%'" icon="check-circle" />
            <x-stat-card :label="'محاولات آخر '.$stats['recent_days'].' أيام'" :value="number_format($stats['recent_attempts'])" icon="bolt" />
        </section>

        {{-- E29: تفاعل المجتمع مع أعمالك (أرقام مجمَّعة فقط: لا أسماء متابعين ولا مقيِّمين). المتوسط يُخفى تحت الحدّ الأدنى للعيّنة. --}}
        <section aria-label="تفاعل المجتمع" class="grid grid-cols-2 lg:grid-cols-4 gap-3 anim-fade-up">
            <x-stat-card label="متابعون" :value="number_format($stats['followers'])" icon="friends" />
            <x-stat-card label="حفظوا أعمالك" :value="number_format($stats['favorites_received'])" icon="sparkles" />
            <x-stat-card label="تقييمات" :value="number_format($stats['ratings_count'])" icon="chart" />
            <x-stat-card label="متوسط التقييم" :value="$stats['average_rating'] === null ? '—' : number_format($stats['average_rating'], 1).' من 5'" icon="trophy" tone="gold" />
        </section>
        @if ($stats['top_rated_work'])
            <p class="text-sm text-slate-300 anim-fade-up">
                الأعلى تقييمًا: <a href="{{ route('community.puzzles.show', $stats['top_rated_work']['public_id']) }}" class="font-bold text-amethyst underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $stats['top_rated_work']['title'] }}</a>
                <span class="text-slate-400">({{ number_format($stats['top_rated_work']['average'], 1) }} من 5 · {{ number_format($stats['top_rated_work']['count']) }} تقييم)</span>
            </p>
        @endif

        <section aria-label="أعمالي" class="anim-fade-up">
            <x-section-header title="أحجياتي" :href="route('creator.puzzles.index')" linkLabel="عرض الكل" />
            <nav aria-label="تصفية حسب الحالة" class="flex flex-wrap gap-2 mb-4">
                @foreach (['draft' => 'مسودات', 'pending' => 'قيد المراجعة', 'changes' => 'تحتاج تعديلات', 'published' => 'منشورة', 'rejected' => 'مرفوضة', 'archived' => 'مؤرشفة'] as $key => $label)
                    <a href="{{ route('creator.puzzles.index', ['status' => $key]) }}" class="chip !py-1.5 !px-3 text-xs">{{ $label }} <span class="font-black">{{ $counts[$key] }}</span></a>
                @endforeach
            </nav>

            @forelse ($recent as $work)
                @include('creator.puzzles._row', ['work' => $work])
            @empty
                <x-empty-state icon="palette" title="لم تنشئ أحجية بعد" message="ابدأ بمسودة جديدة، ثم أرسلها للمراجعة لتُنشر للمجتمع." :action="route('creator.puzzles.create')" actionLabel="أحجية جديدة" />
            @endforelse
        </section>
    </div>
@endsection
