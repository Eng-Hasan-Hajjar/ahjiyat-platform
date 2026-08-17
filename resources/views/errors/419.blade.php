@extends('errors.layout')
@section('code', '419')
@section('icon', '⏰')
@section('iconBg', 'linear-gradient(135deg, rgba(252,211,77,.35), rgba(245,158,11,.25))')
@section('title', 'انتهت صلاحية الجلسة')
@section('desc', 'مضى وقت طويل على فتح هذه الصفحة قبل الإرسال. الرجاء تحديث الصفحة وإعادة المحاولة.')
@section('actions')
    <a href="javascript:location.reload()" class="btn-e btn-e-primary">🔄 حدّث الصفحة</a>
    <a href="{{ url('/') }}" class="btn-e btn-e-outline">🏠 الصفحة الرئيسية</a>
@endsection