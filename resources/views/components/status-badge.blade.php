@props(['tone' => 'neutral', 'icon' => null, 'small' => false, 'dashed' => false])

{{--
    E23: شارة حالة موحَّدة لكل الموقع (قريبًا/مباشر/مكتمل/ملغى/قيد الانتظار/مقبول/مقفل ...). عرض فقط: النبرة يحدّدها المستدعي دون تغيير دلالة أي حالة ولا نصّها.
    النبرات: neutral (انتهى/ملغى/مقفل) · primary (متاح) · success (مباشر/مقبول/مكتمل) · warning (قريبًا/قيد الانتظار) · danger (مرفوض/خطأ).
    لا نعتمد اللون وحده: النص موجود دائمًا والأيقونة اختيارية. الألوان من أصناف الثيم (يعيد الثيم الفاتح تعريفها فتبقى مقروءة). بلا تحويم (ليست زرًّا).
    يحل محل .chip عند استعماله كشارة غير تفاعلية، وأصناف الألوان القديمة (amber/cyan بلا بديل فاتح) والمكوّن القديم badge (ألوان ثابتة).
--}}
@php
    $tones = [
        'neutral' => 'border-white/10 bg-white/5 text-slate-300',
        'primary' => 'border-amethyst/30 bg-amethyst/15 text-amethyst',
        'success' => 'border-emerald/30 bg-emerald/10 text-emerald',
        'warning' => 'border-gold/30 bg-gold/10 text-gold',
        'danger' => 'border-rose/30 bg-rose/10 text-rose',
    ];
    $size = $small ? 'px-2 py-0.5 text-[11px]' : 'px-3 py-1 text-xs';
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border font-black leading-5 shrink-0', $size, $tones[$tone] ?? $tones['neutral'], $dashed ? 'border-dashed' : '']) }}>
    @if ($icon)<x-ui-icon :name="$icon" class="h-3.5 w-3.5 shrink-0" />@endif{{ $slot }}
</span>
