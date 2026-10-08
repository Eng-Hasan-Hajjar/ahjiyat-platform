@extends('layouts.app')

@section('title', 'تحدّيات الفرق')

@section('content')
    <div class="max-w-4xl mx-auto space-y-6">
        <x-page-header :title="'تحدّيات فريق '.$team->name" subtitle="مباريات غير متزامنة: يُقفل الروستران عند القبول، ويحسب الموقع النتيجة. لا جوائز اقتصادية؛ المجد وحده." icon="bolt" :back="route('teams.index')" backLabel="الفرق" class="!mb-0 anim-fade-up">
            <x-slot:actions>
                <a href="{{ route('team-championships.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trophy" class="w-4 h-4" />بطولات الفرق</a>
            </x-slot:actions>
        </x-page-header>
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
