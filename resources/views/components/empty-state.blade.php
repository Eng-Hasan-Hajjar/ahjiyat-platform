@props([
    'icon'    => '📭',
    'title'   => 'لا توجد بيانات',
    'message' => '',
    'action'  => null,
    'actionLabel' => 'إضافة جديد',
])

<div class="card text-center py-5 px-3">
    <div style="font-size:3.5rem;margin-bottom:16px;line-height:1;">{{ $icon }}</div>
    <h5 style="font-weight:700;color:var(--text-dark);margin-bottom:8px;">{{ $title }}</h5>
    @if($message)
        <p style="color:var(--text-mid);font-size:.92rem;max-width:400px;margin:0 auto 20px;">{{ $message }}</p>
    @endif
    @if($action)
        <a href="{{ $action }}" class="btn btn-primary btn-sm px-4">{{ $actionLabel }}</a>
    @endif
</div>