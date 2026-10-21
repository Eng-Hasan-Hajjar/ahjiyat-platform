@extends('layouts.app')

@section('title', 'تقويم الأحداث')

@section('content')

    <div class="max-w-3xl mx-auto">

        <x-page-header title="تقويم الأحداث" icon="calendar" subtitle="المواسم والحملات والمنافسات القادمة والجارية في مكان واحد. التوقيت: {{ $timezone }}." class="anim-fade-up" />

        @php
            $blocks = [
                ['key' => 'now', 'title' => 'يحدث الآن', 'empty' => 'لا توجد أحداث جارية حاليًا.'],
                ['key' => 'today', 'title' => 'اليوم', 'empty' => 'لا أحداث تبدأ اليوم.'],
                ['key' => 'week', 'title' => 'هذا الأسبوع', 'empty' => 'لا أحداث أخرى هذا الأسبوع.'],
                ['key' => 'upcoming', 'title' => 'قريبًا', 'empty' => 'لا أحداث مجدولة لاحقًا حتى الآن.'],
            ];
        @endphp

        @auth
            @if ($agenda->isNotEmpty())
                <section class="mb-8 anim-fade-up" aria-labelledby="agenda-h">
                    <h2 id="agenda-h" class="font-display font-black text-lg text-white mb-3">أجندتي</h2>
                    <ul class="space-y-3">
                        @foreach ($agenda as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                </section>
            @endif
        @endauth

        @foreach ($blocks as $block)
            <section class="mb-8 anim-fade-up" aria-labelledby="ev-{{ $block['key'] }}">
                <h2 id="ev-{{ $block['key'] }}" class="font-display font-black text-lg text-white mb-3">{{ $block['title'] }}</h2>
                @if ($sections[$block['key']]->isEmpty())
                    <p class="text-sm text-slate-500">{{ $block['empty'] }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($sections[$block['key']] as $item)
                            @include('events._item', ['item' => $item])
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

        @auth
            <p class="text-sm text-slate-400"><a href="{{ route('missions.index') }}" class="font-bold text-amethyst rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلى مهامي ←</a></p>
        @endauth

    </div>

@endsection
