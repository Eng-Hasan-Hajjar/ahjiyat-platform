<x-filament-panels::page>
    @php
        $d = $this->data();
        extract($d, EXTR_SKIP);
        $season = $d['season'];
        $setting = $d['setting'];
        $can = $d['can'];
        $input = 'rounded-lg border border-gray-300 dark:border-white/10 dark:bg-white/5 px-3 py-2 text-sm w-full';
        $btn = 'rounded-lg bg-primary-600 text-white px-3 py-1.5 text-sm font-bold disabled:opacity-50';
        $btn2 = 'rounded-lg border border-gray-300 dark:border-white/10 px-3 py-1.5 text-sm';
        $states = ['disabled' => 'معطَّل', 'not_configured' => 'غير مضبوط', 'upcoming' => 'لم يبدأ', 'open' => 'مفتوح', 'closed' => 'مُغلق', 'finalized' => 'مُنتهٍ'];
        $outcomes = ['first_place' => 'الأول', 'second_place' => 'الثاني', 'third_place' => 'الثالث', 'participant' => 'مشارك', 'ineligible_next_season' => 'خارج قائمة الموسم التالي'];
        $awardStatus = ['planned' => 'مخطّطة', 'granted' => 'ممنوحة', 'pending_finance' => 'بانتظار الصرف', 'settled' => 'مُسوّاة', 'cancelled' => 'ملغاة', 'failed' => 'فشل (قابلة لإعادة المحاولة)'];
        $money = fn (?int $m, ?string $c) => $m === null ? '—' : intdiv($m, 100).'.'.str_pad((string) ($m % 100), 2, '0', STR_PAD_LEFT).' '.$c;
        $tierNames = collect(config('seasonal_recognition.supporters.card_tiers'))->map(fn ($t) => $t['name']);
        $fmt = fn ($dt) => $dt ? $dt->format('Y-m-d H:i') : '—';
    @endphp

    <p class="text-xs text-gray-500">كتّاب الموسم: ترتيب بنجوم الجمهور (ميزانية 10 نجوم لكل حساب في الموسم). داعمو الموسم: نقاط دعم <strong>للتقدير فقط</strong> (ليست مالًا ولا جواهر ولا خبرة). الجوائز المالية التزام يُسوَّى يدويًا خارج النظام ولا يوجد دفع آلي ولا تحويل عملات.
        المفتاحان العامان: الكتّاب {{ $d['flags']['writers'] ? 'مُفعَّل' : 'معطَّل' }} · الداعمون {{ $d['flags']['supporters'] ? 'مُفعَّل' : 'معطَّل' }} (تعطيلهما يوقف الإجراءات الجديدة فقط ولا يمحو تاريخًا).</p>

    <div class="flex flex-wrap items-center gap-3">
        <label class="text-sm font-bold">الموسم
            <select wire:model.live="seasonId" class="{{ $input }} !w-auto min-w-[16rem]">
                @foreach ($d['seasons'] as $s)
                    <option value="{{ $s->id }}">{{ $s->campaign?->title ?? $s->slug }} ({{ $s->slug }})</option>
                @endforeach
            </select>
        </label>
        @if ($season)<span class="text-xs text-gray-500">حالة التصويت: <strong>{{ $states[$d['state']] ?? $d['state'] }}</strong></span>@endif
    </div>

    <div class="flex flex-wrap gap-2 border-b border-gray-200 dark:border-white/10 pb-2" role="tablist">
        @foreach (\App\Filament\Pages\SeasonalRecognitionPage::TABS as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                    class="px-3 py-1.5 text-sm rounded-lg {{ $tab === $key ? 'bg-primary-600 text-white font-bold' : 'border border-gray-300 dark:border-white/10' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if (! $season)
        <p class="text-gray-500">لا مواسم بعد.</p>
    @elseif ($tab === 'writers')
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-writers-settings>
            <div class="font-bold">إعدادات الدوري</div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="writersEnabled" @disabled(! $can['manage'])> تفعيل الدوري لهذا الموسم</label>
            <div class="grid md:grid-cols-4 gap-3">
                <label class="text-xs text-gray-500">السعة (1–100)<input type="number" min="1" max="100" wire:model="capacity" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">فتح التصويت<input type="datetime-local" wire:model="opensAt" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">إغلاق التصويت (قبل النهاية بـ7 أيام على الأقل)<input type="datetime-local" wire:model="closesAt" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">عدد «خارج الموسم التالي» (0–{{ (int) config('seasonal_recognition.writers.max_eliminated_count', 20) }})<input type="number" min="0" wire:model="eliminatedCount" class="{{ $input }}"></label>
            </div>
            <label class="text-xs text-gray-500 block">الموسم السابق المباشر (لتطبيق قاعدة الاستبعاد؛ فارغ = يُشتقّ زمنيًا)
                <select wire:model="previousSeasonId" class="{{ $input }}">
                    <option value="">— اشتقاق تلقائي —</option>
                    @foreach ($previousOptions ?? [] as $p)<option value="{{ $p->id }}">{{ $p->campaign?->title ?? $p->slug }}</option>@endforeach
                </select>
            </label>

            <div class="font-bold text-sm pt-2">خطة الجوائز (للمراكز الثلاثة)</div>
            <p class="text-xs text-gray-500">التجميلية: رقم عنصر متجر تجميلي قابل للتسليم كمخزون. المالية: مبلغ صحيح بالسنت (USD) كالتزام قابل للتدقيق يُصرف يدويًا. اترك البند فارغًا لتجاهله.</p>
            @foreach ($plan as $key => $row)
                @php [$placement, $kind] = explode('__', $key); @endphp
                <div class="grid md:grid-cols-4 gap-2 items-end">
                    <div class="text-xs"><strong>{{ $outcomes[$placement] }}</strong> — {{ $kind === 'cosmetic' ? 'تجميلية' : 'مالية' }}</div>
                    <input type="text" maxlength="120" wire:model="plan.{{ $key }}.label" placeholder="اسم الجائزة" class="{{ $input }}">
                    @if ($kind === 'cosmetic')
                        <input type="number" min="1" wire:model="plan.{{ $key }}.store_item_id" placeholder="رقم عنصر المتجر" class="{{ $input }}" dir="ltr">
                    @else
                        <input type="number" min="100" wire:model="plan.{{ $key }}.amount_minor" placeholder="المبلغ بالسنت" class="{{ $input }}" dir="ltr">
                    @endif
                </div>
            @endforeach
            @if ($can['manage'])<button type="button" wire:click="saveSettings" class="{{ $btn }}">حفظ الإعدادات</button>@endif
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2" data-readiness>
            <div class="font-bold">الجاهزية</div>
            <ul class="text-sm space-y-1">
                @foreach ($readiness['checks'] as $c)
                    <li>{{ $c['ok'] ? '✔' : ($c['required'] ? '✖' : '⚠') }} {{ $c['label'] }}@if (! $c['required']) <span class="text-xs text-gray-500">(تنبيه)</span>@endif</li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-roster>
            <div class="font-bold">القائمة ({{ $entries->count() }} / {{ (int) ($setting?->writers_capacity ?? config('seasonal_recognition.writers.default_capacity')) }})</div>
            @if ($can['manage'])
                <div class="flex gap-2"><input type="text" wire:model="creatorSlug" placeholder="معرّف المبدع (slug)" class="{{ $input }}" dir="ltr"><button type="button" wire:click="addWriter" class="{{ $btn }}">إضافة كاتب</button></div>
            @endif
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">الكاتب</th><th>الحالة</th><th>النجوم</th><th>السلاسل المربوطة</th><th></th></tr></thead>
                <tbody>
                @forelse ($entries as $e)
                    <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                        <td>{{ $e->writer_name }} <span class="text-xs text-gray-500" dir="ltr">{{ $e->writer_slug }}</span>@if (in_array($e->creator_profile_id, $rotation, true)) <span class="text-xs text-amber-600">(مستبعد هذا الموسم)</span>@endif</td>
                        <td class="text-center">{{ $e->status === 'active' ? 'نشط' : 'مسحوب' }}</td>
                        <td class="text-center">{{ number_format($e->stars_total) }} ({{ $e->voters_count }} ناخب)</td>
                        <td class="text-xs">
                            @foreach ($e->seriesLinks as $l)
                                <div>{{ $titles[$l->creator_series_id] ?? '#'.$l->creator_series_id }}@if ($can['manage']) <button type="button" wire:click="unlinkSeries('{{ $e->public_id }}', {{ $l->creator_series_id }})" class="text-red-600">فكّ</button>@endif</div>
                            @endforeach
                            @if ($can['manage'] && $e->status === 'active')
                                <div class="flex gap-1 mt-1"><input type="text" wire:model="seriesInput.{{ $e->public_id }}" placeholder="معرّف السلسلة" class="{{ $input }} !py-1" dir="ltr"><button type="button" wire:click="linkSeries('{{ $e->public_id }}')" class="{{ $btn2 }}">ربط</button></div>
                            @endif
                        </td>
                        <td>@if ($can['manage'] && $e->status === 'active')<button type="button" wire:click="removeWriter('{{ $e->public_id }}')" wire:confirm="تأكيد إزالة الكاتب من القائمة؟" class="text-red-600 text-xs font-bold">إزالة</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-gray-500 py-4">لا كتّاب بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'voting')
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2" data-voting>
            <div class="text-sm">يبدأ: {{ $fmt($setting?->writers_voting_opens_at) }} — يُغلق (الفعلي): {{ $fmt($close) }} — ميزانية الناخب: {{ (int) ($setting?->writers_stars_per_voter ?? 10) }} نجوم</div>
            <div class="text-sm">كتّاب: {{ $totals['active'] }} نشط من {{ $totals['writers'] }} — ناخبون: {{ number_format($totals['voters']) }} — توزيعات: {{ number_format($totals['allocations']) }} — مجموع النجوم: {{ number_format($totals['stars']) }}</div>
            <p class="text-xs text-gray-500">مجاميع فقط: لا تُعرض هوية الناخبين ولا توزيعهم الفردي هنا.</p>
        </div>
        <table class="w-full text-sm" data-voting-board>
            <thead><tr class="text-gray-500"><th>#</th><th class="text-start">الكاتب</th><th>النجوم</th><th>الناخبون</th><th>العلني</th></tr></thead>
            <tbody>
            @forelse ($board['rows'] as $r)
                <tr class="border-t border-gray-100 dark:border-white/10"><td class="text-center">{{ $r['rank'] }}</td><td>{{ $r['name'] }}</td><td class="text-center">{{ number_format($r['stars']) }}</td><td class="text-center">{{ number_format($r['voters']) }}</td><td class="text-center">{{ ($r['visible'] ?? true) ? 'ظاهر' : 'مخفي (مبدع غير معتمد/مجمَّد)' }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-gray-500 py-4">لا ترتيب بعد.</td></tr>
            @endforelse
            </tbody>
        </table>
    @elseif ($tab === 'standings')
        <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-standings>
            @if ($preview['already'])
                <div class="text-sm font-bold">انتهى الترتيب في {{ $fmt($setting->writers_finalized_at) }}.</div>
                <table class="w-full text-sm">
                    <thead><tr class="text-gray-500"><th>#</th><th class="text-start">الكاتب</th><th>النجوم</th><th>الناخبون</th><th>النتيجة</th></tr></thead>
                    <tbody>@foreach ($final['rows'] as $r)<tr class="border-t border-gray-100 dark:border-white/10"><td class="text-center">{{ $r['rank'] }}</td><td>{{ $r['name'] }}</td><td class="text-center">{{ number_format($r['stars']) }}</td><td class="text-center">{{ number_format($r['voters']) }}</td><td class="text-center">{{ $outcomes[$r['outcome']] ?? $r['outcome'] }}</td></tr>@endforeach</tbody>
                </table>
                @if ($can['award'])<button type="button" wire:click="redeliverWriters" class="{{ $btn2 }}">إعادة تسليم الإشعارات والجوائز التجميلية المعلّقة</button>@endif
            @else
                <div class="font-bold">معاينة الإنهاء (قراءة فقط)</div>
                @if ($preview['blockers'])<ul class="text-sm text-red-600 list-disc ps-5">@foreach ($preview['blockers'] as $b)<li>{{ $b }}</li>@endforeach</ul>@endif
                <table class="w-full text-sm">
                    <thead><tr class="text-gray-500"><th>#</th><th class="text-start">الكاتب</th><th>النجوم</th><th>الناخبون</th><th>النتيجة المتوقعة</th></tr></thead>
                    <tbody>@foreach ($preview['rows'] as $r)<tr class="border-t border-gray-100 dark:border-white/10"><td class="text-center">{{ $r['rank'] }}</td><td>{{ $r['name'] }}</td><td class="text-center">{{ number_format($r['stars']) }}</td><td class="text-center">{{ number_format($r['voters']) }}</td><td class="text-center">{{ $outcomes[$r['outcome']] ?? $r['outcome'] }}</td></tr>@endforeach</tbody>
                </table>
                @if ($preview['awards'])<div class="text-xs text-gray-500">الجوائز التي ستُسجَّل: @foreach ($preview['awards'] as $a){{ $a['name'] }} — {{ $a['label'] }}@if (! $loop->last)، @endif @endforeach</div>@endif
                @if ($can['finalize'])
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="confirmWriters"> أؤكد إنهاء الترتيب النهائي (لا رجعة بعده ولا يُعاد فتح التصويت)</label>
                    <button type="button" wire:click="finalizeWriters" @disabled(! $preview['can_finalize']) class="{{ $btn }}">إنهاء الترتيب</button>
                @endif
            @endif
        </div>
    @elseif ($tab === 'awards')
        <div class="space-y-3" data-awards>
            @if ($can['award'])<button type="button" wire:click="grantCosmetics" class="{{ $btn2 }}">منح الجوائز التجميلية المعلّقة</button>@endif
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">الكاتب</th><th>المركز</th><th>النوع</th><th class="text-start">الجائزة</th><th>القيمة</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                @forelse ($awards as $a)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td>{{ $a->user?->name }}</td><td class="text-center">{{ $outcomes[$a->placement] ?? $a->placement }}</td>
                        <td class="text-center">{{ $a->kind === 'cosmetic' ? 'تجميلية' : 'مالية' }}</td><td>{{ $a->label }}</td>
                        <td class="text-center">{{ $a->kind === 'monetary' ? $money($a->amount_minor, $a->currency) : '—' }}</td>
                        <td class="text-center">{{ $awardStatus[$a->status] ?? $a->status }}</td>
                        <td>@if ($can['award'] && in_array($a->status, ['pending_finance', 'planned', 'failed'], true))<button type="button" wire:click="showAward('{{ $a->public_id }}')" class="text-primary-600 text-xs font-bold">إجراء</button>@endif</td>
                    </tr>
                    @if ($awardOpen === $a->public_id)
                        <tr><td colspan="7" class="bg-gray-50 dark:bg-white/5 p-3">
                            <div class="grid md:grid-cols-3 gap-2">
                                @if ($a->kind === 'monetary' && $a->status === 'pending_finance')
                                    <input type="text" maxlength="16" wire:model="settleMethod" placeholder="طريقة الصرف" class="{{ $input }}">
                                    <input type="text" maxlength="120" wire:model="settleReference" placeholder="مرجع الصرف (إلزامي)" class="{{ $input }}" dir="ltr">
                                @endif
                                <input type="text" maxlength="{{ (int) config('seasonal_recognition.limits.note_max', 300) }}" wire:model="awardNote" placeholder="ملاحظة (إلزامية عند الإلغاء)" class="{{ $input }}">
                            </div>
                            <div class="flex gap-2 mt-2">
                                @if ($a->kind === 'monetary' && $a->status === 'pending_finance')<button type="button" wire:click="settleAward" class="{{ $btn }}">تسجيل التسوية (تمّ الصرف خارج النظام)</button>@endif
                                <button type="button" wire:click="cancelAward" wire:confirm="تأكيد إلغاء الجائزة؟" class="{{ $btn2 }} text-red-600">إلغاء الجائزة</button>
                            </div>
                        </td></tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="text-gray-500 py-4">لا جوائز مُسجَّلة (تُسجَّل عند الإنهاء).</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'packages')
        <div class="space-y-3" data-packages>
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm">تقدير الداعمين لهذا الموسم: <strong>{{ $setting?->supporters_enabled ? 'مُفعَّل' : 'معطَّل' }}</strong>@if ($setting?->supporters_finalized_at) (انتهى ترتيبه)@endif</span>
                @if ($can['manage'])<button type="button" wire:click="toggleSupporters" class="{{ $btn2 }}">{{ $setting?->supporters_enabled ? 'تعطيل' : 'تفعيل' }}</button>@endif
            </div>
            <p class="text-xs text-gray-500">الأسعار والعملة والنمط تُحدَّد من الخادم: فضي 25.00 · ذهبي 100.00 · ماسي 250.00 USD. تغيير الباقة يسري على الطلبات الجديدة فقط (كل بطاقة تحمل لقطتها). الدفع الحقيقي عبر برنامج رعاية الموسم (E30/E32).</p>
            @if ($can['manage'] && $packages->count() < 3)
                <div class="flex flex-wrap gap-2 items-center">
                    <select wire:model="programId" class="{{ $input }} !w-auto"><option value="">— برنامج رعاية الموسم —</option>@foreach ($programs as $p)<option value="{{ $p->id }}">{{ $p->title }} ({{ $p->status }})</option>@endforeach</select>
                    <button type="button" wire:click="seedPackages" class="{{ $btn }}">إنشاء الباقات الافتراضية</button>
                </div>
            @endif
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">المستوى</th><th class="text-start">الاسم</th><th>السعر</th><th>نقاط الدعم</th><th>نشطة</th><th>تنويه اجتماعي</th><th></th></tr></thead>
                <tbody>
                @forelse ($packages as $p)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td>{{ $p->tier_key }}</td>
                        <td><input type="text" maxlength="60" wire:model="packageInput.{{ $p->id }}.name" class="{{ $input }}" @disabled(! $can['manage'])></td>
                        <td class="text-center" dir="ltr">{{ $money((int) $p->price_minor, $p->currency) }}</td>
                        <td><input type="number" min="0" wire:model="packageInput.{{ $p->id }}.support_points" class="{{ $input }} !w-28" @disabled(! $can['manage'])></td>
                        <td class="text-center"><input type="checkbox" wire:model="packageInput.{{ $p->id }}.is_active" @disabled(! $can['manage'])></td>
                        <td class="text-center"><input type="checkbox" wire:model="packageInput.{{ $p->id }}.social_mention_eligible" @disabled(! $can['manage'])></td>
                        <td>@if ($can['manage'])<button type="button" wire:click="savePackage({{ $p->id }})" class="{{ $btn2 }}">حفظ</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-gray-500 py-4">لا باقات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'leaderboard')
        <div class="space-y-3" data-supporter-board>
            <div class="text-sm">داعمون: {{ number_format($totals['supporters']) }} — نقاط كلية: {{ number_format($totals['points']) }} — نقاط علنية: {{ number_format($totals['public_points']) }}</div>
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th>#</th><th class="text-start">الداعم (للإدارة)</th><th>نقاط كلية</th><th>نقاط علنية</th><th>المستوى</th><th>مرتبة نهائية</th></tr></thead>
                <tbody>
                @forelse ($rows as $i => $r)
                    <tr class="border-t border-gray-100 dark:border-white/10"><td class="text-center">{{ $i + 1 }}</td><td>{{ $r->supporter_name }}@if ($r->frozen) <span class="text-xs text-red-600">(مجمَّد)</span>@endif</td><td class="text-center">{{ number_format($r->points_total) }}</td><td class="text-center">{{ number_format($r->public_points_total) }}</td><td class="text-center">{{ $tierNames[$r->card_tier_key] ?? '—' }}</td><td class="text-center">{{ $r->final_rank ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="text-gray-500 py-4">لا نقاط بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="text-xs text-gray-500">اللوحة العلنية: لاعبون فقط، أعلى {{ (int) config('seasonal_recognition.supporters.leaderboard_limit', 100) }} بالنقاط العلنية؛ المجهولون وملفات E30 غير العلنية/الموقوفة والمؤسسات خارجها.</p>

            @if ($preview['already'])
                <div class="text-sm font-bold">انتهى ترتيب الداعمين.</div>
            @else
                @if ($preview['blockers'])<ul class="text-sm text-red-600 list-disc ps-5">@foreach ($preview['blockers'] as $b)<li>{{ $b }}</li>@endforeach</ul>@endif
                @if ($can['finalize'])
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="confirmSupporters"> أؤكد إنهاء ترتيب الداعمين (لا رجعة بعده)</label>
                    <button type="button" wire:click="finalizeSupporters" @disabled(! $preview['can_finalize']) class="{{ $btn }}">إنهاء ترتيب الداعمين</button>
                @endif
            @endif
        </div>
    @elseif ($tab === 'queue')
        <div class="space-y-3" data-social-queue>
            <p class="text-xs text-gray-500">طابور إداري لتسجيل من يستحق تنويهًا اجتماعيًا فقط. لا نشر آلي ولا ربط بأي واجهة شبكة اجتماعية؛ تُسجَّل الحالة يدويًا بعد النشر.</p>
            <select wire:model.live="queueFilter" class="{{ $input }} !w-auto"><option value="">كل الحالات</option>@foreach (config('seasonal_recognition.social_queue.statuses') as $s)<option value="{{ $s }}">{{ ['eligible' => 'مؤهَّل', 'queued' => 'في الطابور', 'published' => 'نُشر', 'skipped' => 'تُجوهل'][$s] ?? $s }}</option>@endforeach</select>
            <table class="w-full text-sm">
                <thead><tr class="text-gray-500"><th class="text-start">الداعم</th><th>المستوى</th><th>النقاط العلنية</th><th>الحالة</th><th>ملاحظة</th><th></th></tr></thead>
                <tbody>
                @forelse ($queue as $q)
                    <tr class="border-t border-gray-100 dark:border-white/10 align-top">
                        <td>{{ $q->name }}</td><td class="text-center">{{ $tierNames[$q->card_tier_key] ?? '—' }}</td><td class="text-center">{{ number_format($q->public_points_total) }}</td>
                        <td class="text-center">{{ ['eligible' => 'مؤهَّل', 'queued' => 'في الطابور', 'published' => 'نُشر', 'skipped' => 'تُجوهل'][$q->social_status] ?? $q->social_status }}</td>
                        <td class="text-xs">{{ $q->social_note }}@if ($can['manage'])<input type="text" maxlength="{{ (int) config('seasonal_recognition.limits.note_max', 300) }}" wire:model="queueNote.{{ $q->id }}" placeholder="ملاحظة" class="{{ $input }} !py-1 mt-1">@endif</td>
                        <td class="whitespace-nowrap">
                            @if ($can['manage'])
                                @foreach (['queued' => 'إدراج', 'published' => 'نُشر', 'skipped' => 'تجاهل', 'eligible' => 'إعادة'] as $to => $label)
                                    @if ($to !== $q->social_status)<button type="button" wire:click="transition({{ $q->id }}, '{{ $to }}')" class="text-primary-600 text-xs font-bold">{{ $label }}</button> @endif
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-gray-500 py-4">الطابور فارغ.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'reconcile')
        <div class="space-y-4" data-reconcile>
            @if ($writerFindings === null)
                <p class="text-gray-500">المطابقة تتطلب صلاحية التدقيق.</p>
            @else
                <p class="text-xs text-gray-500">قراءة فقط: لا إصلاح صامت. إعادة البناء إجراء صريح مسجَّل في سجل التدقيق.</p>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2">
                    <div class="font-bold">دوري الكتّاب: {{ $writerFindings === [] ? 'متسق ✔' : count($writerFindings).' مخالفة' }}</div>
                    @foreach ($writerFindings as $f)<div class="text-xs" dir="ltr">{{ json_encode($f, JSON_UNESCAPED_UNICODE) }}</div>@endforeach
                    @if ($can['audit'])<button type="button" wire:click="rebuildWriters" wire:confirm="إعادة بناء مجاميع الدوري من دفتر التوزيعات؟" class="{{ $btn2 }}">إعادة بناء مجاميع الكتّاب والميزانيات</button>@endif
                </div>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2">
                    <div class="font-bold">نقاط الدعم والبطاقات: {{ $supporterFindings === [] ? 'متسق ✔' : count($supporterFindings).' مخالفة' }}</div>
                    @foreach ($supporterFindings as $f)<div class="text-xs" dir="ltr">{{ json_encode($f, JSON_UNESCAPED_UNICODE) }}</div>@endforeach
                    @if ($can['manage'])
                        <div class="flex gap-2">
                            <button type="button" wire:click="rebuildSupporters" wire:confirm="إعادة بناء إحصاءات الداعمين من الدفتر؟" class="{{ $btn2 }}">إعادة بناء الإحصاءات</button>
                            <button type="button" wire:click="activatePending" class="{{ $btn2 }}">تفعيل البطاقات المحقَّق دفعها</button>
                        </div>
                        <div class="flex flex-wrap items-end gap-2" data-backfill>
                            <label class="text-xs">رقم شراء المتجر الفائت منحه
                                <input type="number" min="1" wire:model="backfillPurchaseId" class="{{ $input }} !w-40" dir="ltr">
                            </label>
                            <button type="button" wire:click="backfillPurchase" wire:confirm="منح نقاط هذا الشراء إلى هذا الموسم تحديدًا؟" class="{{ $btn2 }}">منح شراء فائت إلى هذا الموسم</button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
