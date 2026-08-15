@extends('errors.layout')
@section('code', '500')
@section('icon', '⚠️')
@section('iconBg', '#FDECEC')
@section('title', 'حدث خطأ غير متوقع')
@section('desc', 'واجه الخادم مشكلة أثناء معالجة طلبك. تم إشعار الفريق التقني تلقائياً، جرّب مرة أخرى بعد قليل.')
@section('actions')
    <a href="{{ url('/') }}" class="btn-e btn-e-primary">🏠 الصفحة الرئيسية</a>
@endsection