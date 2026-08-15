@extends('errors.layout')
@section('code', '401')
@section('icon', '🔑')
@section('iconBg', '#FFF3DC')
@section('title', 'يجب تسجيل الدخول أولاً')
@section('desc', 'هذه الصفحة تتطلب تسجيل الدخول. الرجاء تسجيل الدخول لحسابك للمتابعة.')
@section('actions')
    <a href="{{ route('login') }}" class="btn-e btn-e-primary">🔑 تسجيل الدخول</a>
    <a href="{{ url('/') }}" class="btn-e btn-e-outline">🏠 الصفحة الرئيسية</a>
@endsection