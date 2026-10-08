@extends('layouts.app')

@section('title', 'الرئيسية')

@section('content')
    {{-- E22: نفس المسار، عرضان: لوحة لاعب شخصية للمصادَقين، وصفحة استكشاف للضيف. --}}
    @auth
        @include('home._dashboard')
    @else
        @include('home._guest')
    @endauth
@endsection
