{{--
    معاينة حية بسيطة (Placeholder Content فقط) - لا Iframe لكل الموقع، فقط
    عناصر مصغَّرة تعكس الألوان الحالية بالنموذج (حتى قبل الحفظ).
--}}
<div style="background:#0b1226; border-radius: 1rem; padding: 1.25rem; border: 1px solid rgba(255,255,255,.1);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom: 1rem;">
        <span style="color:#fff; font-weight:900;">أحجيات</span>
        <span style="background:{{ $primary }}22; color:{{ $primary }}; border:1px solid {{ $primary }}55; border-radius:9999px; padding:.25rem .75rem; font-size:.75rem; font-weight:800;">
            شارة (Badge)
        </span>
    </div>

    <div style="background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:.9rem; padding:1rem; margin-bottom:1rem;">
        <p style="color:#cbd5e1; font-size:.85rem; margin:0 0 .5rem;">نص عادي داخل بطاقة (Card)</p>
        <span style="color:{{ $accent }}; font-weight:800; font-size:.85rem;">نص بلون التمييز (Accent)</span>
    </div>

    <button type="button" style="background:linear-gradient(135deg, {{ $primary }}, {{ $secondary }}); color:#fff; font-weight:800; border:none; border-radius:.85rem; padding:.65rem 1.4rem; cursor:default;">
        زر رئيسي (Button)
    </button>
</div>