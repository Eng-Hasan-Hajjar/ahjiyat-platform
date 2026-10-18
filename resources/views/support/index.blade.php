@extends('layouts.app')

@section('title', 'ادعم المنصة')
@section('content_width', 'max-w-5xl')

{{--
    E32: مدخل الدعم العام. يعرض البرامج النشطة الآن فقط. أرقام التمويل/التعزيز مؤكَّدة فقط (الطلبات المعلّقة لا تظهر في أي رقم). الراعي المجهول لا يظهر.
    الدعم طوعي ولا يمنح أي أفضلية في اللعب أو المكافآت الشخصية. كل النصوص مهرّبة.
--}}
@section('content')
    <div class="space-y-8">
        <x-page-header title="ادعم المنصة" icon="gift" subtitle="دعمك يموّل المكافآت والفعاليات الرسمية ويُعزّز جوائز اللاعبين. الدعم طوعي ولا يمنحك أي أفضلية في اللعب." class="anim-fade-up !mb-0" />

        @auth
            <div class="flex flex-wrap gap-2 anim-fade-up">
                <a href="{{ route('support.history') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="wallet" class="w-4 h-4" />طلبات دعمي</a>
                <a href="{{ route('supporters.index') }}" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"><x-ui-icon name="trophy" class="w-4 h-4" />لوحة الداعمين</a>
            </div>
        @endauth

        @if (! $enabled)
            <x-empty-state icon="gift" title="الدعم متوقف مؤقتًا" message="لا يمكن إنشاء طلبات دعم جديدة الآن. شكرًا لتفهّمك." />
        @elseif ($programs->isEmpty())
            <x-empty-state icon="gift" title="لا برامج دعم نشطة حاليًا" message="ستظهر هنا برامج الرعاية عند تفعيلها." />
        @else
            <section aria-labelledby="programs-title" class="space-y-4 anim-fade-up">
                <h2 id="programs-title" class="sr-only">برامج الدعم</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($programs as $program)
                        @php($card = $cards[$program->id])
                        <article class="puzzle-card !p-5 space-y-3" data-program="{{ $program->slug }}">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-display font-black text-white break-words min-w-0">{{ $program->title }}</h3>
                                <x-status-badge tone="success" small>نشط</x-status-badge>
                            </div>
                            <p class="text-xs text-slate-400">{{ $targets->typeLabel($program->target_type) }}</p>
                            @if (filled($program->description))
                                <p class="text-sm text-slate-300 break-words">{{ \Illuminate\Support\Str::limit($program->description, 160) }}</p>
                            @endif
                            @include('support._progress')
                            @if ($card['boost_bps'] > 0)
                                <p class="text-xs font-bold text-emerald">تعزيز المكافآت الرسمية الحالي: {{ rtrim(rtrim(number_format($card['boost_bps'] / 100, 2), '0'), '.') }}٪</p>
                            @endif
                            @if ($card['main'])
                                <p class="text-xs text-slate-400">الراعي الرئيسي: <a href="{{ route('supporters.show', $card['main']['slug']) }}" class="font-bold text-amethyst hover:underline">{{ $card['main']['name'] }}</a></p>
                            @endif
                            <div class="flex flex-wrap gap-2 pt-1">
                                <a href="{{ route('support.program', $program->slug) }}" class="btn-secondary">التفاصيل</a>
                                <a href="{{ route('support.checkout', $program->slug) }}" class="btn-gem">ادعم هذا البرنامج</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-xs text-slate-500 anim-fade-up">لا نطلب بيانات بطاقات ولا كلمات مرور بنكية أبدًا. الدعم لا يُحتسب إلا بعد تحقق الفريق من التحويل.</p>
    </div>
@endsection
