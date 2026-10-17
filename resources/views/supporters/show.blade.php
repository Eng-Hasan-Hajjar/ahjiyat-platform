@extends('layouts.app')

@section('title', $supporter->display_name)
@section('content_width', 'max-w-3xl')

{{--
    E30: صفحة داعم علنية (ملف علني ونشط فقط). تعرض: الاسم/الشعار/الوصف/الرابط الآمن/المستوى/البرامج التي دعمها علنًا (مساهمات غير مجهولة فقط).
    لا تعرض أبدًا: المبالغ (إلا لبرنامج فعّل ذلك)، الملاحظات الإدارية، المراجع، البريد، ولا أي ربط بمساهمة مجهولة.
--}}
@section('content')
    <div class="space-y-6">
        <a href="{{ route('supporters.index') }}" class="inline-flex items-center gap-2 text-sm font-bold text-slate-400 hover:text-white transition anim-fade-up">→ كل الداعمين</a>

        <article class="glass rounded-3xl p-6 md:p-8 anim-fade-up space-y-4">
            <div class="flex items-start gap-4">
                @if ($card['logo_url'])
                    <img src="{{ $card['logo_url'] }}" alt="شعار {{ $supporter->display_name }}" class="w-20 h-20 rounded-2xl object-cover bg-white/5 shrink-0" width="80" height="80">
                @else
                    <span class="grid place-items-center w-20 h-20 rounded-2xl bg-amethyst/15 text-amethyst shrink-0"><x-ui-icon name="gift" class="w-9 h-9" /></span>
                @endif
                <div class="min-w-0">
                    <h1 class="font-display font-black text-2xl md:text-3xl text-white break-words">{{ $supporter->display_name }}</h1>
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        <x-status-badge tone="neutral" small>{{ $supporter->type === 'organization' ? 'مؤسسة' : 'فرد' }}</x-status-badge>
                        @include('supporters._tier', ['tier' => $tier])
                    </div>
                </div>
            </div>

            @if ($supporter->description)
                <p class="text-slate-300 whitespace-pre-line break-words">{{ $supporter->description }}</p>
            @endif

            @if ($card['website'])
                <a href="{{ $card['website'] }}" target="_blank" rel="noopener noreferrer sponsored" class="inline-flex items-center gap-1.5 text-sm font-bold text-amethyst hover:underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" dir="ltr">
                    <x-ui-icon name="globe" class="w-4 h-4" />{{ parse_url($card['website'], PHP_URL_HOST) }}
                </a>
            @endif

            @if ($since)
                <p class="text-xs text-slate-500">داعم منذ {{ \Illuminate\Support\Carbon::parse($since)->format('Y-m-d') }}</p>
            @endif
        </article>

        @if ($programs->isNotEmpty())
            <section aria-labelledby="supported-title" class="anim-fade-up">
                <x-section-header title="يدعم" />
                <h2 id="supported-title" class="sr-only">البرامج المدعومة</h2>
                <ul class="grid gap-2">
                    @foreach ($programs as $p)
                        <li class="glass rounded-xl px-4 py-3 flex items-center justify-between gap-3">
                            <a href="{{ route('supporters.index', ['scope' => 'program', 'program' => $p->slug]) }}" class="font-bold text-white hover:underline break-words rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">{{ $p->title }}</a>
                            @if ($p->show_amounts)
                                <span class="font-black text-gold shrink-0">{{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $p->total, $p->cur) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
