@extends('layouts.app')

@section('title', 'تحدّيات الأصدقاء')

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3 anim-fade-up">
            <div>
                <h1 class="font-display font-black text-2xl text-white">تحدّيات الأصدقاء</h1>
                <p class="text-xs text-slate-400 mt-1">تحدٍّ على أحجية واحدة، محاولة واحدة لكل طرف، والنتيجة يحسبها الموقع. بلا جوائز ولا رهان.</p>
            </div>
            <a href="{{ route('friends.index') }}" class="chip">← الأصدقاء</a>
        </div>

        @foreach ([['pending', 'قيد الانتظار', $pending, 'pending_page', 'لا تحدّيات بانتظار القبول.'], ['active', 'نشطة', $active, 'active_page', 'لا تحدّيات نشطة. اختر صديقًا من صفحة الأصدقاء لتتحدّاه.'], ['history', 'السجل', $history, 'history_page', 'لا تحدّيات سابقة بعد.']] as [$key, $label, $paginator, $pageName, $empty])
            <section class="anim-fade-up" aria-labelledby="ch-{{ $key }}">
                <h2 id="ch-{{ $key }}" class="font-display font-black text-lg text-white mb-3">{{ $label }}</h2>
                <div class="glass rounded-2xl divide-y divide-white/5">
                    @forelse ($paginator as $challenge)
                        @include('friends.challenges._row', ['challenge' => $challenge])
                    @empty
                        <p class="px-4 py-8 text-center text-slate-500 text-sm">{{ $empty }}</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $paginator->links() }}</div>
            </section>
        @endforeach
    </div>
@endsection
