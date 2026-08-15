@extends('layouts.app')
@section('title', 'إدارة المهام')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 style="font-weight:900;font-family:'Cairo',sans-serif;margin-bottom:4px;">إدارة المهام</h2>
        <p class="text-muted mb-0"><a href="{{ route('projects.show', $project) }}" style="color:var(--primary);">{{ $project->title }}</a></p>
    </div>
    <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-right me-1"></i>العودة للمشروع</a>
</div>

{{-- Add Task Form --}}
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>إضافة مهمة جديدة</div>
    <div class="card-body">
        <form action="{{ route('tasks.store', $project) }}" method="POST">
            @csrf
            <div class="row g-3">
                <div class="col-md-4"><input type="text" name="title" class="form-control" placeholder="عنوان المهمة *" required></div>
                <div class="col-md-3">
                    <select name="assigned_to" class="form-select">
                        <option value="">اختر متطوعاً</option>
                        @foreach($volunteers as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="priority" class="form-select">
                        <option value="high">عالية</option>
                        <option value="medium" selected>متوسطة</option>
                        <option value="low">منخفضة</option>
                    </select>
                </div>
                <div class="col-md-2"><input type="number" name="estimated_hours" class="form-control" placeholder="ساعات" min="1" value="4" required></div>
                <div class="col-md-1"><button class="btn btn-primary w-100"><i class="bi bi-plus"></i></button></div>
                <div class="col-md-6"><input type="date" name="due_date" class="form-control" min="{{ now()->toDateString() }}"></div>
                <div class="col-md-6">
                    <select name="required_skill" class="form-select">
                        <option value="">المهارة المطلوبة</option>
                        @foreach(\App\Models\VolunteerProfile::allSkills() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12"><textarea name="description" class="form-control" rows="2" placeholder="وصف المهمة (اختياري)"></textarea></div>
            </div>
        </form>
    </div>
</div>

{{-- Kanban --}}
<div class="row g-4">
    @foreach(['pending'=>['label'=>'معلّقة','color'=>'#f59e0b','icon'=>'⏳'], 'in_progress'=>['label'=>'جارية','color'=>'#3b82f6','icon'=>'⚡'], 'completed'=>['label'=>'مكتملة','color'=>'#22c55e','icon'=>'✅'], 'cancelled'=>['label'=>'ملغاة','color'=>'#ef4444','icon'=>'❌']] as $status => $meta)
    <div class="col-md-6 col-xl-3">
        <div style="border-top:3px solid {{ $meta['color'] }};border-radius:var(--radius);background:#fff;box-shadow:var(--shadow-sm);overflow:hidden;">
            <div class="p-3 d-flex justify-content-between align-items-center" style="border-bottom:1px solid var(--border);">
                <span style="font-weight:700;">{{ $meta['icon'] }} {{ $meta['label'] }}</span>
                <span class="badge" style="background:{{ $meta['color'] }}22;color:{{ $meta['color'] }};">{{ isset($tasks[$status]) ? $tasks[$status]->count() : 0 }}</span>
            </div>
            <div class="p-2">
                @forelse($tasks[$status] ?? collect() as $task)
                <div class="p-3 mb-2 rounded-3 border" style="background:#fff;transition:.2s;" onmouseenter="this.style.boxShadow='0 2px 8px rgba(0,0,0,.08)'" onmouseleave="this.style.boxShadow=''">
                    <div class="d-flex justify-content-between mb-1">
                        <span style="font-weight:600;font-size:.9rem;">{{ $task->title }}</span>
                        <span class="badge priority-{{ $task->priority }}" style="font-size:.68rem;">{{ $task->priority }}</span>
                    </div>
                    @if($task->assignee)<div style="font-size:.8rem;color:var(--text-light);margin-bottom:6px;"><i class="bi bi-person me-1"></i>{{ $task->assignee->name }}</div>@endif
                    @if($task->due_date)<div style="font-size:.78rem;color:var(--text-light);margin-bottom:6px;"><i class="bi bi-calendar me-1"></i>{{ $task->due_date->format('d/m') }}</div>@endif
                    <div class="d-flex gap-1 flex-wrap">
                        @foreach(['pending'=>'معلّقة','in_progress'=>'جارية','completed'=>'مكتملة'] as $s => $sl)
                            @if($s !== $status)
                            <form action="{{ route('tasks.status', $task) }}" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="status" value="{{ $s }}">
                                <button class="btn btn-sm" style="font-size:.72rem;padding:2px 7px;border:1px solid var(--border);">{{ $sl }}</button>
                            </form>
                            @endif
                        @endforeach
                    </div>
                </div>
                @empty
                <div class="text-center py-3 text-muted" style="font-size:.85rem;">لا توجد مهام</div>
                @endforelse
            </div>
        </div>
    </div>
    @endforeach
</div>
@endsection