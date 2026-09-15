@extends('layouts.app')

@section('title', 'المواسم الرسمية')

@section('content')
    <div class="max-w-5xl mx-auto">
        <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-8 anim-fade-up">
            المواسم الرسمية ✦
        </h1>

        @if ($seasons->isEmpty())
            <div class="puzzle-card !p-8 text-center text-slate-400 anim-fade-up">
                لا توجد مواسم رسمية متاحة حالياً - ترقّبونا قريباً.
            </div>
        @else
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ($seasons as $season)
                    <a href="{{ route('seasons.show', $season) }}"
                       class="puzzle-card !p-0 overflow-hidden anim-fade-up hover:border-amethyst/40 transition block">
                        <div class="relative h-36 bg-gradient-to-br from-amethyst/30 to-night-800">
                            @if ($season->banner_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->banner_image))
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($season->banner_image) }}"
                                     alt="{{ $season->campaign->title }}" class="w-full h-full object-cover">
                            @endif
                            <span class="season-badge absolute top-3 {{ app()->getLocale() === 'ar' ? 'right-3' : 'left-3' }}">
                                موسم {{ $season->code }}
                            </span>
                            @if ($season->is_featured)
                                <span class="chip absolute top-3 {{ app()->getLocale() === 'ar' ? 'left-3' : 'right-3' }} !text-gold">مُبرَز</span>
                            @endif
                        </div>

                        <div class="p-5">
                            <h2 class="font-display font-black text-xl text-white mb-2">{{ $season->campaign->title }}</h2>

                            @if ($tagline = $season->heroTagline())
                                <p class="text-sm text-slate-400 line-clamp-2 mb-3">{{ $tagline }}</p>
                            @endif

                            <div class="flex items-center justify-between text-xs font-bold">
                                <span class="text-gold">🏆 {{ $season->grand_prize_description ?: 'الجائزة الكبرى قريباً' }}</span>
                                <span class="text-amethyst">ابدأ الرحلة ←</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection