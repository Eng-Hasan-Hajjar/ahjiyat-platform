@props(['tier'])
{{-- E30: شارة مستوى الداعم (عرض فقط؛ المستوى مشتقّ من صافي الدعم المؤكَّد). --}}
@if ($tier)
    @php($tone = ['gold' => 'warning', 'platinum' => 'primary', 'diamond' => 'primary', 'silver' => 'neutral'][$tier->badge_key] ?? 'neutral')
    <x-status-badge :tone="$tone" icon="sparkles" small>{{ $tier->name }}</x-status-badge>
@endif
