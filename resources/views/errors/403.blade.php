@extends('errors.layout')
@section('code', '403')
@section('icon', '🔒')
@section('iconBg', '#FFF3DC')
@section('title', 'غير مصرح لك بالوصول')
@section('desc', 'حسابك لا يملك الصلاحية اللازمة لعرض هذه الصفحة. إذا كنت تعتقد أن هذا خطأ، تواصل مع إدارة المنصة.')
@section('actions')
    <a href="{{ url('/') }}" class="btn-e btn-e-primary">🏠 الصفحة الرئيسية</a>
    <a href="{{ url()->previous() }}" class="btn-e btn-e-outline">↩️ ارجع للخلف</a>
@endsection