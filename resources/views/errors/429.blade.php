@extends('errors.layout')
@section('code', '429')
@section('icon', '🚦')
@section('iconBg', 'linear-gradient(135deg, rgba(252,211,77,.35), rgba(245,158,11,.25))')
@section('title', 'طلبات كثيرة جداً')
@section('desc', 'قمت بعدد كبير من المحاولات خلال وقت قصير. الرجاء الانتظار قليلاً قبل إعادة المحاولة.')
@section('actions')
    <a href="{{ url('/') }}" class="btn-e btn-e-primary">🏠 الصفحة الرئيسية</a>
@endsection