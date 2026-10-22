@php
    /**
     * E36: نموذج كتابة نص عادي (تعليق/رد/تعديل). نص فقط: لا HTML ولا Markdown ولا روابط قابلة للنقر ولا @إشارات.
     * @var string $action  @var string $method  @var string $id (معرّف فريد للصفحة)  @var string $label (تسمية القارئ الشاشي)
     * @var string $submit  @var string $value (قيمة ابتدائية)  @var int $max  @var ?string $error  @var string $placeholder
     * @var ?array{0: string, 1: string} $marker  حقل مخفي (reply_to|edit_id) يعيد فتح النموذج الصحيح بعد خطأ تحقق
     */
    $method = strtoupper($method ?? 'POST');
    $value = (string) ($value ?? '');
    $max = (int) ($max ?? 1000);
@endphp
<form method="POST" action="{{ $action }}" class="space-y-2" x-data="{ n: {{ mb_strlen($value) }} }">
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    @if (! empty($marker)) <input type="hidden" name="{{ $marker[0] }}" value="{{ $marker[1] }}"> @endif
    <div>
        <label for="{{ $id }}" class="sr-only">{{ $label }}</label>
        <textarea id="{{ $id }}" name="body" rows="3" maxlength="{{ $max }}" required dir="auto" autocomplete="off"
                  placeholder="{{ $placeholder ?? '' }}"
                  aria-describedby="{{ $id }}-hint{{ ! empty($error) ? ' '.$id.'-error' : '' }}"
                  @if (! empty($error)) aria-invalid="true" @endif
                  x-on:input="n = $event.target.value.length"
                  class="input-gem w-full resize-y min-h-[5rem]">{{ $value }}</textarea>
        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
            <span id="{{ $id }}-hint">نص عادي فقط (بلا روابط أو تنسيق) · الحدّ {{ $max }} حرفًا</span>
            <span aria-hidden="true" class="tabular-nums"><span x-text="n">{{ mb_strlen($value) }}</span>/{{ $max }}</span>
        </div>
        @if (! empty($error)) <p id="{{ $id }}-error" class="mt-1 text-xs font-bold text-rose" role="alert">{{ $error }}</p> @endif
    </div>
    <button type="submit" class="btn-gem !py-2 !px-5 text-sm inline-flex items-center gap-1.5"><x-ui-icon name="send" class="w-4 h-4" />{{ $submit }}</button>
</form>
