@extends('layouts.app')

@section('title', 'مختبر الأحجيات')
@section('meta_description', 'تدريب حر على بطاقات الذاكرة وترتيب التسلسل. للتدريب فقط: لا تُحتسب جواهر أو نقاط خبرة.')

@section('content')

    {{-- E47: مختبر الأحجيات - قائمة التمارين المنشورة (نوعان: الذاكرة والتسلسل). لا جواهر ولا XP ولا أثر على التقدّم الرسمي. --}}
    <x-page-header title="مختبر الأحجيات" icon="puzzle" subtitle="تدرّب بلا ضغط: جولات حرّة تعيدها كما تشاء." :back="route('puzzles.index')" backLabel="كل الأحجيات" class="anim-fade-up" />

    <p class="lab-notice anim-fade-up d-1 mb-6">
        <x-ui-icon name="info" class="w-4 h-4 shrink-0" />
        <span>للتدريب فقط. لا تُحتسب جواهر أو نقاط خبرة.</span>
    </p>

    @if ($total === 0)
        <x-empty-state icon="puzzle" title="لا توجد تمارين منشورة بعد" message="تُضاف التمارين من لوحة الإدارة، وتظهر هنا فور نشرها." />
    @else
        @foreach ($sections as $section)
            <section class="mb-10 anim-fade-up d-2" aria-labelledby="lab-type-{{ $section['type'] }}">
                <div class="mb-4">
                    <h2 id="lab-type-{{ $section['type'] }}" class="font-display font-black text-xl text-white">{{ $section['label'] }}</h2>
                    <p class="text-sm text-slate-400 mt-0.5">{{ $section['blurb'] }}</p>
                </div>

                @if ($section['exercises']->isEmpty())
                    <p class="lab-empty-type">لا توجد تمارين منشورة لهذا النوع حاليًا.</p>
                @else
                    <ul class="lab-card-grid">
                        @foreach ($section['exercises'] as $exercise)
                            <li>
                                <a href="{{ route('puzzle-lab.show', $exercise) }}" class="lab-card group">
                                    <span class="flex flex-wrap items-center justify-between gap-2 mb-3">
                                        <span class="chip !py-1 !px-3 !text-xs">{{ $exercise->typeLabel() }}</span>
                                        <x-difficulty-badge :difficulty="$exercise->level" />
                                    </span>
                                    <span class="lab-card-title">{{ $exercise->title }}</span>
                                    <span class="lab-card-summary">{{ $exercise->summary }}</span>
                                    <span class="lab-card-foot">
                                        <span class="text-xs font-bold text-slate-400">
                                            @if ($exercise->game_type === \App\Models\PuzzleLabExercise::TYPE_MEMORY)
                                                {{ $exercise->entryCount() }} أزواج
                                            @else
                                                {{ $exercise->entryCount() }} عناصر للترتيب
                                            @endif
                                        </span>
                                        <span class="text-sm font-bold text-amethyst">ابدأ التمرين <span aria-hidden="true">←</span></span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    @endif

@endsection
