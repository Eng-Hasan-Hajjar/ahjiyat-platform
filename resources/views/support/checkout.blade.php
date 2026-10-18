@extends('layouts.app')

@section('title', 'ادعم: '.$program->title)
@section('content_width', 'max-w-2xl')

{{--
    E32: دفع بثلاث خطوات (Blade + Alpine بلا SPA): 1) المبلغ والعملة · 2) طريقة الدفع والخصوصية · 3) التأكيد والتعليمات.
    الخادم يعيد التحقق من كل شيء (المبلغ/العملة/الطريقة/البرنامج من المسار)؛ ما يُعرض هنا للراحة فقط. بلا JavaScript تظهر الخطوات كلها متتابعة وتعمل.
    مفتاح عدم التكرار (request_key) يمنع إنشاء طلبين بنقرة مزدوجة. لا بيانات دفع هنا؛ التعليمات تظهر بعد إنشاء الطلب فقط.
--}}
@section('content')
    <div class="space-y-6">
        <x-page-header :title="'ادعم: '.$program->title" icon="gift" :back="route('support.program', $program->slug)" backLabel="البرنامج" class="anim-fade-up !mb-0" />

        @if (! $enabled)
            <x-empty-state icon="gift" title="الدفع غير متاح حاليًا" message="لا توجد طريقة دفع مفعّلة الآن. حاول لاحقًا." />
        @else
            <form method="POST" action="{{ route('support.checkout.store', $program->slug) }}" class="puzzle-card !p-6 md:!p-8 space-y-6 anim-fade-up"
                  x-data="{ step: 1, amount: @js(old('amount', '')), currency: @js($defaultCurrency), visibility: @js(old('visibility', 'public')), provider: @js(old('provider', array_key_first($methods))), methods: @js($methods),
                            get methodLabel() { return this.methods[this.provider]?.label ?? '' },
                            get currencyList() { return this.methods[this.provider]?.currencies ?? [] },
                            next() { if (this.step === 1 && !String(this.amount).trim()) { this.$refs.amount.focus(); return } this.step = Math.min(3, this.step + 1) },
                            prev() { this.step = Math.max(1, this.step - 1) },
                            submitting: false }"
                  x-on:submit="submitting = true">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">

                <ol class="flex items-center gap-2 text-xs font-bold" aria-label="خطوات الدفع">
                    <li :class="step >= 1 ? 'text-amethyst' : 'text-slate-500'">١ المبلغ</li>
                    <li aria-hidden="true" class="text-slate-600">‹</li>
                    <li :class="step >= 2 ? 'text-amethyst' : 'text-slate-500'">٢ الطريقة والخصوصية</li>
                    <li aria-hidden="true" class="text-slate-600">‹</li>
                    <li :class="step >= 3 ? 'text-amethyst' : 'text-slate-500'">٣ التأكيد</li>
                </ol>

                {{-- الخطوة 1: المبلغ والعملة --}}
                <section x-show="step === 1" aria-labelledby="step1-title" class="space-y-4">
                    <h2 id="step1-title" class="font-display font-black text-white">المبلغ والعملة</h2>

                    @if ($suggestions !== [])
                        <div class="flex flex-wrap gap-2" role="group" aria-label="مبالغ مقترحة">
                            @foreach ($suggestions as $s)
                                <button type="button" class="chip !py-1.5 !px-3 text-xs" x-on:click="amount = '{{ $s['input'] }}'" :class="String(amount) === '{{ $s['input'] }}' ? 'chip-active' : ''">{{ $s['label'] }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label for="amount" class="block text-sm font-bold text-slate-300 mb-2">المبلغ</label>
                            <input id="amount" x-ref="amount" type="text" inputmode="decimal" autocomplete="off" name="amount" x-model="amount" required maxlength="20"
                                   @unless ($allowCustom) readonly @endunless class="input-gem" placeholder="مثال: 25">
                            @if ($minLabel)<span class="text-xs text-slate-500 mt-1.5 block">الحد الأدنى: {{ $minLabel }}</span>@endif
                        </div>
                        <div>
                            <label for="currency" class="block text-sm font-bold text-slate-300 mb-2">العملة</label>
                            <select id="currency" name="currency" x-model="currency" required class="input-gem">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($defaultCurrency === $code)>{{ $currencyOptions[$code] ?? $code }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end"><button type="button" class="btn-gem" x-on:click="next()">التالي</button></div>
                </section>

                {{-- الخطوة 2: طريقة الدفع والخصوصية --}}
                <section x-show="step === 2" x-cloak aria-labelledby="step2-title" class="space-y-4">
                    <h2 id="step2-title" class="font-display font-black text-white">طريقة الدفع والخصوصية</h2>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-bold text-slate-300 mb-1">طريقة الدفع</legend>
                        @foreach ($methods as $key => $method)
                            <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                                <input type="radio" name="provider" value="{{ $key }}" x-model="provider" @checked(old('provider', array_key_first($methods)) === $key)>
                                <span class="font-bold text-white">{{ $method['label'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-bold text-slate-300 mb-1">كيف تريد أن يظهر دعمك؟</legend>
                        <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                            <input type="radio" name="visibility" value="public" x-model="visibility" @checked(old('visibility', 'public') === 'public')>
                            <span class="font-bold text-white">أظهر اسمي علنًا</span>
                        </label>
                        <label class="flex items-center gap-3 glass rounded-xl px-4 py-3 cursor-pointer">
                            <input type="radio" name="visibility" value="anonymous" x-model="visibility" @checked(old('visibility') === 'anonymous')>
                            <span class="font-bold text-white">أدعم بشكل مجهول</span>
                        </label>
                        <p class="text-xs text-slate-500">الدعم المجهول لا يظهر اسمك في أي صفحة عامة. تحتفظ الإدارة بهوية الداعم داخليًا للتحقق فقط.</p>
                    </fieldset>

                    <div x-show="visibility === 'public'">
                        <label for="display_name" class="block text-sm font-bold text-slate-300 mb-2">الاسم المعروض</label>
                        <input id="display_name" type="text" name="display_name" maxlength="{{ (int) config('payments.checkout.display_name_max', 80) }}" value="{{ old('display_name', $defaultName) }}" class="input-gem">
                    </div>

                    <div class="flex justify-between"><button type="button" class="btn-secondary" x-on:click="prev()">السابق</button><button type="button" class="btn-gem" x-on:click="next()">التالي</button></div>
                </section>

                {{-- الخطوة 3: التأكيد --}}
                <section x-show="step === 3" x-cloak aria-labelledby="step3-title" class="space-y-4">
                    <h2 id="step3-title" class="font-display font-black text-white">التأكيد والتعليمات</h2>
                    <dl class="glass rounded-xl p-4 text-sm space-y-2">
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">البرنامج</dt><dd class="font-bold text-white text-end break-words">{{ $program->title }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">المبلغ</dt><dd class="font-bold text-white" x-text="(amount || '—') + ' ' + currency"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">الطريقة</dt><dd class="font-bold text-white" x-text="methodLabel"></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-400">الظهور</dt><dd class="font-bold text-white" x-text="visibility === 'anonymous' ? 'مجهول' : 'باسمي'"></dd></div>
                    </dl>
                    <p class="text-xs text-slate-400">سيُنشأ طلب دعم برمز مرجع. ستظهر لك تعليمات التحويل في الصفحة التالية. <strong class="text-white">لا يُحتسب الدعم قبل تحقق الفريق من التحويل.</strong></p>
                    <div class="flex justify-between">
                        <button type="button" class="btn-secondary" x-on:click="prev()">السابق</button>
                        <button type="submit" class="btn-gem" :disabled="submitting">إنشاء طلب الدعم</button>
                    </div>
                </section>
            </form>
        @endif
    </div>
@endsection
