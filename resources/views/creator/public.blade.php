@extends('layouts.app')

@section('title', $creator->user->name.' - مبدع')
@section('content_width', 'max-w-5xl')

{{-- E28: صفحة المبدع العامة. اسم + صورة رمزية + نبذة + تاريخ الانضمام كمبدع + أرقام مجمَّعة + أحجياته الظاهرة فقط. بلا بريد/هاتف/حالة إدارية/بيانات خاصة. --}}
@section('content')
    <div class="space-y-6">
        <section class="glass rounded-3xl p-6 md:p-8 flex flex-col sm:flex-row items-center sm:items-start gap-5 anim-fade-up" aria-labelledby="creator-name">
            <x-player-avatar :avatar="$avatar" :frame="$frame" :name="$creator->user->name" size="xl" />
            <div class="min-w-0 text-center sm:text-start">
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                    <h1 id="creator-name" class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $creator->user->name }}</h1>
                    <x-status-badge tone="primary" icon="palette">مبدع</x-status-badge>
                </div>
                @if ($creator->bio)
                    <p class="mt-2 text-sm text-slate-300 break-words whitespace-pre-line max-w-2xl">{{ $creator->bio }}</p>
                @endif
                @if ($creator->approved_at)
                    <p class="mt-2 text-xs text-slate-400">مبدع منذ {{ $creator->approved_at->translatedFormat('F Y') }}</p>
                @endif

                {{-- E29: المتابعة. العدد المجمَّع علني؛ هوية المتابعين لا تُعرض. الزر يتبع حالة المشاهد (ضيف/مالك/غير موثَّق/مجمَّد/يتابع/يمكنه المتابعة). --}}
                <div class="mt-3 flex flex-wrap items-center justify-center sm:justify-start gap-3">
                    <span class="text-sm font-bold text-slate-300">{{ number_format($followers) }} {{ $followers === 1 ? 'متابع' : 'متابعين' }}</span>
                    @switch($followState)
                        @case('can_follow')
                            <form method="POST" action="{{ route('creators.follow', $creator->slug) }}">
                                @csrf
                                <button type="submit" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-2"><x-engagement-icon name="user-plus" class="w-4 h-4" />متابعة</button>
                            </form>
                            @break
                        @case('following')
                            <form method="POST" action="{{ route('creators.unfollow', $creator->slug) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary !py-2 !px-5 text-sm inline-flex items-center gap-2" aria-label="إلغاء متابعة هذا المبدع"><x-ui-icon name="check" class="w-4 h-4" />تتابعه</button>
                            </form>
                            @break
                        @case('guest')
                            <a href="{{ route('login') }}" class="btn-secondary !py-2 !px-5 text-sm">سجّل الدخول للمتابعة</a>
                            @break
                        @case('verify')
                            <span class="text-xs font-bold text-gold">وثّق بريدك الإلكتروني لتتمكن من المتابعة.</span>
                            @break
                        @case('frozen')
                            <span class="text-xs font-bold text-gold">حسابك مجمَّد؛ لا يمكن المتابعة حاليًا.</span>
                            @break
                    @endswitch
                </div>
                @if (session('engagement_status'))
                    <p role="status" class="mt-2 text-xs font-bold text-emerald">{{ session('engagement_status') }}</p>
                @endif
            </div>
        </section>

        <section aria-label="أرقام المبدع" class="grid grid-cols-2 {{ $quality ? 'md:grid-cols-3' : '' }} gap-3 anim-fade-up d-1">
            <x-stat-card label="أحجيات منشورة" :value="number_format($published)" icon="puzzle" />
            <x-stat-card label="إجمالي اللاعبين" :value="number_format($plays)" icon="friends" />
            @if ($quality)
                {{-- E29: ملخّص الجودة يظهر فقط ببلوغ حدّ أدنى من التقييمات على أعماله الظاهرة (لا «5.0» من عيّنة صغيرة). --}}
                <x-stat-card label="متوسط تقييم أعماله" :value="number_format($quality['average'], 1).' من 5'" icon="sparkles" tone="gold" :hint="number_format($quality['count']).' تقييم'" />
            @endif
        </section>

        <section aria-label="أحجيات المبدع" class="anim-fade-up d-2">
            <x-section-header title="أحجياته" />
            @if ($works->isEmpty())
                <x-empty-state icon="puzzle" title="لا أحجيات منشورة حاليًا" message="سيظهر هنا ما ينشره هذا المبدع بعد اعتماده من الإشراف." />
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($works as $work)
                        @include('community._card', ['work' => $work, 'savedIds' => $savedIds ?? []])
                    @endforeach
                </div>
                <div class="flex justify-center mt-6">{{ $works->links() }}</div>
            @endif
        </section>
    </div>
@endsection
