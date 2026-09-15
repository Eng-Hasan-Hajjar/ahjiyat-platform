@extends('layouts.app')

@section('title', 'خطوة مقفلة')

@section('content')
    <div class="max-w-2xl mx-auto text-center">
        <div class="puzzle-card !p-10 anim-fade-up">
            <div class="text-5xl mb-4">🔒</div>
            <h1 class="font-display font-black text-2xl text-white mb-3">هذه الخطوة مقفلة حالياً</h1>
            <p class="text-slate-400 mb-6">أكمل الخطوات السابقة أولاً لفتح هذه المرحلة.</p>
            <a href="{{ route('campaigns.show', $campaign) }}" class="btn-gem !py-3 !px-6">العودة للحملة</a>
        </div>
    </div>
@endsection