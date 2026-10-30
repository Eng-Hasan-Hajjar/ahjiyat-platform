{{--
    E44: مدخلا «كتّاب الموسم» و«داعمو الموسم» في صفحة الموسم. لا دوري ولا تقدير ⇒ صفر إخراج. الأسماء نص مُهرَّب؛ لا هوية ناخب ولا مبالغ.
--}}
@if ($recognition['any'])
    @php $medal = [1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث']; @endphp
    <section {{ $attributes->class('grid gap-3 md:grid-cols-2 anim-fade-up') }} aria-label="التقدير الموسمي" data-seasonal-recognition>
        @if ($recognition['writers'])
            <a href="{{ $recognition['writers']['url'] }}" class="puzzle-card !p-5 block hover:border-amethyst/40 transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <x-ui-icon name="trophy" class="w-5 h-5 text-gold" />
                    <h3 class="font-black text-white">كتّاب الموسم</h3>
                    <x-status-badge :tone="$recognition['writers']['finalized'] ? 'primary' : 'success'" small>{{ $recognition['writers']['finalized'] ? 'الترتيب النهائي' : 'ترتيب حيّ' }}</x-status-badge>
                </div>
                @if ($recognition['writers']['podium'] !== [])
                    <ul class="text-sm text-slate-300 space-y-0.5">
                        @foreach ($recognition['writers']['podium'] as $p)
                            <li><span class="text-gold font-black">{{ $medal[$p['pos']] }}</span> — {{ $p['name'] }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-slate-400">صوّت لكتّاب الموسم بنجومك وتابع الترتيب مباشرةً.</p>
                @endif
            </a>
        @endif

        @if ($recognition['supporters'])
            <a href="{{ $recognition['supporters']['url'] }}" class="puzzle-card !p-5 block hover:border-amethyst/40 transition motion-reduce:transition-none focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <x-ui-icon name="gift" class="w-5 h-5 text-gold" />
                    <h3 class="font-black text-white">داعمو الموسم</h3>
                    <x-status-badge :tone="$recognition['supporters']['finalized'] ? 'primary' : 'success'" small>{{ $recognition['supporters']['finalized'] ? 'الترتيب النهائي' : 'مفتوح' }}</x-status-badge>
                </div>
                <p class="text-sm text-slate-400">بطاقات الداعم ونقاط الدعم وترتيب الداعمين. للتقدير فقط، دون أي أفضلية لعب.</p>
            </a>
        @endif
    </section>
@endif
