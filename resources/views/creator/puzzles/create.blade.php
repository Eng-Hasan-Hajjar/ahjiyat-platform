@extends('layouts.app')

@section('title', 'أحجية جديدة')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="أحجية جديدة" icon="plus" :subtitle="$kind ? 'الخطوة 2 من 2: المحتوى والحل. تُحفظ كمسودة ثم تعاينها وترسلها للمراجعة.' : 'الخطوة 1 من 2: اختر نوع الأحجية.'" :back="route('creator.puzzles.index')" backLabel="أحجياتي" class="anim-fade-up !mb-0" />

        @if (! $kind)
            <div class="grid gap-3 sm:grid-cols-2 anim-fade-up">
                @foreach ($kinds as $key => $cfg)
                    <a href="{{ route('creator.puzzles.create', ['kind' => $key]) }}" class="glass rounded-2xl p-5 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                        <span class="block font-display font-black text-white">{{ $cfg['label'] }}</span>
                        <span class="block text-xs text-slate-400 mt-1">
                            @switch($key)
                                @case('text') سؤال بجواب يكتبه اللاعب. @break
                                @case('multiple_choice') سؤال باختيارات يختار منها اللاعب. @break
                                @case('image') سؤال مرفق بصورة وجواب كتابي. @break
                                @case('sequence') عناصر يرتّبها اللاعب بالترتيب الصحيح. @break
                                @case('memory') لعبة مطابقة أزواج البطاقات. @break
                            @endswitch
                        </span>
                    </a>
                @endforeach
            </div>
            <p class="text-xs text-slate-500">أنواع أخرى (مثل لعبة الفروق) غير متاحة للمبدعين حاليًا.</p>
        @else
            <form method="POST" action="{{ route('creator.puzzles.store') }}" enctype="multipart/form-data" class="glass rounded-3xl p-6 space-y-5 anim-fade-up">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <p class="text-sm font-bold text-amethyst">{{ $kinds[$kind]['label'] }}</p>
                @include('creator.puzzles._fields', ['kind' => $kind, 'values' => [], 'categories' => $categories, 'kindConfig' => $kinds[$kind]])
                @error('kind') <p class="text-xs text-rose" role="alert">{{ $message }}</p> @enderror
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-400">أحجيات المجتمع بلا جوائز اقتصادية، وتحتاج موافقة المراجع قبل ظهورها.</div>
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="btn-gem !px-8">حفظ كمسودة</button>
                    <a href="{{ route('creator.puzzles.create') }}" class="btn-secondary">تغيير النوع</a>
                </div>
            </form>
        @endif
    </div>
@endsection
