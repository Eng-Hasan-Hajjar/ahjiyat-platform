@extends('layouts.app')

@section('title', 'طلب استبدال')

@section('content')
    <div class="max-w-md mx-auto bg-white border border-ink/10 rounded-2xl p-6">
        <h1 class="font-display font-bold text-xl text-ink mb-4">طلب استبدال جديد</h1>

        @if (! $eligibility['eligible'])
            <div class="rounded-lg bg-rose-50 border border-rose text-rose px-4 py-3 text-sm mb-4">
                <ul class="list-disc pr-5 space-y-1">
                    @foreach ($eligibility['reasons'] as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <form method="POST" action="{{ route('redemption.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-ink/70 mb-1">عدد الجواهر المطلوب استبداله</label>
                    <input type="number" name="gems_amount" min="{{ config('gems.min_redemption') }}" required
                           class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst">
                    <span class="text-xs text-ink/40">الحد الأدنى: {{ number_format(config('gems.min_redemption')) }} جوهرة</span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-ink/70 mb-1">المكافأة المطلوبة</label>
                    <textarea name="reward_description" required rows="3"
                              class="w-full rounded-lg border border-ink/20 px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-amethyst"
                              placeholder="مثال: قسيمة شحن رصيد، منتج معيّن..."></textarea>
                </div>
                <button type="submit"
                        class="w-full bg-amethyst text-white font-bold py-2.5 rounded-lg hover:bg-amethyst-700 transition">
                    إرسال الطلب
                </button>
            </form>
        @endif
    </div>
@endsection
