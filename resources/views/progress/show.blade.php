@extends('layouts.app')

@section('title', 'تقدُّمي')

@section('content')

    <div class="max-w-3xl mx-auto">

        <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-6 text-center anim-fade-up">
            يمكنك التقدم ورفع مستواك وفتح الإنجازات من خلال اللعب دون الحاجة إلى شراء أي عملة.
        </div>

        <div class="puzzle-card !p-6 md:!p-8 mb-8 anim-fade-up d-1">
            <div class="flex items-center gap-4 mb-5">
                @if ($currentLevel->icon_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($currentLevel->icon_path) }}" alt="" class="w-14 h-14 rounded-xl object-cover">
                @else
                    <span class="gem-facet w-14 h-14 grid place-items-center text-xl font-black text-white bg-gradient-to-br from-amethyst to-gold shrink-0">
                        {{ $currentLevel->level_number }}
                    </span>
                @endif
                <div class="min-w-0">
                    <span class="block text-xs font-bold text-slate-500">المستوى الحالي</span>
                    <h1 class="font-display font-black text-xl md:text-2xl text-white truncate" style="{{ $currentLevel->color ? 'color:'.$currentLevel->color : '' }}">
                        {{ $currentLevel->name }}
                    </h1>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-2">
                <span>{{ number_format($progression->total_xp) }} XP</span>
                @if ($nextLevel)
                    <span>{{ number_format($nextLevel->xp_required_total) }} XP للمستوى التالي</span>
                @else
                    <span>أعلى مستوى حاليًا</span>
                @endif
            </div>

            <div class="w-full h-3 rounded-full bg-white/5 overflow-hidden" role="progressbar"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPercent }}"
                 aria-label="نسبة التقدم للمستوى التالي">
                <div class="h-full rounded-full bg-gradient-to-l from-amethyst to-gold transition-all" style="width: {{ $progressPercent }}%"></div>
            </div>

            @if ($nextLevel)
                <p class="text-xs text-slate-500 mt-2">{{ $progressPercent }}% نحو "{{ $nextLevel->name }}"</p>
            @else
                <p class="text-xs text-slate-500 mt-2">استمر باللعب - رصيدك من XP يتابع الازدياد.</p>
            @endif
        </div>

        <div class="mb-8 anim-fade-up d-2">
            <h2 class="font-display font-black text-lg text-white mb-3">المستويات</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($allLevels as $level)
                    @php $reached = $level->level_number <= $progression->current_level; @endphp
                    <span class="chip !py-1.5 !px-3 text-xs {{ $reached ? '!text-emerald !border-emerald/40' : '!text-slate-500' }}">
                        {{ $level->level_number }}. {{ $level->name }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="anim-fade-up d-3">
            <h2 class="font-display font-black text-lg text-white mb-3">الإنجازات</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach ($achievements as $row)
                    @php $a = $row['achievement']; @endphp
                    <div class="puzzle-card {{ $row['is_unlocked'] ? '!border-emerald/40' : '' }}">
                        <div class="flex items-center gap-3 mb-2">
                            @if ($row['reveal'] && $a->icon_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($a->icon_path) }}" alt="" class="w-10 h-10 rounded-lg object-cover">
                            @else
                                <span class="w-10 h-10 rounded-lg bg-white/5 grid place-items-center text-lg">{{ $row['reveal'] ? '🏆' : '❓' }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <span class="block font-bold text-white text-sm truncate">
                                    {{ $row['reveal'] ? $a->name : 'إنجاز سري' }}
                                </span>
                                @if ($row['is_unlocked'])
                                    <span class="text-xs text-emerald font-bold">مُفتَح · {{ $row['unlocked_at']->format('Y-m-d') }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($row['reveal'])
                            @if ($a->description)
                                <p class="text-xs text-slate-400 mb-3">{{ $a->description }}</p>
                            @endif

                            @if ($a->target_value)
                                @php $pct = min(100, round(($row['current_value'] / $a->target_value) * 100)); @endphp
                                <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden mb-1"
                                     role="progressbar" aria-valuemin="0" aria-valuemax="{{ $a->target_value }}" aria-valuenow="{{ $row['current_value'] }}">
                                    <div class="h-full rounded-full {{ $row['is_unlocked'] ? 'bg-emerald' : 'bg-amethyst' }}" style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500">{{ min($row['current_value'], $a->target_value) }} / {{ $a->target_value }}</span>
                            @endif

                            @php
                                $rewardParts = [];
                                if ($a->xp_reward > 0) $rewardParts[] = "+{$a->xp_reward} XP";
                                if ($a->reward_currency_amount) $rewardParts[] = "+{$a->reward_currency_amount} من العملة المكتسَبة";
                                if ($a->reward_store_item_id) $rewardParts[] = 'عنصر خاص';
                            @endphp
                            @if ($rewardParts !== [])
                                <p class="text-xs text-gold font-bold mt-2">{{ implode(' · ', $rewardParts) }}</p>
                            @endif
                        @else
                            <p class="text-xs text-slate-500">استمر باللعب لتكتشف هذا الإنجاز.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    </div>

@endsection