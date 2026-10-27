@extends('layouts.app')

@section('title', 'المواسم الرسمية')

@section('content')
    <div class="max-w-5xl mx-auto">
        <x-page-header title="المواسم الرسمية" icon="sparkles" subtitle="مواسم بقصص وجوائز كبرى، تتغير بين مباشر وقريب ومنتهٍ." class="anim-fade-up" />

        @if ($seasons->isEmpty())
            <x-empty-state icon="sparkles" title="لا توجد مواسم رسمية متاحة حالياً" message="ترقّبونا قريباً." class="anim-fade-up" />
        @else
            @if ($live->isNotEmpty())
                <section class="mb-10" aria-labelledby="seasons-live">
                    <x-section-header title="مباشر الآن" id="seasons-live" />
                    <div class="grid gap-6 md:grid-cols-2">
                        @foreach ($live as $item)
                            @include('seasons._card', ['item' => $item, 'wide' => $featured && $item->model->is($featured->model)])
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($upcoming->isNotEmpty())
                <section class="mb-10" aria-labelledby="seasons-upcoming">
                    <x-section-header title="قادمة" id="seasons-upcoming" />
                    <div class="grid gap-6 md:grid-cols-2">
                        @foreach ($upcoming as $item)
                            @include('seasons._card', ['item' => $item])
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($past->isNotEmpty())
                <section class="mb-10" aria-labelledby="seasons-past">
                    <x-section-header title="مواسم سابقة" id="seasons-past" :href="$pastTotal > $past->count() ? route('seasons.archive') : null" link-label="أرشيف المواسم" />
                    <div class="grid gap-6 md:grid-cols-2">
                        @foreach ($past as $item)
                            @include('seasons._card', ['item' => $item])
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </div>
@endsection
