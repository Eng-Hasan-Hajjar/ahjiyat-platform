@extends('layouts.app')

@section('title', 'شروط الاستخدام - منصة إعمار')

@push('styles')
<style>
.policy-hero {
    background: linear-gradient(160deg, var(--primary) 0%, #1B5E35 100%);
    padding: 70px 0 50px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.policy-hero::before {
    content: '📋';
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
.policy-nav h6 { font-weight: 800; font-size: .9rem; color: var(--text-mid); margin-bottom: 14px; text-transform: uppercase; letter-spacing: .5px; }
.policy-nav a {
    display: block; padding: 9px 14px; border-radius: 8px;
    color: var(--text-mid); text-decoration: none; font-size: .88rem;
    transition: all .2s; margin-bottom: 2px; border-right: 3px solid transparent;
}
.policy-nav a:hover, .policy-nav a.active {
    background: var(--primary-pale); color: var(--primary);
    border-right-color: var(--primary); font-weight: 600;
}

.policy-card {
    background: #fff; border-radius: 16px;
    box-shadow: 0 2px 16px rgba(0,0,0,.06);
    border: 1px solid #eef2f7;
    padding: 36px 40px; margin-bottom: 24px;
}
.policy-card .section-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: var(--primary-pale); display: flex;
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
.policy-card ul li::before { content: '✓'; color: var(--primary); font-weight: 700; flex-shrink: 0; margin-top: 3px; }
.policy-card ul li.no-check::before { content: '✕'; color: #e53e3e; }

.highlight-box {
    background: linear-gradient(135deg, var(--primary-pale), #d4ead9);
    border-radius: 12px; padding: 18px 22px; margin: 20px 0;
    border-right: 4px solid var(--primary);
}
.highlight-box p { margin: 0; font-size: .92rem; color: #1B5E35; font-weight: 500; }

.warning-box {
    background: #fffbeb; border-radius: 12px;
    padding: 18px 22px; margin: 20px 0;
    border-right: 4px solid #F4A024;
}
.warning-box p { margin: 0; font-size: .92rem; color: #92400e; font-weight: 500; }

.update-chip {
    display: inline-block; background: var(--primary-pale);
    color: var(--primary); border-radius: 50px;
    padding: 3px 12px; font-size: .8rem; font-weight: 600;
}
</style>
@endpush

@section('content')

{{-- Hero --}}
<section class="policy-hero">
    <div class="container">
        <div class="policy-badge">
            <i class="bi bi-shield-check-fill"></i> وثيقة قانونية
        </div>
        <h1>شروط الاستخدام</h1>
        <p>يُرجى قراءة هذه الشروط بعناية قبل استخدام منصة إعمار</p>
    </div>
</section>

<section class="py-5" style="background:#f7f9fc;">
    <div class="container">
        <div class="row g-4">

            {{-- Sidebar Navigation --}}
            <div class="col-lg-3 d-none d-lg-block">
                <div class="policy-nav">
                    <h6>المحتويات</h6>
                    <a href="#intro">مقدمة</a>
                    <a href="#definitions">التعريفات</a>
                    <a href="#account">حسابات المستخدمين</a>
                    <a href="#volunteer-rules">قواعد التطوع</a>
                    <a href="#owner-rules">قواعد أصحاب المشاريع</a>
                    <a href="#prohibited">المحظورات</a>
                    <a href="#content">المحتوى والملكية الفكرية</a>
                    <a href="#liability">حدود المسؤولية</a>
                    <a href="#termination">إنهاء الخدمة</a>
                    <a href="#changes">التعديلات</a>
                    <a href="#contact">التواصل</a>
                </div>
            </div>

            {{-- Content --}}
            <div class="col-lg-9">

                {{-- Last update --}}
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="update-chip"><i class="bi bi-calendar3 me-1"></i> آخر تحديث: يناير 2026</span>
                    <span class="update-chip"><i class="bi bi-translate me-1"></i> النسخة 1.0</span>
                </div>

                {{-- 1. Intro --}}
                <div class="policy-card" id="intro">
                    <div class="section-icon">📖</div>
                    <h2>1. مقدمة وقبول الشروط</h2>
                    <p>مرحباً بكم في <strong>منصة إعمار</strong>، المنصة الإلكترونية المتخصصة في تنظيم العمل التطوعي لإعادة إعمار المشاريع الصغيرة المتضررة. تُمثّل هذه الوثيقة الاتفاقية القانونية الملزمة بين المنصة وجميع مستخدميها.</p>
                    <p>بمجرد إنشائك حساباً على المنصة أو استخدامك لأي من خدماتها، فإنك تُقرّ بأنك قد قرأت هذه الشروط وفهمتها ووافقت على الالتزام بها.</p>
                    <div class="highlight-box">
                        <p><i class="bi bi-info-circle-fill me-2"></i> إذا كنت لا توافق على أي بند من هذه الشروط، يُرجى التوقف عن استخدام المنصة وحذف حسابك.</p>
                    </div>
                </div>

                {{-- 2. Definitions --}}
                <div class="policy-card" id="definitions">
                    <div class="section-icon">📌</div>
                    <h2>2. التعريفات</h2>
                    <ul>
                        <li><strong>المنصة:</strong> موقع منصة إعمار الإلكتروني وجميع خدماته.</li>
                        <li><strong>المستخدم:</strong> أي شخص يُسجّل حساباً أو يتصفح المنصة.</li>
                        <li><strong>المتطوع:</strong> مستخدم مسجّل يتقدم للمشاركة في مشاريع إعادة الإعمار.</li>
                        <li><strong>صاحب المشروع:</strong> مستخدم يمتلك مشروعاً متضرراً ويطلب مساعدة المتطوعين.</li>
                        <li><strong>المشروع:</strong> أي نشاط تعمير مسجّل على المنصة ومعتمد من المشرف.</li>
                        <li><strong>المشرف:</strong> إدارة المنصة المسؤولة عن الإشراف والاعتماد.</li>
                        <li><strong>المحتوى:</strong> أي نص أو صورة أو معلومة يرفعها المستخدمون.</li>
                    </ul>
                </div>

                {{-- 3. Accounts --}}
                <div class="policy-card" id="account">
                    <div class="section-icon">👤</div>
                    <h2>3. حسابات المستخدمين</h2>
                    <p>لاستخدام جميع خدمات المنصة يجب إنشاء حساب وفق الشروط التالية:</p>
                    <ul>
                        <li>يجب ألا يقل عمر المستخدم عن 16 سنة.</li>
                        <li>يجب تقديم معلومات صحيحة ودقيقة عند التسجيل.</li>
                        <li>أنت مسؤول مسؤولية كاملة عن سرية كلمة مرورك.</li>
                        <li>يُمنع منعاً باتاً إنشاء أكثر من حساب واحد لكل شخص.</li>
                        <li>يجب إخطارنا فوراً في حال اشتباهك باختراق حسابك.</li>
                        <li>يحق للإدارة تعليق أي حساب يُخالف الشروط دون إشعار مسبق.</li>
                    </ul>
                </div>

                {{-- 4. Volunteer Rules --}}
                <div class="policy-card" id="volunteer-rules">
                    <div class="section-icon">🤝</div>
                    <h2>4. قواعد المتطوعين</h2>
                    <p>يلتزم المتطوع المسجّل على المنصة بما يلي:</p>
                    <ul>
                        <li>تقديم بيانات حقيقية عن مهاراته وخبراته وتوافره الزمني.</li>
                        <li>الاحترام التام لصاحب المشروع والمتطوعين الآخرين.</li>
                        <li>إخطار صاحب المشروع مسبقاً في حال تعذّر الحضور أو التأخر.</li>
                        <li>إنجاز المهام المعيّنة في المواعيد المتفق عليها بأفضل مستوى.</li>
                        <li>الحفاظ على سرية أي معلومات خاصة بالمشاريع والأصحابها.</li>
                        <li>الامتناع عن طلب أي مقابل مادي من أصحاب المشاريع.</li>
                    </ul>
                    <div class="warning-box">
                        <p><i class="bi bi-exclamation-triangle-fill me-2"></i> التطوع على منصة إعمار عمل إنساني خيري بالكامل، ولا يترتب عليه أي التزام قانوني أو مالي من أي طرف.</p>
                    </div>
                </div>

                {{-- 5. Owner Rules --}}
                <div class="policy-card" id="owner-rules">
                    <div class="section-icon">🏪</div>
                    <h2>5. قواعد أصحاب المشاريع</h2>
                    <p>يلتزم صاحب المشروع بما يلي:</p>
                    <ul>
                        <li>تقديم معلومات صادقة ودقيقة عن حجم الضرر واحتياجات المشروع.</li>
                        <li>احترام المتطوعين ومعاملتهم بكرامة واحترام.</li>
                        <li>توفير بيئة عمل آمنة للمتطوعين طوال فترة العمل.</li>
                        <li>التواصل الفعّال مع المتطوعين وتوفير ما يحتاجونه من توجيه.</li>
                        <li>تحديث نسبة تقدم المشروع بصورة منتظمة ودقيقة.</li>
                        <li>الإبلاغ عن أي مشكلات تواجه المتطوعين للإدارة فور وقوعها.</li>
                        <li>عدم استغلال المتطوعين في أعمال خارج نطاق المشروع المعلن.</li>
                    </ul>
                </div>

                {{-- 6. Prohibited --}}
                <div class="policy-card" id="prohibited">
                    <div class="section-icon">🚫</div>
                    <h2>6. المحظورات</h2>
                    <p>يُحظر على جميع مستخدمي المنصة القيام بالأفعال الآتية:</p>
                    <ul>
                        <li class="no-check">نشر محتوى مسيء أو تمييزي أو مخالف للآداب العامة.</li>
                        <li class="no-check">انتحال شخصية أي مستخدم آخر أو جهة رسمية.</li>
                        <li class="no-check">استخدام المنصة لأغراض تجارية أو ربحية غير مصرّح بها.</li>
                        <li class="no-check">محاولة اختراق النظام أو الوصول غير المصرّح به إلى بيانات الآخرين.</li>
                        <li class="no-check">نشر أي روابط أو برمجيات ضارة أو احتيالية.</li>
                        <li class="no-check">جمع بيانات المستخدمين الآخرين دون إذن صريح.</li>
                        <li class="no-check">استخدام أدوات أتمتة أو Bots للتفاعل مع المنصة.</li>
                        <li class="no-check">إنشاء مشاريع وهمية أو تقديم معلومات مضللة.</li>
                    </ul>
                </div>

                {{-- 7. Content --}}
                <div class="policy-card" id="content">
                    <div class="section-icon">📸</div>
                    <h2>7. المحتوى والملكية الفكرية</h2>
                    <p>فيما يخص المحتوى المنشور على المنصة:</p>
                    <ul>
                        <li>تظل حقوق ملكية المحتوى الذي ترفعه لك وحدك.</li>
                        <li>بنشر المحتوى تمنح المنصة ترخيصاً لعرضه لأغراض تشغيل الخدمة.</li>
                        <li>لا يجوز نشر صور أو محتوى لأشخاص آخرين دون موافقتهم الصريحة.</li>
                        <li>شعار منصة إعمار وتصميماتها محمية بحقوق الملكية الفكرية.</li>
                        <li>يحق للإدارة حذف أي محتوى يُخالف هذه الشروط فوراً.</li>
                    </ul>
                </div>

                {{-- 8. Liability --}}
                <div class="policy-card" id="liability">
                    <div class="section-icon">⚖️</div>
                    <h2>8. حدود المسؤولية</h2>
                    <p>تُقدّم منصة إعمار خدماتها "كما هي" وتُخلي مسؤوليتها عن:</p>
                    <ul>
                        <li>أي أضرار تنشأ عن تفاعل المتطوعين مع أصحاب المشاريع مباشرة.</li>
                        <li>دقة المعلومات التي يقدمها المستخدمون عن مشاريعهم أو مهاراتهم.</li>
                        <li>أي خسائر ناتجة عن انقطاع الخدمة أو أعطال تقنية.</li>
                        <li>النزاعات التي تنشأ بين المتطوعين وأصحاب المشاريع.</li>
                    </ul>
                    <div class="highlight-box">
                        <p><i class="bi bi-shield-fill me-2"></i> دور المنصة هو التيسير والتنظيم فقط، ولا تتحمل مسؤولية أي ضرر جسدي أو مالي يلحق بأي طرف خلال تنفيذ أعمال التطوع.</p>
                    </div>
                </div>

                {{-- 9. Termination --}}
                <div class="policy-card" id="termination">
                    <div class="section-icon">🔚</div>
                    <h2>9. إنهاء الخدمة</h2>
                    <p>يحق لأي طرف إنهاء العلاقة وفق ما يلي:</p>
                    <ul>
                        <li>يحق لك حذف حسابك في أي وقت من صفحة الإعدادات.</li>
                        <li>يحق للإدارة إيقاف أو حذف أي حساب يُخالف الشروط.</li>
                        <li>عند حذف الحساب تُحذف بياناتك الشخصية خلال 30 يوماً.</li>
                        <li>تبقى بعض البيانات المجمّعة غير الشخصية للأغراض الإحصائية.</li>
                    </ul>
                </div>

                {{-- 10. Changes --}}
                <div class="policy-card" id="changes">
                    <div class="section-icon">🔄</div>
                    <h2>10. التعديلات على الشروط</h2>
                    <p>تحتفظ إدارة منصة إعمار بالحق في تعديل هذه الشروط في أي وقت. سيتم إخطارك بأي تعديلات جوهرية عبر:</p>
                    <ul>
                        <li>إشعار داخل المنصة يظهر عند تسجيل دخولك.</li>
                        <li>بريد إلكتروني يُرسَل على عنوانك المسجّل.</li>
                        <li>تحديث تاريخ "آخر تعديل" في أعلى هذه الصفحة.</li>
                    </ul>
                    <p>استمرار استخدامك للمنصة بعد نشر التعديلات يُعدّ قبولاً ضمنياً لها.</p>
                </div>

                {{-- 11. Contact --}}
                <div class="policy-card" id="contact">
                    <div class="section-icon">📬</div>
                    <h2>11. التواصل معنا</h2>
                    <p>لأي استفسار أو شكوى تتعلق بهذه الشروط، تواصل معنا عبر:</p>
                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <div style="background:var(--primary-pale);border-radius:12px;padding:16px;">
                                <div class="fw-bold mb-1" style="color:var(--primary);"><i class="bi bi-envelope-fill me-2"></i>البريد الإلكتروني</div>
                                <div style="font-size:.9rem;color:var(--text-mid);">support@emaar.sy</div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div style="background:var(--primary-pale);border-radius:12px;padding:16px;">
                                <div class="fw-bold mb-1" style="color:var(--primary);"><i class="bi bi-geo-alt-fill me-2"></i>العنوان</div>
                                <div style="font-size:.9rem;color:var(--text-mid);">جامعة حلب — كلية الهندسة المعلوماتية</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Back to top --}}
                <div class="text-center mt-2 mb-4">
                    <a href="#" class="btn btn-outline-success rounded-pill px-4">
                        <i class="bi bi-arrow-up-circle me-2"></i>العودة للأعلى
                    </a>
                    <a href="{{ route('privacy') }}" class="btn btn-success rounded-pill px-4 me-2">
                        <i class="bi bi-shield-lock me-2"></i>سياسة الخصوصية
                    </a>
                </div>

            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
// Highlight active nav item on scroll
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