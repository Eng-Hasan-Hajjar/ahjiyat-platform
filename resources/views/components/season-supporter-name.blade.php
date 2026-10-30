@props(['tier' => null])

{{--
    E44 - اسم لاعب بنمط الداعم الموسمي (فضي/ذهبي/ماسي). **طبقة عرض مشتقّة فقط**: مفتاح النمط من قائمة بيضاء مغلقة يترجمه CSS الموقع (data-season-style)؛
    لا CSS حر ولا ألوان تُمرَّر من أي مدخل. لا يغيّر أي عنصر تجميلي مملوك ولا يعتمد على المخزون. الأولوية: ماسي > ذهبي > فضي > عادي.
    الاسم يبقى نصًا مُهرَّبًا عاديًا (الفتحة) والنمط لا يمنح أي أفضلية لعب.
--}}
@php $style = in_array($tier, (array) config('seasonal_recognition.supporters.name_styles', []), true) ? $tier : null; @endphp
<span {{ $attributes }} @if ($style) data-season-style="{{ $style }}" @endif>{{ $slot }}</span>
