@extends('layouts.app')

@section('title', 'مسار التحدي')
@section('meta_description', 'مسار التحدي: تقدّم دوري بمكافآت تجميلية. لا مسار فعّال حاليًا.')

@section('content')
    <x-page-header title="مسار التحدي" subtitle="تقدّم دوري بمكافآت تجميلية تكسبها بإكمال المهام." icon="sparkles" class="anim-fade-up" />

    <x-empty-state icon="sparkles" title="لا يوجد مسار فعّال الآن" message="سنعلن عن المسار القادم هنا. تقدّمك في المسارات السابقة محفوظ في الأرشيف." :action-label="$hasArchive ? 'أرشيف المسارات' : null" :action="$hasArchive ? route('pass.archive') : null" />
@endsection
