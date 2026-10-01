@php
    /** @var \App\Models\QuestDefinition $quest */
    /** @var \App\Models\UserQuestProgress $progress */
    $current = min($progress->current_value, $progress->target_value_snapshot);
    $isCompleted = $progress->completed_at !== null;
    $rewardPending = $isCompleted && $progress->reward_granted_at === null;
@endphp

<div class="puzzle-card !p-4">
    <div class="flex items-start justify-between gap-3 mb-2">
        <div class="min-w-0">
            <h3 class="font-bold text-white truncate">{{ $quest->name }}</h3>
            @if ($quest->description)
                <p class="text-xs text-slate-500 mt-0.5">{{ $quest->description }}</p>
            @endif
        </div>
        @if ($isCompleted)
            <span class="chip !py-1 !px-2.5 text-xs !text-emerald !border-emerald/40 shrink-0">
                {{ $rewardPending ? 'مكتملة - جارٍ معالجة المكافأة' : 'مكتملة' }}
            </span>
        @endif
    </div>

    <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1.5">
        <span>{{ $current }} / {{ $progress->target_value_snapshot }}</span>
    </div>

    <div class="w-full h-2 rounded-full bg-white/5 overflow-hidden mb-3"
         role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress->target_value_snapshot }}" aria-valuenow="{{ $current }}"
         aria-label="تقدُّم {{ $quest->name }}">
        <div class="h-full rounded-full {{ $isCompleted ? 'bg-emerald' : 'bg-gradient-to-l from-amethyst to-gold' }} transition-all"
             style="width: {{ $percent }}%"></div>
    </div>

    <div class="flex flex-wrap gap-2 text-xs font-bold">
        @if ($quest->xp_reward > 0)
            <span class="chip !py-1 !px-2.5">+{{ $quest->xp_reward }} XP</span>
        @endif
        @if ($quest->reward_currency_amount)
            <span class="chip !py-1 !px-2.5">+{{ $quest->reward_currency_amount }} {{ $quest->rewardCurrency?->short_name ?? 'عملة' }}</span>
        @endif
        @if ($quest->reward_store_item_id)
            <span class="chip !py-1 !px-2.5">{{ $quest->rewardStoreItem?->name }}</span>
        @endif
    </div>
</div>
