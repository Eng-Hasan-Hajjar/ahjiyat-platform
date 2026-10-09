@extends('layouts.app')

@section('title', $config['title'])

@section('content')
    {{-- E21: غرفة دردشة نصية. كل نص يُعرض بـx-text (مهرَّب) فلا HTML من المستخدمين؛ الإعداد يُمرَّر بـJs::from (JSON مهرَّب للسمات والوسوم). --}}
    {{-- E22: سطح المكتب: قائمة المحادثات جانبًا + الغرفة؛ الجوال: الغرفة وحدها مع سهم رجوع للقائمة. منطق الرسائل (Alpine) كما هو. --}}
    {{--
        E23 (عرض فقط، بلا تغيير صلاحيات/بيانات/بثّ):
        - الغلاف .chat-shell بارتفاع مشتق من الرأس الفعلي والتنقل السفلي (انظر chat.css): الغرفة بطاقة واحدة (ترويسة + سجل + مؤلِّف) فلا يغطي التنقل السفلي المؤلِّف ولا يتسرّب نص من تحت الرأس.
        - الترويسة تُعرّف الغرفة: عامة (أيقونة مجتمع) / فريق (اسم + رابط الفريق) / مباشرة (الاسم + رابط الملف). لا مؤشر «متصل» وهمي.
        - الفقاعات: رسالتي مائلة للون العلامة ومحاذاة مختلفة، غيري محايدة؛ تجميع المتتاليات والأيام عرضيّ فقط (لا تغيير بالنموذج). لا نعتمد اللون وحده: المحذوفة بحدّ متقطّع + أيقونة + نص، والمخفية بأيقونة + نص.
        - الإجراءات في قائمة صغيرة (aria-haspopup): تظهر عند المرور/التركيز على سطح المكتب، وتبقى ظاهرة بخفوت على الجوال؛ كلها تعمل بلوحة المفاتيح (أسهم/Escape).
    --}}
    @php
        $type = $config['type'];
        $peer = $type === 'direct' ? request()->route('user') : null;
        $teamModel = $team ?? null;
        $roomLabel = ['global' => 'مجتمع', 'team' => 'فريق', 'direct' => 'خاصة'][$type] ?? null;
    @endphp

    <div class="chat-shell lg:grid lg:grid-cols-[19rem_minmax(0,1fr)] lg:gap-5">
        <aside class="hidden min-h-0 lg:block [&>nav]:h-full [&>nav]:overflow-y-auto" aria-label="قائمة المحادثات">
            @include('messages._sidebar', $sidebar)
        </aside>

        <section class="glass flex h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-3xl" x-data="chatRoom({{ \Illuminate\Support\Js::from($config) }})" aria-label="غرفة الدردشة">
            <header class="flex shrink-0 items-center gap-3 border-b border-white/10 px-3 py-3 sm:px-4">
                <a href="{{ route('messages.index') }}" aria-label="كل المحادثات" title="كل المحادثات" class="chat-icon-btn grid place-items-center lg:hidden">
                    <x-ui-icon name="chevron-right" class="h-5 w-5" />
                </a>

                @php
                    $identityHref = $type === 'team' && $teamModel ? route('teams.show', $teamModel) : ($peer ? route('players.show', $peer) : null);
                    $identityLabel = $type === 'team' ? 'صفحة الفريق' : 'الملف الشخصي';
                @endphp
                <{{ $identityHref ? 'a' : 'div' }} @if ($identityHref) href="{{ $identityHref }}" title="{{ $identityLabel }}" @endif class="flex min-w-0 flex-1 items-center gap-3 rounded-xl {{ $identityHref ? 'focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst' : '' }}">
                    <span aria-hidden="true" class="chat-avatar chat-avatar--lg {{ $type === 'global' ? 'chat-avatar--global' : '' }}">
                        @if ($type === 'global')
                            <x-ui-icon name="globe" class="h-5 w-5" />
                        @elseif ($type === 'team')
                            <x-ui-icon name="team" class="h-5 w-5" />
                        @else
                            {{ mb_substr($config['title'], 0, 1) }}
                        @endif
                    </span>
                    <div class="min-w-0">
                        <h1 class="truncate font-display text-base font-black text-white sm:text-lg">{{ $config['title'] }}</h1>
                        @if ($config['subtitle'])
                            <span class="block truncate text-xs text-slate-400">{{ $config['subtitle'] }}</span>
                        @elseif ($type === 'direct')
                            <span class="block truncate text-xs text-slate-400">محادثة خاصة بينكما فقط</span>
                        @elseif ($type === 'team' && $teamModel)
                            <span class="block truncate text-xs text-slate-400">{{ $teamModel->members_count }} عضو · تظهر الرسائل لأعضاء الفريق فقط</span>
                        @endif
                    </div>
                </{{ $identityHref ? 'a' : 'div' }}>

                @if ($roomLabel)
                    <x-status-badge tone="neutral" class="hidden sm:inline-flex">{{ $roomLabel }}</x-status-badge>
                @endif
            </header>

            <div x-show="error" x-cloak role="alert" class="flex shrink-0 items-start gap-2 border-b border-rose/30 bg-rose/10 px-4 py-2 text-xs font-bold text-rose">
                <x-ui-icon name="alert" class="mt-px h-4 w-4 shrink-0" /><p class="min-w-0" x-text="error"></p>
            </div>
            <div x-show="notice" x-cloak role="status" class="flex shrink-0 items-start gap-2 border-b border-emerald/30 bg-emerald/10 px-4 py-2 text-xs font-bold text-emerald">
                <x-ui-icon name="check-circle" class="mt-px h-4 w-4 shrink-0" /><p class="min-w-0" x-text="notice"></p>
            </div>

            <div class="relative min-h-0 flex-1">
                <div x-ref="scroller" role="log" aria-live="polite" aria-relevant="additions text" aria-label="رسائل المحادثة" tabindex="0" @scroll.passive="onScroll()"
                    class="chat-scroller h-full overflow-y-auto px-3 py-3 sm:px-4">
                    <div class="flex justify-center pb-1" x-show="hasMore" x-cloak>
                        <button type="button" @click="loadOlder()" :disabled="loadingOlder" :aria-busy="loadingOlder.toString()"
                            class="inline-flex min-h-10 items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 text-xs font-bold text-slate-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst disabled:opacity-60 motion-reduce:transition-none">
                            <span x-show="loadingOlder" x-cloak class="chat-spinner" aria-hidden="true"></span>
                            <x-ui-icon name="arrow-down" x-show="!loadingOlder" class="h-4 w-4 rotate-180" />
                            <span x-text="loadingOlder ? 'جارٍ التحميل…' : 'تحميل رسائل أقدم'">تحميل رسائل أقدم</span>
                        </button>
                    </div>
                    <p x-show="!hasMore && messages.length > 0" x-cloak class="py-1 text-center text-[11px] text-slate-500">بداية المحادثة</p>

                    <div x-show="messages.length === 0" x-cloak class="grid h-full min-h-[12rem] place-items-center text-center">
                        <div class="max-w-xs px-4">
                            <span class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-amethyst/15 text-amethyst"><x-ui-icon name="chat" class="h-7 w-7" /></span>
                            <h2 class="font-display text-base font-black text-white">ابدأ المحادثة</h2>
                            <p class="mt-1 text-sm text-slate-400" x-text="emptyCopy()"></p>
                        </div>
                    </div>

                    <template x-for="(m, i) in messages" :key="m.id">
                        <div>
                            <template x-if="dayStart(i)">
                                <div class="chat-day"><span x-text="dayLabel(m.created_at)"></span></div>
                            </template>

                            <div class="chat-row flex items-start gap-2" :class="[m.mine ? 'justify-end' : 'justify-start', isGroupStart(i) ? 'mt-3' : 'mt-0.5']">
                                <span x-show="!m.mine && cfg.type !== 'direct'" aria-hidden="true" class="chat-avatar !h-8 !w-8" :class="[tone(m.sender), isGroupStart(i) ? '' : 'invisible']" x-text="initial(m.sender)"></span>

                                <div class="chat-bubble max-w-[86%] break-words md:max-w-[36rem]"
                                    :class="[m.mine ? 'chat-bubble--mine' : 'chat-bubble--others', isGroupStart(i) ? '' : 'chat-bubble--cont', m.state === 'deleted' ? 'chat-bubble--deleted' : '', m.state === 'hidden' ? 'chat-bubble--hidden' : '']">
                                    <p x-show="!m.mine && cfg.type !== 'direct' && isGroupStart(i)" class="chat-sender" x-text="m.sender ? m.sender.name : 'حساب محذوف'"></p>
                                    <span class="sr-only" x-show="m.mine || cfg.type === 'direct' || !isGroupStart(i)" x-text="(m.mine ? 'أنت' : (m.sender ? m.sender.name : 'حساب محذوف')) + ': '"></span>

                                    <template x-if="m.state === 'normal' && editingId !== m.id">
                                        <p class="whitespace-pre-wrap text-[0.9375rem] leading-6 [overflow-wrap:anywhere]" x-text="m.body"></p>
                                    </template>
                                    <template x-if="m.state === 'normal' && editingId === m.id">
                                        <form @submit.prevent="saveEdit(m)" @keydown.escape="editingId = null" class="space-y-2">
                                            <label class="sr-only" for="edit-box">تعديل الرسالة</label>
                                            <textarea id="edit-box" x-model="editBody" rows="2" :maxlength="cfg.limits.max_length" class="chat-input !max-h-40 !w-full !rounded-xl !text-sm"></textarea>
                                            <div class="flex gap-2 text-xs">
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-full bg-amethyst/20 px-4 font-bold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">حفظ</button>
                                                <button type="button" class="inline-flex min-h-9 items-center rounded-full border border-white/10 px-4 font-bold text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" @click="editingId = null">إلغاء</button>
                                            </div>
                                        </form>
                                    </template>
                                    <p x-show="m.state === 'deleted'" class="flex items-center gap-2 text-xs italic">
                                        <x-ui-icon name="trash" class="h-4 w-4 shrink-0" /><span>تم حذف هذه الرسالة</span>
                                    </p>
                                    <template x-if="m.state === 'hidden'">
                                        <div>
                                            <p class="flex items-center gap-2 text-xs font-bold text-gold">
                                                <x-ui-icon name="eye-slash" class="h-4 w-4 shrink-0" /><span>أُخفيت هذه الرسالة بواسطة المشرفين</span>
                                            </p>
                                            <div x-show="m.moderation_body" class="mt-2 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5">
                                                <p class="text-[11px] font-bold text-slate-400">النص الأصلي (يراه المشرفون فقط)</p>
                                                <p class="mt-0.5 whitespace-pre-wrap text-xs text-slate-300 [overflow-wrap:anywhere]" x-text="m.moderation_body"></p>
                                            </div>
                                        </div>
                                    </template>

                                    <div class="chat-meta">
                                        <time :datetime="m.created_at" :title="fullTime(m.created_at)" x-text="timeLabel(m.created_at)"></time>
                                        <span x-show="m.edited">معدّلة</span>
                                    </div>

                                    <template x-if="reporting === m.id">
                                        <form @submit.prevent="submitReport(m)" @keydown.escape="reporting = null" class="mt-2 space-y-2 border-t border-white/10 pt-2 text-xs">
                                            <label class="sr-only" for="report-category">سبب البلاغ</label>
                                            <select id="report-category" x-model="reportCategory" class="w-full rounded-lg border border-white/10 bg-night-900 px-2 py-2 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                                                <template x-for="c in cfg.categories" :key="c"><option :value="c" x-text="{ spam: 'إزعاج', harassment: 'إساءة', inappropriate: 'غير لائق', scam: 'احتيال', other: 'آخر' }[c] ?? c"></option></template>
                                            </select>
                                            <label class="sr-only" for="report-details">تفاصيل البلاغ (اختياري)</label>
                                            <input id="report-details" type="text" x-model="reportDetails" maxlength="500" placeholder="تفاصيل (اختياري)" class="w-full rounded-lg border border-white/10 bg-white/5 px-2 py-2 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                                            <div class="flex gap-2">
                                                <button type="submit" class="inline-flex min-h-9 items-center rounded-full bg-amethyst/20 px-4 font-bold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إرسال البلاغ</button>
                                                <button type="button" class="inline-flex min-h-9 items-center rounded-full border border-white/10 px-4 font-bold text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" @click="reporting = null">إلغاء</button>
                                            </div>
                                        </form>
                                    </template>
                                </div>

                                <div class="relative shrink-0 pt-1" :class="m.mine ? 'order-first' : ''" x-show="hasActions(m) && editingId !== m.id" @click.outside="menuId === m.id && closeMenu()">
                                    <button type="button" class="chat-act" :data-chat-act="m.id" aria-haspopup="menu" :aria-expanded="(menuId === m.id).toString()" :aria-controls="'chat-menu-' + m.id"
                                        aria-label="إجراءات الرسالة" title="إجراءات الرسالة" @click="toggleMenu(m, $event)" @keydown.escape="closeMenu(true)">
                                        <x-ui-icon name="ellipsis" class="h-5 w-5" />
                                    </button>
                                    <div x-show="menuId === m.id" x-cloak role="menu" aria-label="إجراءات الرسالة" :id="'chat-menu-' + m.id" :data-chat-menu="m.id" class="chat-menu"
                                        :class="[menuUp ? 'bottom-full mb-1' : 'top-full mt-1', m.mine ? 'start-0' : 'end-0']"
                                        @keydown="menuKey($event, m.id)" @keydown.escape.stop="closeMenu(true)" @keydown.tab="closeMenu()">
                                        <button type="button" role="menuitem" x-show="m.can_edit" @click="closeMenu(); startEdit(m)"><x-ui-icon name="pencil" class="h-4 w-4 shrink-0" />تعديل</button>
                                        <button type="button" role="menuitem" x-show="m.can_delete" class="chat-menu__danger" @click="closeMenu(); remove(m)"><x-ui-icon name="trash" class="h-4 w-4 shrink-0" />حذف</button>
                                        <button type="button" role="menuitem" x-show="m.can_report" @click="closeMenu(); startReport(m)"><x-ui-icon name="flag" class="h-4 w-4 shrink-0" />إبلاغ</button>
                                        <button type="button" role="menuitem" x-show="m.can_hide" @click="closeMenu(); moderate('hide', m)"><x-ui-icon name="eye-slash" class="h-4 w-4 shrink-0" />إخفاء</button>
                                        <button type="button" role="menuitem" x-show="m.can_restore" @click="closeMenu(); moderate('restore', m)"><x-ui-icon name="undo" class="h-4 w-4 shrink-0" />استعادة</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" x-show="unseen > 0" x-cloak @click="jumpLatest()" class="chat-new">
                    <x-ui-icon name="arrow-down" class="h-4 w-4" />
                    <span>رسائل جديدة</span>
                    <span x-text="unseen > 9 ? '9+' : unseen" class="rounded-full bg-white/20 px-1.5 text-xs"></span>
                </button>
            </div>

            <div class="chat-composer">
                <template x-if="cfg.caps.send">
                    <div>
                        <form @submit.prevent="send()" class="flex items-end gap-2">
                            <label class="sr-only" for="chat-body">رسالتك</label>
                            <textarea id="chat-body" x-ref="box" x-model="body" rows="1" :maxlength="cfg.limits.max_length" placeholder="اكتب رسالة…" aria-describedby="chat-hint" @input="grow()" @keydown.enter="onEnter($event)" class="chat-input"></textarea>
                            <button type="submit" class="chat-send" :disabled="sending || !body.trim()" :aria-busy="sending.toString()" aria-label="إرسال الرسالة" title="إرسال">
                                <x-ui-icon name="send" x-show="!sending" class="h-5 w-5 rtl:-scale-x-100" />
                                <span x-show="sending" x-cloak class="chat-spinner" aria-hidden="true"></span>
                            </button>
                        </form>
                        <div class="mt-1.5 flex items-center justify-between gap-3 px-1 text-[11px] text-slate-500">
                            <p id="chat-hint" class="pointer-coarse:hidden"><bdi>Enter</bdi> للإرسال · <bdi>Shift+Enter</bdi> لسطر جديد</p>
                            <p x-show="body.length > cfg.limits.max_length * 0.8" x-cloak class="tabular-nums"><span x-text="body.length"></span> / <span x-text="cfg.limits.max_length"></span></p>
                        </div>
                    </div>
                </template>

                <div x-show="!cfg.caps.send" x-cloak class="space-y-2">
                    <div class="chat-blocked" role="status">
                        <x-ui-icon name="lock" x-show="blockerView()[1] === 'lock'" class="mt-0.5 h-5 w-5 shrink-0 text-gold" />
                        <x-ui-icon name="no-symbol" x-show="blockerView()[1] === 'no-symbol'" class="mt-0.5 h-5 w-5 shrink-0 text-gold" />
                        <x-ui-icon name="mute" x-show="blockerView()[1] === 'mute'" class="mt-0.5 h-5 w-5 shrink-0 text-gold" />
                        <x-ui-icon name="friends" x-show="blockerView()[1] === 'friends'" class="mt-0.5 h-5 w-5 shrink-0 text-gold" />
                        <x-ui-icon name="mail" x-show="blockerView()[1] === 'mail'" class="mt-0.5 h-5 w-5 shrink-0 text-gold" />
                        <div class="min-w-0">
                            <p class="text-sm font-black text-white" x-text="blockerView()[0]"></p>
                            <p class="mt-0.5 text-xs text-slate-300" x-text="cfg.caps.blocker_message"></p>
                        </div>
                    </div>
                    <div class="flex items-end gap-2" aria-hidden="true">
                        <textarea rows="1" disabled tabindex="-1" aria-label="الإرسال غير متاح" placeholder="الإرسال غير متاح" class="chat-input"></textarea>
                        <button type="button" disabled tabindex="-1" aria-label="إرسال الرسالة (غير متاح)" class="chat-send"><x-ui-icon name="send" class="h-5 w-5 rtl:-scale-x-100" /></button>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection
