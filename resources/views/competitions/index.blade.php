@extends('layouts.app')

@section('title', 'المنافسات')

@section('content')
    <div class="max-w-5xl mx-auto space-y-8">
        <div class="anim-fade-up">
            <h1 class="font-display font-black text-2xl md:text-3xl text-white">المنافسات</h1>
            <p class="text-sm text-slate-400 mt-1">أحجية واحدة، محاولة واحدة، ونتيجة يحسبها الموقع. لا رسوم دخول ولا أفضلية مدفوعة.</p>
        </div>

        @foreach ([['live', 'مباشرة الآن', $live, 'لا توجد منافسات مباشرة حاليًا.'], ['upcoming', 'قادمة', $upcoming, 'لا توجد منافسات قادمة بعد.'], ['ended', 'منتهية', $ended, 'لا توجد منافسات منتهية بعد.']] as [$key, $label, $events, $empty])
            <section class="anim-fade-up" aria-labelledby="sec-{{ $key }}">
                <h2 id="sec-{{ $key }}" class="font-display font-black text-lg text-white mb-3">{{ $label }}</h2>
                @if ($events->isEmpty())
                    <p class="glass rounded-2xl px-4 py-8 text-center text-slate-500 text-sm">{{ $empty }}</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($events as $event)
                            @include('competitions._card', ['event' => $event])
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endsection
