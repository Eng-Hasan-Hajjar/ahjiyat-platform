@extends('layouts.app')

@section('title', 'سياسة الخصوصية')

@section('content')

    @php
        $sections = [
            'collect'   => 'البيانات التي نجمعها',
            'use'       => 'كيف نستخدم بياناتك',
            'fraud'     => 'بيانات مكافحة الاحتيال',
            'storage'   => 'التخزين والأمان',
            'sharing'   => 'مشاركة البيانات',
            'rights'    => 'حقوقك على بياناتك',
            'cookies'   => 'الجلسات وملفات تعريف الارتباط',
            'children'  => 'خصوصية القُصّر',
            'changes'   => 'تعديل السياسة',
            'contact'   => 'التواصل معنا',
        ];
    @endphp

    <div class="anim-fade-up mb-8">
        <span class="chip !py-1 !px-3 mb-4 inline-block">آخر تحديث: {{ now()->format('Y-m-d') }}</span>
        <h1 class="font-display font-black text-3xl md:text-4xl text-gradient-gem mb-3">سياسة الخصوصية</h1>
        <p class="text-slate-400 max-w-2xl">
            نوضّح في هذه الصفحة ما هي البيانات التي تجمعها منصة أحجيات، ولماذا، وكيف نحميها.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

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

        <div class="lg:col-span-3 space-y-6">

            <section id="collect" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">1. البيانات التي نجمعها</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>بيانات الحساب: الاسم، البريد الإلكتروني، كلمة المرور (مخزّنة بشكل مشفّر).</li>
                    <li>بيانات النشاط: الأحجيات التي حللتها، محاولاتك، رصيدك من الجواهر، وطلبات الاستبدال.</li>
                    <li>بيانات فنية محدودة لأغراض الأمان: عنوان الـ IP، وبصمة جهاز/متصفح مبسّطة، ووقت آخر ظهور لك على المنصة.</li>
                </ul>
            </section>

            <section id="use" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">2. كيف نستخدم بياناتك</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>لإدارة حسابك وتوثيقه، وتشغيل نظام الجواهر بشكل صحيح.</li>
                    <li>لمعالجة طلبات الاستبدال والتواصل معك بخصوصها.</li>
                    <li>لعرض اسمك في لوحة الصدارة العامة إن كنت من أفضل الحلّالين (الاسم فقط، دون بيانات حساسة أخرى).</li>
                    <li>لتحسين المنصة وتحليل الاستخدام العام بشكل مجمّع وغير شخصي.</li>
                </ul>
            </section>

            <section id="fraud" class="puzzle-card anim-fade-up d-3">
                <h2 class="font-display font-black text-xl text-white mb-3">3. بيانات مكافحة الاحتيال</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    لحماية نظام الجواهر من الاستغلال، تُسجّل المنصة تلقائيًا عنوان الـ IP وبصمة جهاز مبسّطة (مشتقة من متصفحك)
                    عند تفاعلك مع المنصة، وتُستخدم فقط لاكتشاف الحسابات المتعددة أو الأنماط غير الطبيعية في كسب الجواهر،
                    ولا تُستخدم لأي غرض تسويقي.
                </p>
            </section>

            <section id="storage" class="puzzle-card anim-fade-up d-4">
                <h2 class="font-display font-black text-xl text-white mb-3">4. التخزين والأمان</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>كلمات المرور مخزّنة بتشفير أحادي الاتجاه (hash) ولا يمكن لأي موظف الاطلاع عليها كنص واضح.</li>
                    <li>جميع العمليات المالية على المحفظة (إضافة/خصم جواهر) مسجّلة في سجل عمليات دائم لأغراض التدقيق.</li>
                    <li>الوصول للوحة الإدارة مقصور على المخوّلين فقط، مع فصل صلاحيات واضح.</li>
                </ul>
            </section>

            <section id="sharing" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">5. مشاركة البيانات</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    لا تبيع المنصة بياناتك لأي جهة خارجية. قد تتم مشاركة أقل قدر ممكن من البيانات مع مزوّدي خدمات تقنية
                    ضروريين لتشغيل المنصة (مثل الاستضافة أو إرسال البريد الإلكتروني)، ووفق التزامهم بالحفاظ على سريّتها.
                </p>
            </section>

            <section id="rights" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">6. حقوقك على بياناتك</h2>
                <ul class="list-disc pr-5 space-y-2 text-slate-300 text-sm leading-relaxed">
                    <li>يمكنك تعديل بيانات حسابك في أي وقت من صفحة "ملفي الشخصي".</li>
                    <li>يمكنك طلب حذف حسابك وبياناتك عبر التواصل مع فريق الدعم.</li>
                    <li>قد نحتفظ ببعض سجلات المعاملات (مثل سجل الجواهر) للحد الأدنى المطلوب لأغراض تدقيقية حتى بعد حذف الحساب.</li>
                </ul>
            </section>

            <section id="cookies" class="puzzle-card anim-fade-up d-3">
                <h2 class="font-display font-black text-xl text-white mb-3">7. الجلسات وملفات تعريف الارتباط</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    تستخدم المنصة ملف تعريف ارتباط (cookie) واحد أساسي للحفاظ على تسجيل دخولك بأمان بين الصفحات،
                    ولا تُستخدم أي ملفات تعريف ارتباط لأغراض إعلانية أو تتبّع خارجي.
                </p>
            </section>

            <section id="children" class="puzzle-card anim-fade-up d-4">
                <h2 class="font-display font-black text-xl text-white mb-3">8. خصوصية القُصّر</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    المنصة موجّهة لجمهور عام، ونوصي بإشراف ولي الأمر عند استخدام المنصة من قِبل من هم دون السن القانوني
                    في بلدهم، خصوصًا فيما يتعلق بطلبات الاستبدال.
                </p>
            </section>

            <section id="changes" class="puzzle-card anim-fade-up d-1">
                <h2 class="font-display font-black text-xl text-white mb-3">9. تعديل السياسة</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    قد تُحدَّث هذه السياسة بين حين وآخر لمواكبة تطوّر المنصة، وسيظهر تاريخ آخر تحديث أعلى الصفحة دائمًا.
                </p>
            </section>

            <section id="contact" class="puzzle-card anim-fade-up d-2">
                <h2 class="font-display font-black text-xl text-white mb-3">10. التواصل معنا</h2>
                <p class="text-slate-300 text-sm leading-relaxed">
                    لأي استفسار يتعلق ببياناتك أو رغبتك بحذف حسابك، تواصل معنا عبر البريد الإلكتروني الموضّح في صفحة الدعم بالمنصة.
                </p>
            </section>

            <p class="text-xs text-slate-500 leading-relaxed pt-2">
                هذه السياسة مبدئية وقد تحتاج مراجعة من مختص قانوني بما يتوافق مع نظام حماية البيانات بالدولة المستهدفة قبل الإطلاق الرسمي.
            </p>

        </div>
    </div>

@endsection