@extends('errors.layout')
@section('code', '404')
@section('icon', '🧭')
@section('iconBg', 'linear-gradient(135deg, rgba(139,92,246,.35), rgba(217,70,239,.25))')
@section('title', 'الصفحة غير موجودة')
@section('desc', 'يبدو أن الرابط الذي تحاول الوصول إليه غير موجود، أو تم نقله، أو ربما كُتب بشكل غير صحيح.')
@section('actions')
    <a href="{{ url('/') }}" class="btn-e btn-e-primary">🏠 الصفحة الرئيسية</a>
    <a href="{{ url()->previous() }}" class="btn-e btn-e-outline">↩️ ارجع للخلف</a>
@endsection