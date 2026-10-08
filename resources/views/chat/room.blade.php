@extends('layouts.app')

@section('title', $config['title'])

@section('content')
    {{-- E21: غرفة دردشة نصية. كل نص يُعرض بـx-text (مهرَّب) فلا HTML من المستخدمين؛ الإعداد يُمرَّر بـJs::from (JSON مهرَّب للسمات والوسوم). --}}
    {{-- E22: سطح المكتب: قائمة المحادثات جانبًا + الغرفة؛ الجوال: الغرفة وحدها مع سهم رجوع للقائمة. منطق الرسائل (Alpine) كما هو. --}}
    <div class="lg:grid lg:grid-cols-[19rem_minmax(0,1fr)] lg:gap-5 lg:items-start">
        <aside class="hidden lg:block lg:sticky lg:top-20" aria-label="قائمة المحادثات">
            @include('messages._sidebar', $sidebar)
        </aside>

    <div class="min-w-0 space-y-3" x-data="chatRoom({{ \Illuminate\Support\Js::from($config) }})">
        <header class="glass rounded-2xl px-3 sm:px-4 py-3 flex items-center gap-3">
            <a href="{{ route('messages.index') }}" aria-label="كل المحادثات" class="lg:hidden grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-white/5 text-slate-300 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <x-ui-icon name="chevron-right" class="w-5 h-5" />
            </a>
            <span aria-hidden="true" class="grid place-items-center w-10 h-10 shrink-0 rounded-full {{ $config['type'] === 'global' ? 'bg-emerald/15 text-emerald' : 'bg-amethyst/20 text-white' }} font-bold">
                @if ($config['type'] === 'global')
                    <x-ui-icon name="globe" class="w-5 h-5" />
                @elseif ($config['type'] === 'team')
                    <x-ui-icon name="team" class="w-5 h-5" />
                @else
                    {{ mb_substr($config['title'], 0, 1) }}
                @endif
            </span>
            <div class="min-w-0">
                <h1 class="font-display font-black text-lg text-white truncate">{{ $config['title'] }}</h1>
                @if ($config['subtitle'])
                    <p class="text-xs text-slate-400 truncate">{{ $config['subtitle'] }}</p>
                @endif
            </div>
        </header>

        <p x-show="error" x-cloak x-text="error" role="alert" class="rounded-xl bg-rose/10 border border-rose/30 px-3 py-2 text-xs text-rose"></p>
        <p x-show="notice" x-cloak x-text="notice" role="status" class="rounded-xl bg-emerald-500/10 border border-emerald-500/30 px-3 py-2 text-xs text-emerald-400"></p>

        <section class="glass rounded-3xl p-3 sm:p-4" aria-label="الرسائل">
            <div x-ref="scroller" role="log" aria-live="polite" class="h-[56vh] min-h-[18rem] lg:h-[calc(100vh-18rem)] lg:max-h-[46rem] overflow-y-auto space-y-3 pe-1">
                <div class="text-center">
                    <button type="button" x-show="hasMore" x-cloak @click="loadOlder()" :disabled="loadingOlder" class="chip text-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                        <span x-text="loadingOlder ? 'جارٍ التحميل…' : 'تحميل رسائل أقدم'"></span>
                    </button>
                </div>

                <p x-show="messages.length === 0" class="text-center text-sm text-slate-500 py-10">لا رسائل بعد. ابدأ المحادثة!</p>

                <template x-for="m in messages" :key="m.id">
                    <div class="flex" :class="m.mine ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[85%] rounded-2xl px-3 py-2 border" :class="m.mine ? 'bg-amethyst/15 border-amethyst/30' : 'bg-white/5 border-white/10'">
                            <p x-show="!m.mine && cfg.type !== 'direct'" class="text-[11px] font-bold text-gold mb-0.5" x-text="m.sender ? m.sender.name : 'حساب محذوف'"></p>

                            <template x-if="m.state === 'normal' && editingId !== m.id">
                                <p class="text-sm text-white whitespace-pre-wrap break-words" x-text="m.body"></p>
                            </template>
                            <template x-if="m.state === 'normal' && editingId === m.id">
                                <form @submit.prevent="saveEdit(m)" class="space-y-1">
                                    <label class="sr-only" for="edit-box">تعديل الرسالة</label>
                                    <textarea id="edit-box" x-model="editBody" rows="2" :maxlength="cfg.limits.max_length" class="w-full rounded-xl bg-white/5 border border-white/10 px-2 py-1.5 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"></textarea>
                                    <div class="flex gap-2 text-xs"><button type="submit" class="chip">حفظ</button><button type="button" class="chip" @click="editingId = null">إلغاء</button></div>
                                </form>
                            </template>
                            <p x-show="m.state === 'deleted'" class="text-xs italic text-slate-500">تم حذف هذه الرسالة</p>
                            <template x-if="m.state === 'hidden'">
                                <div>
                                    <p class="text-xs italic text-slate-500">أُخفيت هذه الرسالة بواسطة المشرفين</p>
                                    <p x-show="m.moderation_body" class="text-xs text-slate-400 mt-1 whitespace-pre-wrap break-words" x-text="m.moderation_body"></p>
                                </div>
                            </template>

                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-500">
                                <time x-text="timeLabel(m.created_at)"></time>
                                <span x-show="m.edited">معدّلة</span>
                                <button type="button" x-show="m.can_edit" class="hover:text-white" @click="startEdit(m)">تعديل</button>
                                <button type="button" x-show="m.can_delete" class="hover:text-rose" @click="remove(m)">حذف</button>
                                <button type="button" x-show="m.can_report" class="hover:text-amber-400" @click="reporting = m.id">إبلاغ</button>
                                <button type="button" x-show="m.can_hide" class="hover:text-amber-400" @click="moderate('hide', m)">إخفاء</button>
                                <button type="button" x-show="m.can_restore" class="hover:text-emerald-400" @click="moderate('restore', m)">استعادة</button>
                            </div>

                            <form x-show="reporting === m.id" x-cloak @submit.prevent="submitReport(m)" class="mt-2 space-y-1 border-t border-white/10 pt-2 text-xs">
                                <label class="sr-only" for="report-category">سبب البلاغ</label>
                                <select id="report-category" x-model="reportCategory" class="w-full rounded-lg bg-white/5 border border-white/10 px-2 py-1 text-white">
                                    <template x-for="c in cfg.categories" :key="c"><option :value="c" x-text="{ spam: 'إزعاج', harassment: 'إساءة', inappropriate: 'غير لائق', scam: 'احتيال', other: 'آخر' }[c] ?? c"></option></template>
                                </select>
                                <input type="text" x-model="reportDetails" maxlength="500" placeholder="تفاصيل (اختياري)" class="w-full rounded-lg bg-white/5 border border-white/10 px-2 py-1 text-white">
                                <div class="flex gap-2"><button type="submit" class="chip">إرسال البلاغ</button><button type="button" class="chip" @click="reporting = null">إلغاء</button></div>
                            </form>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-3 border-t border-white/10 pt-3">
                <template x-if="cfg.caps.send">
                    <form @submit.prevent="send()" class="flex items-end gap-2">
                        <label class="sr-only" for="chat-body">رسالتك</label>
                        <textarea id="chat-body" x-model="body" rows="2" :maxlength="cfg.limits.max_length" placeholder="اكتب رسالة…" @keydown.enter.prevent="if (!$event.shiftKey) send()"
                            class="flex-1 rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst"></textarea>
                        <button type="submit" :disabled="sending || !body.trim()" class="btn-gem !py-2 !px-5 text-sm disabled:opacity-50">إرسال</button>
                    </form>
                </template>
                <p x-show="!cfg.caps.send" x-cloak x-text="cfg.caps.blocker_message" role="status" class="text-center text-xs text-amber-400"></p>
            </div>
        </section>
    </div>
    </div>
@endsection
