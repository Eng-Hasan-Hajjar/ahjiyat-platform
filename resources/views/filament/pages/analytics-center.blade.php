<x-filament-panels::page>
    <div style="display:flex; flex-direction:column; gap:1.5rem;">

        {{-- مُرشِّح الفترة العام - يُطبَّق على كل الأقسام أدناه (بند 7/8) --}}
        <x-filament::section>
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:1rem;">
                <label style="font-weight:800; font-size:.85rem;">الفترة:</label>
                <select wire:model.live="preset" style="padding:.5rem .8rem; border-radius:.6rem; border:1px solid rgba(148,163,184,.3); background:rgba(255,255,255,.04); color:inherit;">
                    @foreach ($this->presetOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                @if ($preset === 'custom')
                    <input type="date" wire:model.live="customStart" style="padding:.4rem .6rem; border-radius:.5rem; border:1px solid rgba(148,163,184,.3); background:rgba(255,255,255,.04); color:inherit;">
                    <span>إلى</span>
                    <input type="date" wire:model.live="customEnd" style="padding:.4rem .6rem; border-radius:.5rem; border:1px solid rgba(148,163,184,.3); background:rgba(255,255,255,.04); color:inherit;">
                @endif

                <span style="color:#94a3b8; font-size:.8rem;">{{ $this->getPeriod()->start->format('Y-m-d') }} — {{ $this->getPeriod()->end->format('Y-m-d') }}</span>

                <button wire:click="refreshData" type="button" style="margin-inline-start:auto; padding:.4rem .9rem; border-radius:.6rem; border:1px solid rgba(148,163,184,.3); background:rgba(139,92,246,.12); color:inherit; font-size:.78rem; font-weight:700; cursor:pointer;">
                    تحديث البيانات
                </button>
            </div>
        </x-filament::section>

        {{-- التبويبات --}}
        <div style="display:flex; flex-wrap:wrap; gap:.5rem; border-bottom:1px solid rgba(148,163,184,.2); padding-bottom:.5rem;">
            @php
                $tabs = ['overview' => 'نظرة عامة'];
                if (auth()->user()?->can('analytics.users')) $tabs['users'] = 'المستخدمون';
                if (auth()->user()?->can('analytics.puzzles')) $tabs['puzzles'] = 'الأحجيات';
                if (auth()->user()?->can('analytics.campaigns')) $tabs['campaigns'] = 'الحملات والمواسم';
                if (auth()->user()?->can('analytics.financial')) $tabs['economy'] = 'الجواهر والاستبدال';
                if (auth()->user()?->can('analytics.security')) $tabs['security'] = 'الأمان';
                if (auth()->user()?->can('reports.export')) $tabs['reports'] = 'التقارير';
            @endphp
            @foreach ($tabs as $key => $label)
                <button wire:click="$set('activeTab', '{{ $key }}')" type="button"
                    style="padding:.5rem 1rem; border-radius:.6rem 0.6rem 0 0; border:none; cursor:pointer; font-weight:700; font-size:.85rem;
                    background:{{ $activeTab === $key ? 'rgba(139,92,246,.18)' : 'transparent' }}; color:inherit;">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- نظرة عامة --}}
        @if ($activeTab === 'overview' && ($overview = $this->executiveOverview()))
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                @php
                    $cards = [
                        'إجمالي المستخدمين' => $overview['total_users'],
                        'مستخدمون جدد بالفترة' => $overview['new_users'].($overview['new_users_change'] !== null ? ' ('.($overview['new_users_change'] >= 0 ? '+' : '').$overview['new_users_change'].'%)' : ''),
                        'مستخدمون نشطون' => $overview['active_users'],
                        'محاولات الأحجيات' => $overview['total_attempts'],
                        'نسبة النجاح' => $overview['success_rate'].'%',
                        'المواسم المنشورة' => $overview['active_seasons'],
                    ];
                    if ($overview['gems_issued'] !== null) $cards['جواهر ممنوحة بالفترة'] = number_format($overview['gems_issued']);
                    if ($overview['pending_redemptions'] !== null) $cards['طلبات استبدال معلَّقة'] = $overview['pending_redemptions'];
                    if ($overview['open_fraud_flags'] !== null) $cards['إشارات أمنية مفتوحة'] = $overview['open_fraud_flags'];
                @endphp
                @foreach ($cards as $label => $value)
                    <x-filament::section>
                        <div style="font-size:1.7rem; font-weight:900;">{{ $value }}</div>
                        <div style="color:#94a3b8; font-size:.82rem;">{{ $label }}</div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif

        {{-- المستخدمون --}}
        @if ($activeTab === 'users' && ($u = $this->userAnalytics()))
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                @foreach ([
                    'إجمالي' => $u['overview']['total_users'], 'جدد بالفترة' => $u['overview']['new_users'],
                    'موثَّقون' => $u['overview']['verified_users'], 'غير موثَّقين' => $u['overview']['unverified_users'],
                    'مجمَّدون' => $u['overview']['frozen_users'], 'نشطون' => $u['overview']['active_users'],
                ] as $label => $value)
                    <x-filament::section><div style="font-size:1.4rem; font-weight:900;">{{ $value }}</div><div style="color:#94a3b8; font-size:.8rem;">{{ $label }}</div></x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <x-slot name="heading">توزيع الأدوار</x-slot>
                <p style="color:#94a3b8; font-size:.78rem; margin-bottom:.6rem;">مستخدم بأكثر من دور واحد يظهر بأكثر من فئة - المجموع قد يتجاوز عدد المستخدمين الكلي.</p>
                <div style="display:flex; flex-wrap:wrap; gap:.6rem;">
                    @foreach ($u['role_distribution'] as $role)
                        <span style="padding:.3rem .8rem; border-radius:999px; background:rgba(139,92,246,.12); font-size:.8rem;">{{ $role['label'] }}: {{ $role['count'] }}</span>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">التفاعل (Engagement)</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:.8rem;">
                    <div>مستخدمون لديهم محاولات: <strong>{{ $u['engagement']['users_with_attempts'] }}</strong></div>
                    <div>مستخدمون بتقدُّم بالحملات: <strong>{{ $u['engagement']['users_with_campaign_progress'] }}</strong></div>
                    <div>مستخدمون أكملوا خطوات: <strong>{{ $u['engagement']['users_completing_steps'] }}</strong></div>
                </div>
            </x-filament::section>
        @endif

        {{-- الأحجيات --}}
        @if ($activeTab === 'puzzles' && ($p = $this->puzzleAnalytics()))
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:1rem;">
                @foreach ([
                    'إجمالي الأحجيات' => $p['overview']['total_puzzles'], 'مفعَّلة' => $p['overview']['active_puzzles'],
                    'المحاولات' => $p['overview']['total_attempts'], 'صحيحة' => $p['overview']['correct_attempts'],
                    'خاطئة' => $p['overview']['incorrect_attempts'], 'نسبة النجاح' => $p['overview']['success_rate'].'%',
                    'لاعبون فريدون' => $p['overview']['unique_players'],
                ] as $label => $value)
                    <x-filament::section><div style="font-size:1.3rem; font-weight:900;">{{ $value }}</div><div style="color:#94a3b8; font-size:.78rem;">{{ $label }}</div></x-filament::section>
                @endforeach
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem;">
                <x-filament::section>
                    <x-slot name="heading">الأكثر لعباً</x-slot>
                    @forelse ($p['top']['most_played'] as $row)
                        <div style="display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid rgba(148,163,184,.1); font-size:.85rem;">
                            <span>{{ $row['title'] }}</span><span>{{ $row['attempts'] }}</span>
                        </div>
                    @empty <p style="color:#64748b;">لا بيانات كافية بهذه الفترة.</p> @endforelse
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">أعلى نسبة نجاح (حد أدنى {{ $p['top']['min_sample_size'] }} محاولات)</x-slot>
                    @forelse ($p['top']['highest_success_rate'] as $row)
                        <div style="display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid rgba(148,163,184,.1); font-size:.85rem;">
                            <span>{{ $row['title'] }}</span><span>{{ $row['success_rate'] }}%</span>
                        </div>
                    @empty <p style="color:#64748b;">لا بيانات كافية بهذه الفترة.</p> @endforelse
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">أدنى نسبة نجاح (نفس الحد الأدنى)</x-slot>
                    @forelse ($p['top']['lowest_success_rate'] as $row)
                        <div style="display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid rgba(148,163,184,.1); font-size:.85rem;">
                            <span>{{ $row['title'] }}</span><span>{{ $row['success_rate'] }}%</span>
                        </div>
                    @empty <p style="color:#64748b;">لا بيانات كافية بهذه الفترة.</p> @endforelse
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">حسب مستوى الصعوبة</x-slot>
                <div style="display:flex; gap:1.5rem; flex-wrap:wrap;">
                    @foreach ($p['difficulty'] as $row)
                        <div>{{ $row['difficulty'] }}: {{ $row['attempts'] }} محاولة - نجاح {{ $row['success_rate'] }}%</div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">التحديات</x-slot>
                <div>إجمالي: {{ $p['challenges']['total_challenges'] }} - نشطة الآن: {{ $p['challenges']['active_challenges'] }} - مشاركون: {{ $p['challenges']['total_participants'] }}</div>
            </x-filament::section>
        @endif

        {{-- الحملات والمواسم --}}
        @if ($activeTab === 'campaigns' && ($c = $this->campaignAnalytics()))
            @foreach ($c['campaigns'] as $campaign)
                <x-filament::section>
                    <x-slot name="heading">{{ $campaign['title'] }}</x-slot>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:.8rem; margin-bottom:1rem;">
                        <div>بدأوا: <strong>{{ $campaign['started'] }}</strong></div>
                        <div>أكملوا: <strong>{{ $campaign['completed'] }}</strong></div>
                        <div>نسبة الإكمال: <strong>{{ $campaign['completion_rate'] }}%</strong></div>
                        <div>نشطون حاليًا: <strong>{{ $campaign['active_participants'] }}</strong></div>
                    </div>
                    @if (!empty($campaign['funnel']))
                        <div style="font-weight:800; margin-bottom:.4rem; font-size:.85rem;">تقدُّم اللاعبين عبر القصة</div>
                        <div style="display:flex; flex-direction:column; gap:.3rem;">
                            @foreach ($campaign['funnel'] as $step)
                                <div style="display:flex; justify-content:space-between; font-size:.8rem; padding:.25rem 0; border-bottom:1px solid rgba(148,163,184,.1);">
                                    <span>{{ $step['stage'] }} ← {{ $step['gate'] }} ← {{ $step['step'] }}</span>
                                    <span>{{ $step['reached'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
            @endforeach

            @foreach ($c['seasons'] as $season)
                <x-filament::section>
                    <x-slot name="heading">موسم {{ $season['code'] }} {{ $season['is_published'] ? '(منشور)' : '(غير منشور)' }}</x-slot>
                    <div style="margin-bottom:1rem;">أكملوا: {{ $season['completed'] }} من {{ $season['started'] }} ({{ $season['completion_rate'] }}%)</div>
                    @if (!empty($season['qualifications']))
                        <div style="font-weight:800; margin-bottom:.4rem; font-size:.85rem;">التأهيل</div>
                        @foreach ($season['qualifications'] as $q)
                            <div style="font-size:.8rem; padding:.25rem 0;">
                                {{ $q['gate'] }}: {{ $q['qualified_count'] }}{{ $q['limit'] ? ' / '.$q['limit'] : '' }}
                                @if ($q['occupancy_percent'] !== null) ({{ $q['occupancy_percent'] }}%) @endif
                            </div>
                        @endforeach
                    @endif
                </x-filament::section>
            @endforeach
        @endif

        {{-- الجواهر والاستبدال --}}
        @if ($activeTab === 'economy' && ($e = $this->economyAnalytics()))
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                @foreach ([
                    'جواهر بالمحافظ حاليًا' => number_format($e['gems']['total_in_wallets']),
                    'ممنوحة بالفترة' => number_format($e['gems']['issued_in_period']),
                    'مُستبدَلة بالفترة' => number_format($e['gems']['spent_in_period']),
                    'تعديلات يدوية بالفترة' => number_format($e['gems']['manual_adjustments_in_period']),
                    'كاسبون فريدون' => $e['gems']['unique_earners'],
                ] as $label => $value)
                    <x-filament::section><div style="font-size:1.3rem; font-weight:900;">{{ $value }}</div><div style="color:#94a3b8; font-size:.78rem;">{{ $label }}</div></x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <x-slot name="heading">توزيع أنواع المعاملات</x-slot>
                @foreach ($e['gems']['breakdown_by_type'] as $row)
                    <div style="display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid rgba(148,163,184,.1); font-size:.85rem;">
                        <span>{{ $row['type'] }}</span><span>{{ $row['count'] }} عملية</span>
                    </div>
                @endforeach
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">طلبات الاستبدال</x-slot>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:.8rem;">
                    <div>الإجمالي: <strong>{{ $e['redemptions']['total_requests'] }}</strong></div>
                    <div>معلَّقة: <strong>{{ $e['redemptions']['pending'] }}</strong></div>
                    <div>مقبولة: <strong>{{ $e['redemptions']['approved'] }}</strong></div>
                    <div>مرفوضة: <strong>{{ $e['redemptions']['rejected'] }}</strong></div>
                    <div>نسبة القبول: <strong>{{ $e['redemptions']['approval_rate'] }}%</strong></div>
                    @if ($e['redemptions']['avg_processing_hours'] !== null)
                        <div>متوسط وقت المعالجة: <strong>{{ $e['redemptions']['avg_processing_hours'] }} ساعة</strong></div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- الأمان --}}
        @if ($activeTab === 'security' && ($s = $this->securityAnalytics()))
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem;">
                @foreach ([
                    'إشارات مفتوحة' => $s['open_fraud_flags'], 'إشارات مُعالَجة' => $s['resolved_fraud_flags'],
                    'إشارات جديدة بالفترة' => $s['flags_created_in_period'], 'حسابات مجمَّدة' => $s['frozen_users'],
                    'إنهاء جلسات بالفترة' => $s['session_revocations_in_period'],
                ] as $label => $value)
                    <x-filament::section><div style="font-size:1.3rem; font-weight:900;">{{ $value }}</div><div style="color:#94a3b8; font-size:.78rem;">{{ $label }}</div></x-filament::section>
                @endforeach
            </div>

            <x-filament::section>
                <x-slot name="heading">إجراءات إدارية بالفترة</x-slot>
                @forelse ($s['admin_actions_in_period'] as $row)
                    <div style="display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid rgba(148,163,184,.1); font-size:.85rem;">
                        <span>{{ $row['action'] }}</span><span>{{ $row['count'] }}</span>
                    </div>
                @empty <p style="color:#64748b;">لا إجراءات مسجَّلة بهذه الفترة.</p> @endforelse
            </x-filament::section>
        @endif

        {{-- التقارير --}}
        @if ($activeTab === 'reports' && auth()->user()?->can('reports.export'))
            <x-filament::section>
                <x-slot name="heading">تقارير جاهزة للتصدير (CSV)</x-slot>
                <p style="color:#94a3b8; font-size:.8rem; margin-bottom:1rem;">كل تقرير يُصدَّر وفق الفترة المختارة أعلاه.</p>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:.8rem;">
                    @foreach ([
                        ['exportUsers', 'تقرير المستخدمين'],
                        ['exportPuzzleActivity', 'تقرير نشاط الأحجيات'],
                        ['exportCampaignProgress', 'تقرير تقدُّم الحملات'],
                        ['exportGems', 'تقرير الجواهر'],
                        ['exportRedemptions', 'تقرير الاستبدالات'],
                        ['exportSecurity', 'تقرير الأمان'],
                    ] as [$method, $label])
                        <button wire:click="{{ $method }}" type="button" style="padding:.7rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.3); background:rgba(139,92,246,.1); color:inherit; font-weight:700; font-size:.85rem; cursor:pointer; text-align:start;">
                            ⬇ {{ $label }}
                        </button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

    </div>
</x-filament-panels::page>