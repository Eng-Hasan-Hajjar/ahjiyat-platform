{{--
    E45: زر «تطوّر AI» العائم (المساعد الذكي لمنصة الأحجيات). لا يُعرَض إلا إن كان المساعد مفعّلًا للمشاهد (مفتاح شامل + ضيوف + غير مجمَّد): بقراءة إعداد واحدة بلا استعلام قاعدة بيانات.
    هنا الزر فقط؛ اللوحة وكل منطقها (JavaScript كسول يُحمَّل عند أول تفاعل) تُبنى بـtextContent حصرًا، فلا HTML خام. الأيقونة SVG مملوكة بالكود (بلا أصول خارجية).
    الموضع: أسفل يسار الشاشة على سطح المكتب (left فيزيائي)، وفوق التنقل السفلي بالجوال للمصادَق (--app-bottom-nav-h).
--}}
@php($twVisible = \App\Services\AiAssistant\TatawwurSettings::widgetVisibleFor(auth()->user()))
@if ($twVisible)
    <div data-tatawwur-widget class="tw-root {{ auth()->check() ? 'tw-has-nav' : '' }}"
        data-state-url="{{ route('tatawwur.state', [], false) }}"
        data-messages-url="{{ route('tatawwur.messages.store', [], false) }}"
        data-history-url="{{ route('tatawwur.history.destroy', [], false) }}"
        data-feedback-url="{{ route('tatawwur.feedback.store', [], false) }}"
        data-csrf="{{ csrf_token() }}">
        <button type="button" data-tatawwur-launcher class="tw-launcher" aria-haspopup="dialog" aria-expanded="false" aria-label="تطوّر AI، المساعد الذكي لمنصة الأحجيات">
            <svg class="tw-mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                <path d="M16 4 6 11l10 2z" fill="#c4b5fd" />
                <path d="M16 4l10 7-10 2z" fill="#a78bfa" />
                <path d="M6 11l10 2v16z" fill="#7c3aed" />
                <path d="M26 11l-10 2v16z" fill="#6d28d9" />
                <path d="M26 1.5l1.1 2.9 2.9 1.1-2.9 1.1L26 9.5l-1.1-2.9L22 5.5l2.9-1.1z" fill="#fcd34d" />
            </svg>
            <span class="tw-launcher-label">تطوّر AI</span>
        </button>
    </div>
@endif
