{{-- E42 - تقرير جاهزية الموسم (قراءة فقط). الحواجز تمنع النشر؛ التحذيرات تنبّه ولا تمنع. النصوص مهرَّبة. --}}
<div class="space-y-4 text-sm" data-season-readiness>
    <div class="rounded-lg border p-3 {{ $report['ready'] ? 'border-success-500' : 'border-danger-500' }}">
        <strong>{{ $report['ready'] ? 'الموسم جاهز للنشر.' : 'الموسم غير جاهز للنشر.' }}</strong>
    </div>

    <div>
        <h3 class="font-bold mb-1">حواجز النشر ({{ count($report['blockers']) }})</h3>
        @forelse ($report['blockers'] as $item)
            <p class="py-1 border-t" data-blocker>• {{ $item }}</p>
        @empty
            <p class="text-gray-500">لا حواجز.</p>
        @endforelse
    </div>

    <div>
        <h3 class="font-bold mb-1">تحذيرات ({{ count($report['warnings']) }})</h3>
        @forelse ($report['warnings'] as $item)
            <p class="py-1 border-t" data-warning>• {{ $item }}</p>
        @empty
            <p class="text-gray-500">لا تحذيرات.</p>
        @endforelse
    </div>
</div>
