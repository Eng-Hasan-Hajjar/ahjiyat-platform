<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- تحتاج إجراء الآن --}}
        @if ($this->getPendingRedemptionsCount() !== null || $this->getOpenFraudFlagsCount() !== null || $this->getPendingStoreFulfillmentCount() !== null || $this->getFailedCompetitiveGrantsCount() !== null || $this->getOverdueTeamCompetitionCount() !== null)
            <x-filament::section>
                <x-slot name="heading">تحتاج إجراء الآن</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
                    @if ($this->getPendingRedemptionsCount() !== null)
                        <a href="{{ \App\Filament\Resources\RedemptionRequestResource::getUrl() }}"
                            style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900;">{{ $this->getPendingRedemptionsCount() }}</div>
                                <div style="color:#94a3b8;">طلبات استبدال معلَّقة</div>
                            </x-filament::section>
                        </a>
                    @endif
                    @if ($this->getOverdueTeamCompetitionCount() !== null)
                        <a href="{{ \App\Filament\Resources\TeamChallengeResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900; color:#f59e0b;">{{ $this->getOverdueTeamCompetitionCount() }}</div>
                                <div style="color:#94a3b8;">منافسات فرق متأخرة الاعتماد</div>
                            </x-filament::section>
                        </a>
                    @endif
                    @if ($this->getFailedCompetitiveGrantsCount() !== null)
                        <a href="{{ \App\Filament\Resources\CompetitiveEventResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900; color:#f59e0b;">{{ $this->getFailedCompetitiveGrantsCount() }}</div>
                                <div style="color:#94a3b8;">جوائز تنافسية فاشلة</div>
                            </x-filament::section>
                        </a>
                    @endif
                    @if ($this->getOpenFraudFlagsCount() !== null)
                        <a href="{{ \App\Filament\Resources\FraudFlagResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900; color:#fb7185;">
                                    {{ $this->getOpenFraudFlagsCount() }}</div>
                                <div style="color:#94a3b8;">إشارات أمنية مفتوحة</div>
                            </x-filament::section>
                        </a>
                    @endif
                    @if ($this->getPendingStoreFulfillmentCount() !== null)
                        <a href="{{ \App\Filament\Resources\StorePurchaseResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900; color:#fbbf24;">
                                    {{ $this->getPendingStoreFulfillmentCount() }}</div>
                                <div style="color:#94a3b8;">طلبات متجر بانتظار الإنجاز اليدوي</div>
                            </x-filament::section>
                        </a>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- E25: صحة النظام (قراءة فقط) --}}
        @if (($signals = $this->getSystemSignals()) !== null)
            <x-filament::section>
                <x-slot name="heading">صحة النظام (قراءة فقط)</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">
                    <x-filament::section>
                        <div style="font-size:1.4rem; font-weight:900;" dir="ltr">{{ $signals['release'] ?? 'غير مضبوط' }}</div>
                        <div style="color:#94a3b8;">الإصدار (APP_RELEASE) · البيئة: <span dir="ltr">{{ $signals['environment'] }}</span></div>
                        @if ($signals['debug'])
                            <div style="color:#fb7185; font-weight:800;">APP_DEBUG مفعَّلة!</div>
                        @endif
                        @if ($signals['maintenance'])
                            <div style="color:#f59e0b; font-weight:800;">وضع الصيانة مفعَّل</div>
                        @endif
                    </x-filament::section>
                    <x-filament::section>
                        <div style="font-size:1.4rem; font-weight:900; color:{{ $signals['scheduler']['status'] === 'ok' ? '#34d399' : ($signals['scheduler']['status'] === 'unknown' ? '#94a3b8' : '#f59e0b') }};">{{ $this->schedulerLabel($signals['scheduler']['status']) }}</div>
                        <div style="color:#94a3b8;">المجدول @if ($signals['scheduler']['age_seconds'] !== null)· آخر نبضة منذ {{ $signals['scheduler']['age_seconds'] }} ث @endif</div>
                    </x-filament::section>
                    <x-filament::section>
                        <div style="font-size:1.4rem; font-weight:900;">{{ $signals['queue']['pending'] ?? '—' }} <span style="font-size:.9rem; color:#94a3b8;">منتظرة</span></div>
                        <div style="color:#94a3b8;">الطابور (<span dir="ltr">{{ $signals['queue']['driver'] }}</span>)
                            @if ($signals['queue']['oldest_age_seconds'] !== null)· أقدمها منذ {{ intdiv($signals['queue']['oldest_age_seconds'], 60) }} د @endif
                            · فاشلة: {{ $signals['queue']['failed'] ?? '—' }}</div>
                    </x-filament::section>
                    <x-filament::section>
                        <div style="font-size:1rem; font-weight:800;" dir="ltr">DB {{ $signals['readiness']['database']['ok'] ? 'OK' : 'DOWN' }} · {{ $signals['readiness']['database']['ms'] }}ms</div>
                        <div style="font-size:1rem; font-weight:800;" dir="ltr">Cache {{ $signals['readiness']['cache']['ok'] ? 'OK' : 'DOWN' }} · {{ $signals['readiness']['cache']['ms'] }}ms</div>
                        <div style="color:#94a3b8;">الجاهزية (قراءة فقط)</div>
                    </x-filament::section>
                </div>

                <div style="color:#94a3b8; font-size:.85rem; margin-bottom:.5rem;" dir="ltr">
                    cache={{ $signals['drivers']['cache'] }} · session={{ $signals['drivers']['session'] }} · queue={{ $signals['drivers']['queue'] }} · broadcast={{ $signals['drivers']['broadcast'] }} · mail={{ $signals['drivers']['mail'] }}
                </div>

                @foreach ($signals['queue_warnings'] as $warning)
                    <div style="color:#f59e0b; font-size:.85rem;">⚠ {{ $warning }}</div>
                @endforeach

                <div style="color:#64748b; font-size:.75rem; margin-top:.5rem;">لقطة مخزَّنة 30 ثانية · {{ \Illuminate\Support\Carbon::parse($signals['generated_at'])->diffForHumans() }}</div>
            </x-filament::section>
        @endif

        {{-- أمان الحسابات --}}
        @if ($this->getFrozenUsersCount() !== null)
            <x-filament::section>
                <x-slot name="heading">أمان الحسابات</x-slot>
                <div
                    style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">
                    <x-filament::section>
                        <div style="font-size:2rem; font-weight:900;">{{ $this->getFrozenUsersCount() }}</div>
                        <div style="color:#94a3b8;">حسابات مجمَّدة</div>
                    </x-filament::section>
                    <x-filament::section>
                        <div style="font-size:2rem; font-weight:900;">{{ $this->getUnverifiedUsersCount() }}</div>
                        <div style="color:#94a3b8;">حسابات غير موثَّقة</div>
                    </x-filament::section>
                </div>

                @if ($this->getRecentFraudFlags()->isNotEmpty())
                    <div style="font-weight:800; margin-bottom:.5rem;">أحدث الإشارات الأمنية المفتوحة</div>
                    <div style="display:flex; flex-direction:column; gap:.4rem;">
                        @foreach ($this->getRecentFraudFlags() as $flag)
                            <div
                                style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
                                <span>{{ $flag->user?->name ?? '—' }} - {{ $flag->reason }}</span>
                                <span style="color:#64748b;">{{ $flag->created_at->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- نشاط حديث --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1.5rem;">
            @if ($this->getRecentUsers()->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">تسجيلات حديثة</x-slot>
                    <div style="display:flex; flex-direction:column; gap:.4rem;">
                        @foreach ($this->getRecentUsers() as $user)
                            <div
                                style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
                                <span>{{ $user->name }}</span>
                                <span style="color:#64748b;">{{ $user->created_at->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($this->getRecentOperationalActions()->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">آخر الإجراءات الإدارية</x-slot>
                    <div style="display:flex; flex-direction:column; gap:.4rem;">
                        @foreach ($this->getRecentOperationalActions() as $log)
                            <div
                                style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
                                <span>{{ $log->actor?->name ?? 'نظام' }} - {{ $this->actionLabel($log->action) }}</span>
                                <span style="color:#64748b;">{{ $log->created_at->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>

    </div>
</x-filament-panels::page>