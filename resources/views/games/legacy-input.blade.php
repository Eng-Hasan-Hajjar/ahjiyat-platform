{{-- الجزء الوحيد الذي كان يتغيّر فعلياً بين الأنواع الكلاسيكية الثلاثة -
     نفس الماركب حرفياً كما كان قبل استخراجه، صفر تغيير بصري --}}
@if ($puzzle->type === 'multiple_choice' && $puzzle->choices)
    <div class="space-y-3 mb-5">
        @foreach ($puzzle->choices as $choice)
            <label class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-4 cursor-pointer hover:border-amethyst hover:bg-amethyst/10 transition">
                <input type="radio" name="answer" value="{{ $choice }}" required class="accent-amethyst">
                <span class="text-slate-200 font-semibold">{{ $choice }}</span>
            </label>
        @endforeach
    </div>
@else
    <input type="text" name="answer" required placeholder="اكتب إجابتك هنا..."
           class="input-gem mb-5">
@endif