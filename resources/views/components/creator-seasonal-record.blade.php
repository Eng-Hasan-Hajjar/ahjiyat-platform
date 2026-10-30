{{-- E44: سجل دوري الكتّاب على صفحة المبدع (مواسم مُنهاة فقط، بلا ذكر «خارج الموسم التالي»). بلا سجل ⇒ صفر إخراج. --}}
@if ($records !== [])
    <section {{ $attributes->class('puzzle-card !p-5 md:!p-6 anim-fade-up') }} aria-labelledby="creator-seasonal-title" data-creator-seasonal-record>
        <h2 id="creator-seasonal-title" class="font-display font-black text-xl text-white mb-3">دوري كتّاب الموسم</h2>
        <ul class="space-y-2">
            @foreach ($records as $r)
                <li class="flex flex-wrap items-center gap-2 text-sm">
                    <a href="{{ $r['url'] }}" class="font-black text-white hover:underline break-words">{{ $r['season'] }}</a>
                    <x-status-badge :tone="$r['place'] ? 'success' : 'neutral'" small>{{ $r['label'] }}</x-status-badge>
                </li>
            @endforeach
        </ul>
    </section>
@endif
