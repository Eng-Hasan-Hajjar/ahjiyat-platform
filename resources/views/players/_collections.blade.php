{{--
    E35: قسم «مجموعات» بالملف العام (أمين المجتمع). يعرض المجموعات العامة المُدرَجة الآن فقط (CuratorCollections: public ∧ غير مخفية ∧ ملف عام ∧ فيها عمل ظاهر ∧ بلا حظر).
    الغياب = لا قسم (لا عنوان فارغ). غير المدرجة والخاصة لا تظهر هنا أبدًا.
--}}
@if (isset($collections) && $collections->isNotEmpty())
    <section class="anim-fade-up" aria-labelledby="player-collections-title" data-player-collections>
        <h2 id="player-collections-title" class="font-display font-black text-lg text-white mb-3">مجموعات {{ $player->name }}</h2>
        <div class="grid sm:grid-cols-2 gap-3">
            @foreach ($collections as $c)
                @include('collections._card', ['c' => $c])
            @endforeach
        </div>
    </section>
@endif
