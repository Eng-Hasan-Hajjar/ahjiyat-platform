<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- تحتاج إجراء الآن --}}
        @if ($this->getPendingRedemptionsCount() !== null || $this->getOpenFraudFlagsCount() !== null)
            <x-filament::section>
                <x-slot name="heading">تحتاج إجراء الآن</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
                    @if ($this->getPendingRedemptionsCount() !== null)
                        <a href="{{ \App\Filament\Resources\RedemptionRequestResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900;">{{ $this->getPendingRedemptionsCount() }}</div>
                                <div style="color:#94a3b8;">طلبات استبدال معلَّقة</div>
                            </x-filament::section>
                        </a>
                    @endif
                    @if ($this->getOpenFraudFlagsCount() !== null)
                        <a href="{{ \App\Filament\Resources\FraudFlagResource::getUrl() }}" style="text-decoration:none;">
                            <x-filament::section>
                                <div style="font-size:2rem; font-weight:900; color:#fb7185;">{{ $this->getOpenFraudFlagsCount() }}</div>
                                <div style="color:#94a3b8;">إشارات أمنية مفتوحة</div>
                            </x-filament::section>
                        </a>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- أمان الحسابات --}}
        @if ($this->getFrozenUsersCount() !== null)
            <x-filament::section>
                <x-slot name="heading">أمان الحسابات</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">
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
                            <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
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
                            <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
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
                            <div style="display:flex; justify-content:space-between; font-size:.85rem; padding:.4rem 0; border-bottom:1px solid rgba(148,163,184,.15);">
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