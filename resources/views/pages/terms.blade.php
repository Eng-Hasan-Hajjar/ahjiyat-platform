@extends('layouts.app')

@section('title', 'شروط الاستخدام')

@section('content')

    @php
        $sections = [
            'account'      => 'الحساب والتسجيل',
            'gems'         => 'نظام الجواهر',
            'earning'      => 'كسب الجواهر',
            'redemption'   => 'الاستبدال والأهلية',
            'conduct'      => 'الاستخدام المقبول',
            'fraud'        => 'مكافحة الاحتيال وتجميد الحسابات',
            'content'      => 'الملكية والمحتوى',
            'liability'    => 'حدود المسؤولية',
            'changes'      => 'تعديل الشروط',
            'contact'      => 'التواصل معنا',
        ];
        $gems = config('gems');
    @endphp

    <div class="anim-fade-up mb-8">
        <span class="chip !py-1 !px-3 mb-4 inline-block">آخر تحديث: {{ now()->format('Y-m-d') }}</span>
        <h1 class="font-display font-black text-3xl md:text-4xl text-gradient-gem mb-3">شروط الاستخدام</h1>
        <p class="text-slate-400 max-w-2xl">
            هذه الشروط تنظّم استخدامك لمنصة أحجيات. بإنشائك حسابًا أو استخدامك للمنصة، فإنك توافق على ما يلي.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

        {{-- ===== فهرس جانبي (سطح المكتب) ===== --}}
        <nav class="hidden lg:block lg:col-span-1">
            <div class="glass rounded-2xl p-5 sticky top-24">
                <span class="text-xs font-black text-slate-500 uppercase tracking-widest block mb-3">المحتويات</span>
                <ul class="space-y-1 text-sm font-bold text-slate-400">
                    @foreach ($sections as $id => $label)
                        <li><a href="#{{ $id }}" class="block rounded-lg px-3 py-2 hover:bg-white/5 hover:text-white transition">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
        </nav>

        {{-- ===== المحتوى ===== --}}
        <div class="lg:col-span-3 space-y-6">

            <section id="account" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">1. الحساب والتسجيل</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>يجب أن تقدّم بيانات صحيحة عند إنشاء الحساب (الاسم والبريد الإلكتروني).</li>
                    <li>بعض ميزات المنصة (حل الأحجيات، طلب الاستبدال) تتطلب توثيق بريدك الإلكتروني أولاً.</li>
                    <li>أنت مسؤول عن سرّية بيانات الدخول لحسابك، وعن أي نشاط يتم من خلاله.</li>
                    <li>يُسمح بحساب واحد فقط لكل شخص. الحسابات المتعددة لنفس الشخص تُعامل كمحاولة احتيال.</li>
                </ul>
            </section>

            <section id="gems" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">2. نظام الجواهر</h2>
                <p class="text-slate-300 text-sm leading-relaxed mb-3">
                    "الجواهر" 💎 هي نقاط مكافآت داخلية تمنحها المنصة مقابل حل الأحجيات والمشاركة، وهي
                    <strong class="text-white">ليست عملة نقدية أو أداة دفع</strong>، وليس لها قيمة نقدية مباشرة خارج المنصة.
                    تُستخدم فقط وفق سياسة الاستبدال الموضحة أدناه، وتحتفظ المنصة بحق تعديل قيمتها أو آلية الحصول عليها في أي وقت.
                </p>
                <div class="rounded-xl bg-white/5 border border-white/10 px-4 py-3 text-sm text-slate-300">
                    كل {{ number_format($gems['per_redemption_unit']) }} جوهرة ≈ وحدة استبدال داخلية واحدة (تُحدَّد قيمتها الفعلية حسب سياسة المكافآت المعلنة وقتها).
                </div>
            </section>

            <section id="earning" class="puzzle-card anim-fade-up d-3">
                <h2 class="font-display font-black text-xl text-white mb-3">3. كسب الجواهر</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>تُمنح الجواهر عند حل أحجية بشكل صحيح، ضمن عدد المحاولات المسموح به لكل أحجية.</li>
                    <li>يوجد سقف يومي للجواهر المكتسبة ({{ $gems['daily_earn_cap'] }} جوهرة/يوم) لحماية المنصة من الاستغلال الآلي.</li>
                    <li>الجواهر المكتسبة حديثًا تكون <strong class="text-white">"معلّقة"</strong> لمدة {{ $gems['pending_hold_days'] }} أيام قبل أن تصبح متاحة للاستبدال.</li>
                    <li>استخدام التلميح داخل أحجية يكلّف {{ $gems['hint_cost'] }} جواهر من رصيدك المتاح.</li>
                    <li>أي محاولة للتلاعب بآلية الكسب (نصوص آلية، حسابات وهمية...) تُلغي الجواهر الناتجة وتُعرّض الحساب للتجميد.</li>
                </ul>
            </section>

            <section id="redemption" class="puzzle-card anim-fade-up d-4">
                <h2 class="font-display font-black text-xl text-white mb-3">4. الاستبدال والأهلية</h2>
                <p class="text-slate-300 text-sm leading-relaxed mb-3">للتقدّم بطلب استبدال، يجب أن تتوفر الشروط التالية:</p>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>توثيق البريد الإلكتروني.</li>
                    <li>مرور {{ $gems['eligibility']['min_account_age_days'] }} أيام على الأقل على إنشاء الحساب.</li>
                    <li>حل {{ $gems['eligibility']['min_puzzles_solved'] }} أحجيات على الأقل بنجاح.</li>
                    <li>عدم وجود علامات احتيال قائمة على الحساب.</li>
                    <li>الوصول لرصيد متاح لا يقل عن {{ number_format($gems['min_redemption']) }} جوهرة.</li>
                </ul>
                <p class="text-slate-400 text-sm leading-relaxed mt-3">
                    تتم مراجعة طلبات الاستبدال يدويًا من فريق المنصة، وقد تُقبل أو تُرفض حسب الضوابط المعمول بها،
                    وفي حال الرفض تُعاد الجواهر المحجوزة إلى رصيدك المتاح تلقائيًا.
                </p>
            </section>

            <section id="conduct" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">5. الاستخدام المقبول</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>يُمنع استخدام أي أدوات آلية (بوتات) لحل الأحجيات أو جمع الجواهر.</li>
                    <li>يُمنع محاولة الوصول غير المصرّح به لحسابات أو بيانات مستخدمين آخرين.</li>
                    <li>يُمنع نشر محتوى مسيء أو مخالف للأنظمة عبر أي ميزة تفاعلية بالمنصة.</li>
                    <li>تحتفظ إدارة المنصة بحق إيقاف أي حساب يخالف هذه الشروط.</li>
                </ul>
            </section>

            <section id="fraud" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">6. مكافحة الاحتيال وتجميد الحسابات</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    تراقب المنصة أنماط الاستخدام غير الطبيعية (مثل تعدد الحسابات من نفس الجهاز، أو معدلات كسب غير منطقية)
                    لحماية نظام المكافآت لجميع المستخدمين. عند رصد نشاط مشبوه، يجوز للمنصة تعليق الرصيد المرتبط، أو تجميد
                    الحساب مؤقتًا لحين المراجعة، أو رفض طلب استبدال قائم، دون الحاجة لإشعار مسبق في الحالات الواضحة.
                </p>
            </section>

            <section id="content" class="puzzle-card anim-fade-up d-3">
                <h2 class="font-display font-black text-xl text-white mb-3">7. الملكية والمحتوى</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    جميع الأحجيات والتصاميم والعلامة "أحجيات" مملوكة للمنصة، ولا يجوز نسخها أو إعادة نشرها تجاريًا دون إذن كتابي.
                </p>
            </section>

            <section id="liability" class="puzzle-card anim-fade-up d-4">
                <h2 class="font-display font-black text-xl text-white mb-3">8. حدود المسؤولية</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    تُقدَّم المنصة "كما هي"، وتُبذل جهود معقولة لضمان استقرارها ودقتها، لكن لا تضمن المنصة عدم انقطاع الخدمة
                    أو خلوّها التام من الأخطاء. الجواهر ومكافآتها تخضع لسياسة المنصة القابلة للتعديل، ولا تشكّل التزامًا ماليًا ثابتًا.
                </p>
            </section>

            <section id="changes" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">9. تعديل الشروط</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    يجوز تحديث هذه الشروط من وقت لآخر، وسيتم نشر أي تعديل جوهري على هذه الصفحة مع تاريخ التحديث.
                    استمرارك في استخدام المنصة بعد التعديل يُعد موافقة عليه.
                </p>
            </section>

            <section id="contact" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">10. التواصل معنا</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    لأي استفسار متعلق بهذه الشروط أو حسابك، يمكنك التواصل معنا عبر البريد الإلكتروني الموضّح في صفحة الدعم بالمنصة.
                </p>
            </section>

            <p class="text-xs text-slate-500 leading-relaxed pt-2">
                هذه الشروط مبدئية وقد تحتاج مراجعة من مختص قانوني بما يتوافق مع نظام الدولة المستهدفة قبل الإطلاق الرسمي.
            </p>

        </div>
    </div>

@endsection