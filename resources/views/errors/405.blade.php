@extends('errors.layout')
@section('code', '405')
@section('icon', '🚫')
@section('iconBg', 'linear-gradient(135deg, rgba(251,113,133,.35), rgba(225,29,72,.25))')
@section('title', 'طريقة الطلب غير مسموحة')
@section('desc', 'الرابط الذي وصلت إليه لا يقبل هذا النوع من الطلبات. جرّب الرجوع للصفحة الرئيسية والمتابعة من هناك.')
@section('actions')
    <a href="{{ url('/') }}" class="btn-e btn-e-primary">🏠 الصفحة الرئيسية</a>
    <a href="{{ url()->previous() }}" class="btn-e btn-e-outline">↩️ ارجع للخلف</a>
@endsection