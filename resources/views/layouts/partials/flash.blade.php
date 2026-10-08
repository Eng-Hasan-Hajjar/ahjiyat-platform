{{-- E22: رسائل الحالة والأخطاء موحَّدة بصريًا (أيقونة SVG بدل Emoji، role مناسب للقارئ). المحتوى نفسه كما كان: success/error/hint وأخطاء التحقق. --}}
@php
    $flashes = array_filter([
        'success' => session('success'),
        'error' => session('error'),
        'hint' => session('hint'),
    ]);
    $styles = [
        'success' => ['border-emerald/30 bg-emerald/10 text-emerald', 'check-circle', 'status'],
        'error' => ['border-rose/30 bg-rose/10 text-rose', 'alert', 'alert'],
        'hint' => ['border-gold/30 bg-gold/10 text-gold', 'sparkles', 'status'],
    ];
@endphp

@foreach ($flashes as $type => $message)
    <div role="{{ $styles[$type][2] }}" class="mb-5 flex items-start gap-3 rounded-xl border {{ $styles[$type][0] }} px-4 py-3 text-sm font-bold anim-fade-up">
        <x-ui-icon :name="$styles[$type][1]" class="w-5 h-5 shrink-0 mt-px" />
        <p class="min-w-0">@if ($type === 'hint')<strong>التلميح:</strong> @endif{{ $message }}</p>
    </div>
@endforeach

@if ($errors->any())
    <div role="alert" class="mb-5 flex items-start gap-3 rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-3 text-sm anim-fade-up">
        <x-ui-icon name="alert" class="w-5 h-5 shrink-0 mt-px" />
        <ul class="list-disc ps-4 space-y-1 min-w-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
