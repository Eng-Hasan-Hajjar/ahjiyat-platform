{{-- E42 - تحليلات الموسم: تجميعات فقط من مصادر موجودة؛ بلا مبالغ ولا هويات. ما لا يُتتبَّع يُذكر صراحةً بدل أرقام مختلقة. --}}
@php
    $when = fn ($d) => $d ? $d->format('Y-m-d H:i') : '—';
    $row = fn (array $c) => $c['participants'].' مشارك · '.$c['completed'].' أكمل ('.$c['completion_rate'].'٪) · '.$c['active'].' نشط';
@endphp
<div class="space-y-5 text-sm" data-season-analytics>
    <p>الحالة: <strong>{{ $stats['state_label'] }}</strong> · من {{ $when($stats['window_start']) }} إلى {{ $when($stats['window_end']) }}</p>

    @if ($stats['primary'])
        <div>
            <h3 class="font-bold mb-1">الحملة الأساسية: {{ $stats['primary']['title'] }}</h3>
            <p>{{ $row($stats['primary']) }}</p>
        </div>
    @endif

    @if ($stats['extra_campaigns'] !== [])
        <div>
            <h3 class="font-bold mb-1">حملات إضافية</h3>
            @foreach ($stats['extra_campaigns'] as $c)
                <p class="py-1 border-t">{{ $c['title'] }} — {{ $row($c) }}</p>
            @endforeach
        </div>
    @endif

    @if ($stats['phases'] !== [])
        <div>
            <h3 class="font-bold mb-1">المراحل</h3>
            @foreach ($stats['phases'] as $p)
                <p class="py-1 border-t">{{ $p['name'] }} — {{ $p['label'] }}{{ $p['notified'] ? ' · عُولج بدؤها' : '' }}</p>
            @endforeach
        </div>
    @endif

    @if ($stats['competitions'] !== [])
        <div>
            <h3 class="font-bold mb-1">المنافسات</h3>
            @foreach ($stats['competitions'] as $e)
                <p class="py-1 border-t">{{ $e['title'] }} — {{ $e['participants'] }} مشارك</p>
            @endforeach
        </div>
    @endif

    @if ($stats['mission_pools'] !== [])
        <div>
            <h3 class="font-bold mb-1">مجموعات المهام (لاعبون مُسنَد إليهم في الفترتين الحاليتين)</h3>
            @foreach ($stats['mission_pools'] as $m)
                <p class="py-1 border-t">{{ $m['name'] }} — {{ $m['assigned_users'] }} لاعب{{ $m['active'] ? '' : ' · معطّلة' }}</p>
            @endforeach
        </div>
    @endif

    @if ($stats['pass'])
        <div>
            <h3 class="font-bold mb-1">المسار: {{ $stats['pass']['name'] }}</h3>
            <p>{{ $stats['pass']['joined'] }} لاعب بدأ · {{ $stats['pass']['premium'] }} فتح المميز (أعداد فقط)</p>
        </div>
    @endif

    <div>
        <h3 class="font-bold mb-1">الإعلانات</h3>
        <p>منشور {{ $stats['announcements']['live'] }} · مجدول {{ $stats['announcements']['scheduled'] }} · مسودة {{ $stats['announcements']['draft'] }} · منتهٍ {{ $stats['announcements']['expired'] }} · أُشعر به {{ $stats['announcements']['notified'] }}</p>
    </div>

    <div class="text-gray-500">
        <h3 class="font-bold mb-1">غير متاح بعد (لا يوجد تتبّع له)</h3>
        <p>{{ implode(' · ', $stats['not_tracked']) }}</p>
    </div>
</div>
