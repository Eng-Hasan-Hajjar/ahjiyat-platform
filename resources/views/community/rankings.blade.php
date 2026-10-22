@extends('layouts.app')

@section('title', 'ترتيبات المجتمع')
@section('meta_description', 'أفضل مبدعي أحجيات المجتمع ومنسّقي المجموعات والمساهمين المفيدين على أحجيات، بمعايير جودة شفّافة.')
@section('canonical', route('community.rankings'))
@section('content_width', 'max-w-6xl')

{{-- E37: ترتيبات الجودة العامة. قراءة فقط. لا درجات خام: المتوسط والأعداد المجمَّعة فقط. لا أثر لدعم/دفع/جواهر/متجر/إحالات على أي ترتيب (يُصرَّح بذلك أدناه). نصوص مهرَّبة. --}}
@section('content')
    <div class="space-y-8">
        <x-page-header title="ترتيبات المجتمع" icon="trophy" subtitle="اعتراف بجودة ما يصنعه الأعضاء ويشاركونه. بلا جوائز اقتصادية، ولا يشتريه دعم أو رعاية." class="anim-fade-up !mb-0">
            <x-slot:actions>
                <a href="{{ route('community.puzzles.index') }}" class="chip !py-2 text-sm">أحجيات المجتمع</a>
            </x-slot:actions>
        </x-page-header>

        @if (count($featured) > 0)
            <section aria-labelledby="featured-heading" class="anim-fade-up">
                <x-section-header title="مبدعون مختارون" id="featured-heading" />
                <p class="text-xs text-slate-400 -mt-2 mb-3">اختيار تحريري من فريق المنصة، منفصل عن الترتيبات أدناه.</p>
                <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($featured as $f)
                        <li>
                            <a href="{{ route('creators.show', $f['slug']) }}" class="glass rounded-2xl p-4 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-gold/15 text-gold"><x-ui-icon name="trophy" class="w-5 h-5" /></span>
                                <span class="min-w-0">
                                    <span class="block font-display font-black text-white break-words">{{ $f['name'] }}</span>
                                    <x-status-badge tone="warning" :small="true">مبدع مختار</x-status-badge>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid lg:grid-cols-2 gap-8">
            <section aria-labelledby="creators-heading" class="anim-fade-up">
                <x-section-header title="أفضل المبدعين" id="creators-heading" />
                <p class="text-xs text-slate-400 -mt-2 mb-3">يُحتسب من تقييم الأعمال (بعد تهذيب بايزي يمنع قفز تقييم واحد)، وتفاعل اللاعبين، واستمرارية النشر. يشترط {{ $minWorks }} أعمال ظاهرة و{{ $minRatings }} تقييمات على الأقل.</p>
                @if (count($creators) === 0)
                    <x-empty-state icon="friends" title="لا ترتيب بعد" message="يظهر المبدع هنا حين تتوفر أدلة كافية على جودة أعماله." />
                @else
                    <ol class="space-y-2">
                        @foreach ($creators as $row)
                            <li>
                                <a href="{{ route('creators.show', $row['slug']) }}" class="glass rounded-2xl p-3 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                    <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-amethyst/15 text-amethyst font-black" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-display font-black text-white break-words">{{ $row['name'] }}</span>
                                            @if ($row['tier']) <x-status-badge tone="primary" :small="true">{{ $row['tier'] }}</x-status-badge> @endif
                                        </span>
                                        <span class="block text-[11px] text-slate-400 mt-0.5">
                                            @if ($row['average'] !== null) ★ {{ number_format($row['average'], 1) }} ({{ number_format($row['ratings']) }} تقييم) · @endif
                                            {{ number_format($row['works']) }} عمل · {{ number_format($row['favorites']) }} إضافة للمفضلة
                                        </span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section aria-labelledby="rising-heading" class="anim-fade-up">
                <x-section-header title="مبدعون صاعدون" id="rising-heading" />
                <p class="text-xs text-slate-400 -mt-2 mb-3">نشاط وجودة آخر {{ $windowDays }} يومًا فقط، ويصلح للمبدع الجديد. عدد المنشورات لا يدخل في الحساب.</p>
                @if (count($rising) === 0)
                    <x-empty-state icon="friends" title="لا صاعدون الآن" message="يظهر هنا من نالت أعماله تقييمات جيدة حديثة وتفاعلًا متسارعًا." />
                @else
                    <ol class="space-y-2">
                        @foreach ($rising as $row)
                            <li>
                                <a href="{{ route('creators.show', $row['slug']) }}" class="glass rounded-2xl p-3 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                    <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-emerald/10 text-emerald font-black" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-display font-black text-white break-words">{{ $row['name'] }}</span>
                                        <span class="block text-[11px] text-slate-400 mt-0.5">
                                            @if ($row['recent_average'] !== null) ★ {{ number_format($row['recent_average'], 1) }} من {{ number_format($row['recent_ratings']) }} تقييم حديث · @endif
                                            {{ number_format($row['recent_favorites']) }} مفضلة جديدة
                                        </span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section aria-labelledby="curators-heading" class="anim-fade-up">
                <x-section-header title="أفضل المنسّقين" id="curators-heading" />
                <p class="text-xs text-slate-400 -mt-2 mb-3">أصحاب مجموعات عامة يحفظها لاعبون مختلفون. الحفظ المتكرر من لاعب واحد يُحتسب مرة واحدة.</p>
                @if (count($curators) === 0)
                    <x-empty-state icon="friends" title="لا ترتيب بعد" message="تظهر هنا مجموعات عامة حفظها عدد كافٍ من اللاعبين." />
                @else
                    <ol class="space-y-2">
                        @foreach ($curators as $row)
                            <li>
                                <a href="{{ route('players.show', $row['public_id']) }}" class="glass rounded-2xl p-3 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                    <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-amethyst/15 text-amethyst font-black" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-display font-black text-white break-words">{{ $row['name'] }}</span>
                                            @if ($row['recognition']) <x-status-badge tone="primary" :small="true">{{ $row['recognition'] }}</x-status-badge> @endif
                                        </span>
                                        <span class="block text-[11px] text-slate-400 mt-0.5">{{ number_format($row['collections']) }} مجموعة عامة · حفظها {{ number_format($row['savers']) }} لاعب</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            @if ($helpful !== null)
                <section aria-labelledby="helpful-heading" class="anim-fade-up">
                    <x-section-header title="مساهمون مفيدون" id="helpful-heading" />
                    <p class="text-xs text-slate-400 -mt-2 mb-3">أعضاء أعجب بتعليقاتهم أعضاء مختلفون. لا يُحتسب عدد التعليقات ولا إعجاب الشخص بنفسه.</p>
                    @if (count($helpful) === 0)
                        <x-empty-state icon="friends" title="لا ترتيب بعد" message="يظهر هنا من نالت تعليقاته إعجابات من عدد كافٍ من الأعضاء." />
                    @else
                        <ol class="space-y-2">
                            @foreach ($helpful as $row)
                                <li>
                                    <a href="{{ route('players.show', $row['public_id']) }}" class="glass rounded-2xl p-3 flex items-center gap-3 hover:border-amethyst/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst transition motion-reduce:transition-none">
                                        <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-amethyst/15 text-amethyst font-black" aria-label="المرتبة {{ $row['rank'] }}">{{ $row['rank'] }}</span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-display font-black text-white break-words">{{ $row['name'] }}</span>
                                            <span class="block text-[11px] text-slate-400 mt-0.5">أعجب بتعليقاته {{ number_format($row['likers']) }} عضو</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            @endif
        </div>

        <section class="glass rounded-2xl p-5 text-sm text-slate-300 space-y-2 anim-fade-up" aria-labelledby="how-heading">
            <h2 id="how-heading" class="font-display font-black text-white">كيف تُحسب هذه الترتيبات؟</h2>
            <ul class="list-disc ps-5 space-y-1 text-slate-300">
                <li>تُحسب من تقييمات ومفضلات ومتابعات وحفظ وإعجابات <strong>أعضاء آخرين</strong> فقط؛ تفاعل الشخص مع محتواه لا يُحتسب.</li>
                <li>لا يدخل في الحساب أي دعم أو رعاية أو دفع أو جواهر أو مشتريات أو إحالات، ولا يمكن شراء مكان في الترتيب.</li>
                <li>المحتوى المخفي أو غير المنشور لا يُحتسب، والحسابات المعلّقة أو المجمّدة لا تظهر.</li>
                <li>تُحدَّث الترتيبات دوريًا، لذا قد يتأخر ظهور التغيير قليلًا.</li>
                <li>الاعتراف هنا وسام تقدير لا يمنح أي مكافأة اقتصادية.</li>
            </ul>
        </section>
    </div>
@endsection
