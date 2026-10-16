@extends('layouts.app')

@section('title', 'استوديو المبدع')

@section('content')
    <div class="space-y-8">
        <x-page-header title="استوديو المبدع" icon="palette" subtitle="أنشئ أحجيات المجتمع وتابع حالتها وأرقام لعبها." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('creators.show', $profile->slug) }}" class="btn-secondary !py-2 !px-4 text-sm">صفحتي العامة</a>
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
