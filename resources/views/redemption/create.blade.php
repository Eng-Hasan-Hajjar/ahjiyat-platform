@extends('layouts.app')

@section('title', 'طلب استبدال')

@section('content')

    <div class="max-w-md mx-auto puzzle-card !p-6 md:!p-8 anim-fade-up">
        <h1 class="font-display font-black text-xl md:text-2xl text-white mb-6">طلب استبدال جديد</h1>

        @if (! $eligibility['eligible'])
            <div class="rounded-xl border border-rose/30 bg-rose/10 text-rose px-4 py-4 text-sm">
                <ul class="list-disc pr-5 space-y-1 font-semibold">
                    @foreach ($eligibility['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <form method="POST" action="{{ route('redemption.store') }}" class="space-y-5">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-slate-300 mb-2">عدد الجواهر المطلوب استبداله</label>
                    <input type="number" name="gems_amount" min="{{ config('gems.min_redemption') }}" required
                           class="input-gem">
                    <span class="text-xs text-slate-500 mt-1.5 block">
                        الحد الأدنى: {{ number_format(config('gems.min_redemption')) }} جوهرة
                    </span>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-300 mb-2">المكافأة المطلوبة</label>
                    <textarea name="reward_description" required rows="3"
                              class="input-gem resize-none"
                              placeholder="مثال: قسيمة شحن رصيد، منتج معيّن..."></textarea>
                </div>
                <button type="submit" class="btn-gem w-full justify-center">
                    إرسال الطلب
                </button>
            </form>
        @endif
    </div>

@endsection