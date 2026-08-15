@extends('layouts.app')

@section('title', 'الرئيسية')

@section('content')

    {{-- الهيرو = أحجية اليوم فعلياً، مو نص تسويقي عام - المستخدم يشوف "المنتج" من أول ثانية --}}
    <section class="bg-ink text-parchment rounded-2xl p-8 md:p-12 mb-10 relative overflow-hidden">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-amethyst/20 gem-facet"></div>
        <div class="absolute -left-4 bottom-6 w-16 h-16 bg-gold/20 gem-facet"></div>

        <div class="relative max-w-xl">
            <span class="inline-block text-xs font-bold text-gold mb-3 tracking-wide">أحجية اليوم</span>

            @if ($dailyPuzzle)
                <h1 class="font-display font-extrabold text-2xl md:text-3xl mb-4 leading-relaxed">
                    {{ $dailyPuzzle->prompt }}
                </h1>
                <a href="{{ route('puzzles.show', $dailyPuzzle) }}"
                   class="inline-flex items-center gap-2 bg-gold text-ink font-bold px-5 py-2.5 rounded-lg hover:bg-gold/90 transition">
                    حل الأحجية الآن
                    <span class="w-3 h-3 bg-ink gem-facet inline-block"></span>
                    {{ $dailyPuzzle->gem_reward }}
                </a>
            @else
                <h1 class="font-display font-extrabold text-2xl md:text-3xl mb-4 leading-relaxed">
                    حل الألغاز، اجمع الجواهر، وتصدّر لوحة الصدارة
                </h1>
                <a href="{{ route('puzzles.index') }}"
                   class="inline-flex items-center gap-2 bg-gold text-ink font-bold px-5 py-2.5 rounded-lg hover:bg-gold/90 transition">
                    تصفّح الأحجيات
                </a>
            @endif
        </div>
    </section>

    <section>
        <h2 class="font-display font-bold text-xl text-ink mb-4">التصنيفات</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            @foreach ($categories as $category)
                <a href="{{ route('puzzles.category', $category) }}"
                   class="bg-white border border-ink/10 rounded-xl p-4 text-center hover:border-amethyst hover:shadow-md transition">
                    <span class="font-display font-bold text-ink block mb-1">{{ $category->name }}</span>
                    <span class="text-xs text-ink/50">{{ $category->puzzles_count }} أحجية</span>
                </a>
            @endforeach
        </div>
    </section>

@endsection
