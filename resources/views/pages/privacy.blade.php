@extends('layouts.app')

@section('title', 'سياسة الخصوصية - منصة إعمار')

@push('styles')
<style>
.policy-hero {
    background: linear-gradient(160deg, #1B3A6B 0%, #2E5090 100%);
    padding: 70px 0 50px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.policy-hero::before {
    content: '🔒';
    position: absolute; font-size: 14rem; opacity: .05;
    right: -40px; top: -30px; line-height: 1;
}
.policy-hero h1 { font-family:'Cairo',sans-serif; font-size: 2.4rem; font-weight: 900; margin-bottom: 10px; }
.policy-hero p  { opacity: .85; font-size: 1.05rem; margin: 0; }
.policy-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,.15); border-radius: 50px;
    padding: 6px 18px; font-size: .85rem; margin-bottom: 20px;
}

.policy-nav {
    position: sticky; top: 80px;
    background: #fff; border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    padding: 24px 20px; border: 1px solid #eef2f7;
}
.policy-nav h6 { font-weight: 800; font-size: .9rem; color: var(--text-mid); margin-bottom: 14px; }
.policy-nav a {
    display: block; padding: 9px 14px; border-radius: 8px;
    color: var(--text-mid); text-decoration: none; font-size: .88rem;
    transition: all .2s; margin-bottom: 2px; border-right: 3px solid transparent;
}
.policy-nav a:hover, .policy-nav a.active {
    background: #EEF2FF; color: #2E5090;
    border-right-color: #2E5090; font-weight: 600;
}

.policy-card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 2px 16px rgba(0,0,0,.06);
    border: 1px solid #eef2f7;
    padding: 36px 40px; margin-bottom: 24px;
}
.policy-card .section-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: #EEF2FF; display: flex;
    align-items: center; justify-content: center;
    font-size: 1.4rem; margin-bottom: 16px;
}
.policy-card h2 { font-family:'Cairo',sans-serif; font-size: 1.35rem; font-weight: 800; color: var(--text-dark); margin-bottom: 14px; }
.policy-card p  { color: var(--text-mid); line-height: 1.85; font-size: .95rem; margin-bottom: 12px; }
.policy-card ul { padding-right: 0; list-style: none; }
.policy-card ul li {
    display: flex; align-items: flex-start; gap: 10px;
    color: var(--text-mid); font-size: .93rem; line-height: 1.7;
    padding: 6px 0; border-bottom: 1px solid #f3f6fa;
}
.policy-card ul li:last-child { border-bottom: none; }
.policy-card ul li::before { content: '🔹'; flex-shrink: 0; margin-top: 2px; font-size: .85rem; }

.highlight-box {
    background: linear-gradient(135deg, #EEF2FF, #dde5ff);
    border-radius: 12px; padding: 18px 22px; margin: 20px 0;
    border-right: 4px solid #2E5090;
}
.highlight-box p { margin: 0; font-size: .92rem; color: #1e3a8a; font-weight: 500; }

.data-table { border-radius: 12px; overflow: hidden; }
.data-table thead th { background: #2E5090; color: #fff; font-weight: 700; font-size: .88rem; padding: 12px 16px; }
.data-table tbody td { padding: 10px 16px; font-size: .88rem; color: var(--text-mid); border-bottom: 1px solid #f0f4ff; vertical-align: middle; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover td { background: #f5f8ff; }

.right-badge { display: inline-block; background:#dcfce7; color:#16a34a; border-radius:50px; padding:2px 10px; font-size:.78rem; font-weight:600; }
.no-badge   { display: inline-block; background:#fee2e2; color:#dc2626; border-radius:50px; padding:2px 10px; font-size:.78rem; font-weight:600; }

.update-chip {
    display: inline-block; background: #EEF2FF;
    color: #2E5090; border-radius: 50px;
    padding: 3px 12px; font-size: .8rem; font-weight: 600;
}
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="policy-hero">
    <div class="container">
        <div class="policy-badge">
            <i class="bi bi-lock-fill"></i> حماية بياناتك أولويتنا
        </div>
        <h1>سياسة الخصوصية</h1>
        <p>نوضّح هنا كيف نجمع بياناتك ونستخدمها ونحميها</p>
    </div>
</section>

<section class="py-5" style="background:#f7f9fc;">
    <div class="container">
        <div class="row g-4">

            {{-- Sidebar --}}
            <div class="col-lg-3 d-none d-lg-block">
                <div class="policy-nav">
                    <h6>المحتويات</h6>
                    <a href="#commitment">التزامنا بخصوصيتك</a>
                    <a href="#data-collected">البيانات التي نجمعها</a>
                    <a href="#data-use">كيف نستخدم بياناتك</a>
                    <a href="#data-sharing">مشاركة البيانات</a>
                    <a href="#cookies">ملفات الارتباط</a>
                    <a href="#security">الأمان والحماية</a>
                    <a href="#retention">مدة الاحتفاظ</a>
                    <a href="#your-rights">حقوقك</a>
                    <a href="#children">خصوصية الأطفال</a>
                    <a href="#changes">تعديلات السياسة</a>
                    <a href="#contact">تواصل معنا</a>
                </div>
            </div>

            {{-- Content --}}
            <div class="col-lg-9">

                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="update-chip"><i class="bi bi-calendar3 me-1"></i> آخر تحديث: يناير 2026</span>
                    <span class="update-chip"><i class="bi bi-patch-check me-1"></i> النسخة 1.0</span>
                </div>

                {{-- 1. Commitment --}}
                <div class="policy-card" id="commitment">
                    <div class="section-icon">🛡️</div>
                    <h2>1. التزامنا بخصوصيتك</h2>
                    <p>تُدرك منصة إعمار أن خصوصيتك حق أساسي وليست مجرد التزام قانوني. نُصمّم خدماتنا منذ البداية مع مراعاة حماية بياناتك الشخصية (Privacy by Design).</p>
                    <p>تُطبّق المنصة معايير حماية البيانات وفق أفضل الممارسات الدولية، وتلتزم بمبادئ:</p>
                    <ul>
                        <li><strong>الشفافية:</strong> نُخبرك دائماً بما نجمعه ولماذا.</li>
                        <li><strong>الحد الأدنى من البيانات:</strong> نجمع فقط ما هو ضروري لتشغيل الخدمة.</li>
                        <li><strong>الغرض المحدد:</strong> لا نستخدم بياناتك لأغراض مختلفة عما أُخبرت به.</li>
                        <li><strong>الأمان:</strong> نحمي بياناتك بأحدث تقنيات التشفير.</li>
                        <li><strong>حقوقك:</strong> تملك السيطرة الكاملة على بياناتك في أي وقت.</li>
                    </ul>
                </div>

                {{-- 2. Data Collected --}}
                <div class="policy-card" id="data-collected">
                    <div class="section-icon">📥</div>
                    <h2>2. البيانات التي نجمعها</h2>
                    <p>نجمع البيانات التالية لتشغيل خدمات المنصة:</p>

                    <h6 class="fw-bold mt-3 mb-2" style="color:var(--text-dark);">أ) بيانات تُقدّمها أنت</h6>
                    <table class="table data-table">
                        <thead>
                            <tr>
                                <th>نوع البيانات</th>
                                <th>المثال</th>
                                <th>الإلزامية</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>معلومات الحساب</td><td>الاسم، البريد الإلكتروني، كلمة المرور</td><td><span class="right-badge">إلزامي</span></td></tr>
                            <tr><td>بيانات الملف الشخصي</td><td>المدينة، رقم الهاتف، الصورة الشخصية</td><td><span class="no-badge">اختياري</span></td></tr>
                            <tr><td>بيانات المتطوع</td><td>المهارات، النبذة الشخصية، ساعات التطوع</td><td><span class="no-badge">اختياري</span></td></tr>
                            <tr><td>بيانات المشروع</td><td>وصف المشروع، الموقع، الصور</td><td><span class="right-badge">إلزامي للأصحاب</span></td></tr>
                            <tr><td>رسائل الطلبات</td><td>رسائل التقدم للمشاريع</td><td><span class="no-badge">اختياري</span></td></tr>
                        </tbody>
                    </table>

                    <h6 class="fw-bold mt-4 mb-2" style="color:var(--text-dark);">ب) بيانات يجمعها النظام تلقائياً</h6>
                    <ul>
                        <li>عنوان IP وبيانات الجهاز والمتصفح المستخدم.</li>
                        <li>تاريخ ووقت تسجيل الدخول والخروج.</li>
                        <li>الصفحات التي تزورها والمدة التي تقضيها فيها.</li>
                        <li>سجل العمليات التي تنفّذها داخل المنصة.</li>
                    </ul>
                </div>

                {{-- 3. Data Use --}}
                <div class="policy-card" id="data-use">
                    <div class="section-icon">⚙️</div>
                    <h2>3. كيف نستخدم بياناتك</h2>
                    <p>نستخدم بياناتك حصراً للأغراض التالية:</p>
                    <ul>
                        <li>إنشاء حسابك وتمكينك من الدخول للمنصة.</li>
                        <li>مطابقتك بالمشاريع المناسبة لمهاراتك وموقعك.</li>
                        <li>إرسال الإشعارات المتعلقة بطلبات التطوع وتحديثات المشاريع.</li>
                        <li>احتساب النقاط والشارات وعرض لوحة المتصدرين.</li>
                        <li>تحسين أداء المنصة وتطوير ميزات جديدة.</li>
                        <li>الرد على استفساراتك وحل المشكلات التقنية.</li>
                        <li>إعداد إحصائيات مجمّعة (غير شخصية) عن نشاط المنصة.</li>
                    </ul>
                    <div class="highlight-box">
                        <p><i class="bi bi-lock-fill me-2"></i> <strong>لا نبيع بياناتك ولن نبيعها أبداً.</strong> بياناتك ليست منتجاً، إنها معلومات خاصة بك نؤتمن عليها.</p>
                    </div>
                </div>

                {{-- 4. Data Sharing --}}
                <div class="policy-card" id="data-sharing">
                    <div class="section-icon">🔗</div>
                    <h2>4. مشاركة البيانات مع الأطراف الثالثة</h2>
                    <p>لا نُشارك بياناتك الشخصية مع أطراف ثالثة إلا في الحالات المحدودة التالية:</p>
                    <ul>
                        <li><strong>مزودو الخدمات التقنية:</strong> استضافة الخوادم وخدمات البريد الإلكتروني، ملزَمون بعدم استخدام بياناتك لأغراضهم.</li>
                        <li><strong>المتطوعون وأصحاب المشاريع:</strong> يتاح اسمك وملفك الشخصي العام للطرف الآخر فقط بعد قبول طلب التطوع.</li>
                        <li><strong>متطلبات قانونية:</strong> في حال صدور أمر قضائي ملزم من جهة مختصة.</li>
                    </ul>
                </div>

                {{-- 5. Cookies --}}
                <div class="policy-card" id="cookies">
                    <div class="section-icon">🍪</div>
                    <h2>5. ملفات الارتباط (Cookies)</h2>
                    <p>تستخدم المنصة ملفات الارتباط للأغراض التالية:</p>
                    <table class="table data-table">
                        <thead>
                            <tr><th>نوع الكوكي</th><th>الغرض</th><th>المدة</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>الجلسة (Session)</td><td>الحفاظ على تسجيل دخولك</td><td>حتى إغلاق المتصفح</td></tr>
                            <tr><td>التذكّر (Remember Me)</td><td>البقاء مسجّلاً لفترة أطول</td><td>30 يوماً</td></tr>
                            <tr><td>CSRF Token</td><td>حماية نماذج الإدخال</td><td>مدة الجلسة</td></tr>
                            <tr><td>التفضيلات</td><td>حفظ إعداداتك داخل المنصة</td><td>سنة واحدة</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3">يمكنك التحكم في ملفات الارتباط من إعدادات متصفحك، لكن تعطيلها قد يؤثر على بعض وظائف المنصة.</p>
                </div>

                {{-- 6. Security --}}
                <div class="policy-card" id="security">
                    <div class="section-icon">🔐</div>
                    <h2>6. الأمان وحماية البيانات</h2>
                    <p>نُطبّق طبقات متعددة من الحماية لتأمين بياناتك:</p>
                    <ul>
                        <li>تشفير كلمات المرور باستخدام خوارزمية Bcrypt غير القابلة للعكس.</li>
                        <li>حماية جميع النماذج من هجمات CSRF بتوكن فريد لكل جلسة.</li>
                        <li>منع حقن SQL عبر استخدام Eloquent ORM مع Prepared Statements.</li>
                        <li>تهريب جميع المدخلات لمنع هجمات XSS.</li>
                        <li>نظام صلاحيات دقيق يمنع الوصول غير المصرّح به.</li>
                        <li>مراجعات أمنية دورية للكود والبنية التحتية.</li>
                    </ul>
                    <div class="highlight-box">
                        <p><i class="bi bi-exclamation-triangle-fill me-2"></i> في حال اكتشافك لأي ثغرة أمنية، يُرجى إبلاغنا فوراً على البريد: security@emaar.sy</p>
                    </div>
                </div>

                {{-- 7. Retention --}}
                <div class="policy-card" id="retention">
                    <div class="section-icon">🗓️</div>
                    <h2>7. مدة الاحتفاظ بالبيانات</h2>
                    <table class="table data-table">
                        <thead>
                            <tr><th>نوع البيانات</th><th>مدة الاحتفاظ</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>بيانات الحساب النشط</td><td>طوال فترة نشاط الحساب</td></tr>
                            <tr><td>بيانات الحساب المحذوف</td><td>تُحذف خلال 30 يوماً</td></tr>
                            <tr><td>سجلات النشاط</td><td>90 يوماً للأغراض الأمنية</td></tr>
                            <tr><td>البيانات المجمّعة الإحصائية</td><td>لأجل غير مسمى (بدون تعريف شخصي)</td></tr>
                            <tr><td>سجلات المشاريع المكتملة</td><td>سنتان بعد اكتمال المشروع</td></tr>
                        </tbody>
                    </table>
                </div>

                {{-- 8. Your Rights --}}
                <div class="policy-card" id="your-rights">
                    <div class="section-icon">⚖️</div>
                    <h2>8. حقوقك فيما يخص بياناتك</h2>
                    <p>تتمتع بالحقوق الكاملة التالية تجاه بياناتك الشخصية:</p>
                    <ul>
                        <li><strong>حق الاطلاع:</strong> طلب نسخة كاملة من جميع بياناتك المحفوظة.</li>
                        <li><strong>حق التصحيح:</strong> تعديل أي بيانات غير دقيقة مباشرةً من ملفك الشخصي.</li>
                        <li><strong>حق الحذف:</strong> طلب حذف حسابك وجميع بياناتك بشكل كامل.</li>
                        <li><strong>حق الاعتراض:</strong> رفض استخدام بياناتك لأغراض بعينها.</li>
                        <li><strong>حق النقل:</strong> الحصول على بياناتك بصيغة قابلة للقراءة آلياً.</li>
                        <li><strong>حق تقييد المعالجة:</strong> تجميد معالجة بياناتك في حالات معينة.</li>
                    </ul>
                    <p>لممارسة أي من هذه الحقوق، تواصل معنا عبر البريد: privacy@emaar.sy وسنستجيب خلال 15 يوم عمل.</p>
                </div>

                {{-- 9. Children --}}
                <div class="policy-card" id="children">
                    <div class="section-icon">👦</div>
                    <h2>9. خصوصية القاصرين</h2>
                    <p>منصة إعمار غير موجّهة للأشخاص دون سن 16 عاماً. إذا علمنا بأن قاصراً قد سجّل حساباً دون إذن وليّ أمره، سنقوم بحذف حسابه فوراً.</p>
                    <p>إذا كنت ولي أمر وتعتقد أن طفلك سجّل حساباً على منصتنا، يُرجى التواصل معنا على: privacy@emaar.sy</p>
                </div>

                {{-- 10. Changes --}}
                <div class="policy-card" id="changes">
                    <div class="section-icon">🔄</div>
                    <h2>10. تعديلات سياسة الخصوصية</h2>
                    <p>قد نُعدّل هذه السياسة من وقت لآخر. عند إجراء تعديلات جوهرية سنُخطرك عبر:</p>
                    <ul>
                        <li>إشعار بارز داخل المنصة عند تسجيل دخولك.</li>
                        <li>بريد إلكتروني على عنوانك المسجّل.</li>
                        <li>تحديث تاريخ "آخر تعديل" أعلى الصفحة.</li>
                    </ul>
                </div>

                {{-- 11. Contact --}}
                <div class="policy-card" id="contact">
                    <div class="section-icon">📬</div>
                    <h2>11. تواصل معنا بشأن الخصوصية</h2>
                    <p>لأي سؤال أو طلب يتعلق بخصوصيتك:</p>
                    <div class="row g-3 mt-1">
                        <div class="col-sm-4">
                            <div style="background:#EEF2FF;border-radius:12px;padding:16px;text-align:center;">
                                <div style="font-size:1.8rem;margin-bottom:8px;">📧</div>
                                <div class="fw-bold mb-1" style="color:#2E5090;font-size:.88rem;">الخصوصية</div>
                                <div style="font-size:.82rem;color:var(--text-mid);">privacy@emaar.sy</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div style="background:#EEF2FF;border-radius:12px;padding:16px;text-align:center;">
                                <div style="font-size:1.8rem;margin-bottom:8px;">🔒</div>
                                <div class="fw-bold mb-1" style="color:#2E5090;font-size:.88rem;">الأمان</div>
                                <div style="font-size:.82rem;color:var(--text-mid);">security@emaar.sy</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div style="background:#EEF2FF;border-radius:12px;padding:16px;text-align:center;">
                                <div style="font-size:1.8rem;margin-bottom:8px;">🏛️</div>
                                <div class="fw-bold mb-1" style="color:#2E5090;font-size:.88rem;">العنوان</div>
                                <div style="font-size:.82rem;color:var(--text-mid);">جامعة حلب</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Navigation buttons --}}
                <div class="text-center mt-2 mb-4">
                    <a href="#" class="btn btn-outline-primary rounded-pill px-4">
                        <i class="bi bi-arrow-up-circle me-2"></i>العودة للأعلى
                    </a>
                    <a href="{{ route('terms') }}" class="btn btn-primary rounded-pill px-4 me-2">
                        <i class="bi bi-file-text me-2"></i>شروط الاستخدام
                    </a>
                </div>

            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
const sections = document.querySelectorAll('[id]');
const navLinks = document.querySelectorAll('.policy-nav a');
window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(s => {
        if (window.scrollY >= s.offsetTop - 120) current = s.getAttribute('id');
    });
    navLinks.forEach(a => {
        a.classList.toggle('active', a.getAttribute('href') === '#' + current);
    });
});
</script>
@endpush