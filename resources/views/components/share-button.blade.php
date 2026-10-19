@props(['kind', 'param' => null, 'title', 'text' => null, 'seasonId' => null, 'payload' => null])

{{--
    E33 (C): مكوّن «مشاركة» موحَّد (RTL، بلا مكتبات طرف ثالث ولا بكسلات ولا سكربت خارجي).
    - الرابط الأساسي قانوني نظيف؛ «مع رابط دعوتي» اختياري لمن لديه رمز نشط (يُبنى في الخادم ولا يُركَّب في المتصفح).
    - navigator.share عند توفره، وإلا «نسخ الرابط» (Clipboard API ثم احتياطي execCommand). واتساب/تيليجرام روابط عادية rel آمن.
    - تتبّع المشاركة اختياري ومحدود: POST بقوائم مغلقة للمصادَق فقط، يفشل بصمت، ولا يمنح شيئًا.
    - المصدر: ShareLinkBuilder من مسار مسمّى؛ لا URL من العميل.
--}}
@php
    $share = $payload ?? app(\App\Services\Referrals\ShareLinkBuilder::class)->build($kind, $param, (string) $title, $text, auth()->user(), $seasonId);
    $hasRef = $share['url_ref'] !== null;
@endphp

@if ((bool) config('referrals.enabled', true))
    <div {{ $attributes->class('glass rounded-2xl p-4') }} data-share-component data-share-kind="{{ $share['kind'] }}"
         x-data="{
            withRef: {{ $hasRef ? 'true' : 'false' }},
            copied: false,
            failed: false,
            canNative: (typeof navigator !== 'undefined' && typeof navigator.share === 'function'),
            payload: @js($share),
            get url() { return (this.withRef && this.payload.url_ref) ? this.payload.url_ref : this.payload.url; },
            get links() { return (this.withRef && this.payload.links.ref) ? this.payload.links.ref : this.payload.links.plain; },
            track(target) {
                @auth
                try {
                    fetch(@js(route('referrals.share')), {
                        method: 'POST', keepalive: true, credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || '' },
                        body: JSON.stringify({ kind: this.payload.kind, target: target, with_ref: this.withRef && !!this.payload.url_ref }),
                    }).catch(() => {});
                } catch (e) {}
                @endauth
            },
            async nativeShare() {
                try { await navigator.share({ title: this.payload.title, text: this.payload.text, url: this.url }); this.track('native'); } catch (e) { /* ألغى المستخدم */ }
            },
            async copy() {
                this.failed = false;
                try {
                    if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(this.url); }
                    else { const t = document.createElement('textarea'); t.value = this.url; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); const ok = document.execCommand('copy'); document.body.removeChild(t); if (!ok) { throw new Error('copy'); } }
                    this.copied = true; this.track('copy'); setTimeout(() => this.copied = false, 2500);
                } catch (e) { this.failed = true; }
            },
         }">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-bold text-slate-300 flex items-center gap-1.5"><x-ui-icon name="send" class="w-4 h-4" />مشاركة</span>

            <button type="button" x-show="canNative" x-cloak @click="nativeShare()" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-native>
                <x-ui-icon name="send" class="w-4 h-4" />شارك
            </button>

            <button type="button" @click="copy()" class="chip inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-copy>
                <x-ui-icon name="check" class="w-4 h-4" x-show="copied" x-cloak /><span x-text="copied ? 'تم النسخ' : 'نسخ الرابط'">نسخ الرابط</span>
            </button>

            <a :href="links.whatsapp" target="_blank" rel="noopener noreferrer" @click="track('whatsapp')" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-whatsapp href="{{ $share['links']['plain']['whatsapp'] }}">واتساب</a>
            <a :href="links.telegram" target="_blank" rel="noopener noreferrer" @click="track('telegram')" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-share-telegram href="{{ $share['links']['plain']['telegram'] }}">تيليجرام</a>
        </div>

        @if ($hasRef)
            <label class="mt-3 flex items-center gap-2 text-xs text-slate-400 cursor-pointer">
                <input type="checkbox" x-model="withRef" class="rounded border-white/20 bg-transparent focus:ring-amethyst" data-share-ref-toggle>
                أضف رابط دعوتي (يُنسب إليّ كل من ينضم عبره)
            </label>
        @elseif (auth()->check())
            <p class="mt-3 text-xs text-slate-500">لديك رابط دعوة شخصي؟ <a href="{{ route('referrals.index') }}" class="underline hover:text-white">افتح صفحة الدعوات</a> لتفعيله.</p>
        @endif

        <p class="sr-only" aria-live="polite" x-text="copied ? 'تم نسخ الرابط' : (failed ? 'تعذّر النسخ' : '')"></p>
        <p x-show="failed" x-cloak class="mt-2 text-xs text-rose">تعذّر النسخ تلقائيًا. انسخ الرابط يدويًا: <span dir="ltr" class="select-all break-all" x-text="url"></span></p>
    </div>
@endif
