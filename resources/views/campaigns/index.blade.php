@extends('layouts.app')

@section('title', 'الحملات')

@section('content')
    <div class="max-w-5xl mx-auto">
        <h1 class="font-display font-black text-2xl md:text-4xl text-white mb-8 anim-fade-up">
            الحملات 🏆
        </h1>

        @if ($campaigns->isEmpty())
            <div class="puzzle-card !p-8 text-center text-slate-400 anim-fade-up">
                لا توجد حملات متاحة حالياً.
            </div>
        @else
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ($campaigns as $campaign)
                    <a href="{{ route('campaigns.show', $campaign) }}"
                       class="puzzle-card !p-6 anim-fade-up hover:border-amethyst/40 transition block">
                        @if ($campaign->cover_image)
                            <div class="rounded-xl overflow-hidden border border-white/10 mb-4">
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($campaign->cover_image) }}"
                                     alt="{{ $campaign->title }}" class="w-full h-40 object-cover">
                            </div>
                        @endif

                        <h2 class="font-display font-black text-xl text-white mb-2">{{ $campaign->title }}</h2>

                        @if ($campaign->description)
                            <p class="text-sm text-slate-400 line-clamp-2 mb-4">{{ $campaign->description }}</p>
                        @endif

                        <div class="flex items-center justify-between text-xs font-bold">
                            @if ($campaign->ends_at)
                                <span class="text-gold">ينتهي {{ $campaign->ends_at->translatedFormat('j F') }}</span>
                            @else
                                <span class="text-emerald">متاحة الآن</span>
                            @endif
                            <span class="text-amethyst">ابدأ التحدي ←</span>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $campaigns->links() }}
            </div>
        @endif
    </div>
@endsection