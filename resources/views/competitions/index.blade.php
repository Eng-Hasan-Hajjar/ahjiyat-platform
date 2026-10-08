@extends('layouts.app')

@section('title', 'المنافسات')

@section('content')
    <div class="space-y-8">
        <x-page-header title="المنافسات" icon="trophy" subtitle="أحجية واحدة، محاولة واحدة، ونتيجة يحسبها الموقع. لا رسوم دخول ولا أفضلية مدفوعة." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('competitions.hall-of-fame') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="sparkles" class="w-4 h-4" /> قاعة الأمجاد</a>
                <a href="{{ route('team-championships.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="flag" class="w-4 h-4" /> بطولات الفرق</a>
            </x-slot:actions>
        </x-page-header>

        @foreach ([['live', 'مباشرة الآن', $live, 'لا توجد منافسات مباشرة حاليًا.', 'lg:grid-cols-2'], ['upcoming', 'قادمة', $upcoming, 'لا توجد منافسات قادمة بعد.', 'lg:grid-cols-2'], ['ended', 'منتهية', $ended, 'لا توجد منافسات منتهية بعد.', 'lg:grid-cols-3']] as [$key, $label, $events, $empty, $cols])
            <section class="anim-fade-up" aria-labelledby="sec-{{ $key }}">
                <x-section-header :title="$label" id="sec-{{ $key }}" />
                @if ($events->isEmpty())
                    <x-empty-state icon="trophy" :title="$empty" />
                @else
                    <div class="grid gap-4 sm:grid-cols-2 {{ $cols }}">
                        @foreach ($events as $event)
                            @include('competitions._card', ['event' => $event])
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endsection
