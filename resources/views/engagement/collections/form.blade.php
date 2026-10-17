@extends('layouts.app')

@php $editing = $collection !== null; @endphp
@section('title', $editing ? 'تعديل المجموعة' : 'مجموعة جديدة')
@section('content_width', 'max-w-xl')

@section('content')
    <div class="space-y-6">
        <x-page-header :title="$editing ? 'تعديل المجموعة' : 'مجموعة جديدة'" icon="inventory" :back="$editing ? route('collections.show', $collection->public_id) : route('collections.index')" class="anim-fade-up !mb-0" />

        <form method="POST" action="{{ $editing ? route('collections.update', $collection->public_id) : route('collections.store') }}" class="glass rounded-2xl p-5 md:p-6 space-y-4 anim-fade-up">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div>
                <label for="title" class="block text-xs font-bold text-slate-300 mb-1">العنوان</label>
                <input id="title" type="text" name="title" required minlength="{{ (int) config('engagement.collections.title_min') }}" maxlength="{{ (int) config('engagement.collections.title_max') }}" value="{{ old('title', $collection?->title) }}" class="input-gem" aria-describedby="title-error">
                @error('title') <p id="title-error" class="mt-1 text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="description" class="block text-xs font-bold text-slate-300 mb-1">وصف (اختياري)</label>
                <textarea id="description" name="description" rows="3" maxlength="{{ (int) config('engagement.collections.description_max') }}" class="input-gem" aria-describedby="description-error">{{ old('description', $collection?->description) }}</textarea>
                @error('description') <p id="description-error" class="mt-1 text-xs font-bold text-rose" role="alert">{{ $message }}</p> @enderror
            </div>
            <p class="text-xs text-slate-500">المجموعة خاصة بك دائمًا ولا يراها أحد غيرك.</p>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm">{{ $editing ? 'حفظ التعديلات' : 'إنشاء المجموعة' }}</button>
                <a href="{{ $editing ? route('collections.show', $collection->public_id) : route('collections.index') }}" class="text-sm font-bold text-slate-400 hover:text-white underline rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">إلغاء</a>
            </div>
        </form>
    </div>
@endsection
