{{--
    ضمانة "لا صورة معطَّلة أبداً" - يفحص وجود الملف فعلياً بالتخزين قبل رسم
    <img>/<audio>؛ إن لم يوجد، يعرض لوحة بديلة متناسقة مع الهوية بدل
    أيقونة كسر. عام تماماً - يُستخدم لأي CampaignStep media.
    الاستخدام: <x-media-or-placeholder :path="$path" type="image|audio"
        :caption="$caption" label="نص بديل عند الغياب" />
--}}
@props(['path', 'type' => 'image', 'caption' => null, 'label' => 'سيُضاف المحتوى الأصلي قريبًا'])

@php
    $exists = $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path);
@endphp

@if ($exists && $type === 'audio')
    <div class="my-4">
        <audio controls preload="none" class="w-full">
            <source src="{{ \Illuminate\Support\Facades\Storage::url($path) }}">
        </audio>
        @if ($caption)
            <p class="text-xs text-slate-500 mt-1">{{ $caption }}</p>
        @endif
    </div>
@elseif ($exists)
    <div class="rounded-2xl overflow-hidden border border-white/10 mb-4">
        <img src="{{ \Illuminate\Support\Facades\Storage::url($path) }}" alt="{{ $caption ?? '' }}" class="w-full h-auto">
    </div>
    @if ($caption)
        <p class="text-xs text-slate-500 mb-4">{{ $caption }}</p>
    @endif
@else
    <div class="media-placeholder mb-4">
        <span class="media-placeholder-icon">{{ $type === 'audio' ? '🎧' : '🖼️' }}</span>
        <span class="text-sm font-bold">{{ $label }}</span>
    </div>
@endif