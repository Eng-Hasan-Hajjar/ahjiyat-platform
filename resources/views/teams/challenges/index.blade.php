@extends('layouts.app')

@section('title', 'تحدّيات الفرق')

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <div>
                <h1 class="font-display font-black text-2xl text-white">تحدّيات فريق {{ $team->name }}</h1>
                <p class="text-sm text-slate-400 mt-1">مباريات غير متزامنة: يُقفل الروستران عند القبول، ويحسب الموقع النتيجة. لا جوائز اقتصادية؛ المجد وحده.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('team-championships.index') }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">🏆 بطولات الفرق</a>
                <a href="{{ route('teams.index') }}" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">← الفرق</a>
            </div>
        </div>
        @if ($canManage)
            <p class="text-xs text-slate-500">لتحدّي فريق: افتح صفحته واضغط «تحدَّ هذا الفريق».</p>
        @endif

        @foreach ([['incoming', 'واردة تنتظر ردّكم', $incoming], ['outgoing', 'أرسلتموها', $outgoing], ['active', 'جارية', $active], ['history', 'السجل', $history]] as [$key, $title, $page])
            <section class="space-y-3 anim-fade-up" aria-labelledby="{{ $key }}-title">
                <h2 id="{{ $key }}-title" class="font-display font-black text-lg text-white">{{ $title }}</h2>
                @forelse ($page as $challenge)
                    <x-team-challenge-row :challenge="$challenge" :team-id="$team->id" />
                @empty
                    <p class="glass rounded-2xl px-4 py-6 text-center text-slate-500 text-sm">لا شيء هنا.</p>
                @endforelse
                <div>{{ $page->links() }}</div>
            </section>
        @endforeach
    </div>
@endsection
