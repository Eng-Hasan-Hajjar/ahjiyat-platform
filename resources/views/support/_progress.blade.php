{{-- E32: تقدّم هدف التمويل (يُعرض فقط إن فعّل البرنامج show_goal_progress). المبالغ الدقيقة فقط إن فعّل show_public_amounts. أرقام مؤكَّدة فقط. --}}
@if ($card['progress'] !== null)
    <div>
        <div class="flex items-center justify-between text-xs font-bold text-slate-400 mb-1">
            <span>تقدّم الهدف</span>
            <span class="text-gold">{{ $card['progress'] }}٪</span>
        </div>
        <div class="h-2 rounded-full bg-white/5 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $card['progress'] }}" aria-label="تقدّم هدف التمويل">
            <div class="h-full bg-gradient-to-l from-amethyst to-gold" style="width: {{ $card['progress'] }}%"></div>
        </div>
        @if ($program->show_public_amounts)
            <p class="text-xs text-slate-400 mt-1">{{ \App\Services\Sponsorship\SponsorshipMoney::format($card['funding'], $program->accounting_currency) }} من {{ \App\Services\Sponsorship\SponsorshipMoney::format((int) $program->goal_amount_minor, $program->accounting_currency) }}</p>
        @endif
    </div>
@endif
