@extends('layouts.app')

@section('title', 'المواسم الرسمية')

@section('content')
    <div class="max-w-5xl mx-auto">
        <x-page-header title="المواسم الرسمية" icon="sparkles" subtitle="مواسم بقصص وجوائز كبرى، تتغير بين مباشر وقريب ومنتهٍ." class="anim-fade-up" />

        @if ($seasons->isEmpty())
            <x-empty-state icon="sparkles" title="لا توجد مواسم رسمية متاحة حالياً" message="ترقّبونا قريباً." class="anim-fade-up" />
        @else
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ($seasons as $item)
                    @php
                        $season = $item->model;
                        $stateLabel = ['live' => 'مباشر الآن', 'upcoming' => 'قريباً', 'finished' => 'انتهى', 'inactive' => 'غير متاحة'][$item->state] ?? $item->state;
                    @endphp
                    <a href="{{ route('seasons.show', $season) }}"
                       class="puzzle-card !p-0 overflow-hidden anim-fade-up hover:border-amethyst/40 transition block {{ $season->is_featured ? 'season-card--featured' : '' }}">
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

                            @if ($season->logo_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($season->logo_image))
                                <img
                                    src="{{ \Illuminate\Support\Facades\Storage::url($season->logo_image) }}"
                                    alt=""
                                    class="season-logo absolute -bottom-6 {{ app()->getLocale() === 'ar' ? 'right-4' : 'left-4' }}"
                                >
                            @endif
                        </div>

                        <div class="p-5 {{ $season->logo_image ? 'pt-8' : '' }}">
                            <div class="flex items-center gap-2 mb-2">
                                <h2 class="font-display font-black text-xl text-white">{{ $season->campaign->title }}</h2>
                                <x-status-badge :tone="$item->state === 'live' ? 'success' : ($item->state === 'finished' ? 'neutral' : 'warning')" small>{{ $stateLabel }}</x-status-badge>
                            </div>

                            @if ($tagline = $season->heroTagline())
                                <p class="text-sm text-slate-400 line-clamp-2 mb-3">{{ $tagline }}</p>
                            @elseif ($season->campaign->description)
                                <p class="text-sm text-slate-400 line-clamp-2 mb-3">{{ $season->campaign->description }}</p>
                            @endif

                            <div class="flex items-center justify-between text-xs font-bold">
                                <span class="text-gold">🏆 {{ $season->grand_prize_description ?: 'الجائزة الكبرى قريباً' }}</span>
                                <span class="text-amethyst">
                                    @if ($item->state === 'live') ابدأ الرحلة ←
                                    @elseif ($item->state === 'upcoming') التفاصيل ←
                                    @else شاهد النتائج ← @endif
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection