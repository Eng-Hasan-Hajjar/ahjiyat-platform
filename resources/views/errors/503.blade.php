@extends('errors.layout')
@section('code', '503')
@section('icon', '🛠️')
@section('iconBg', '#E3F2FD')
@section('title', 'المنصة تحت الصيانة حالياً')
@section('desc', 'نعمل على تحسين المنصة وسنعود خلال وقت قصير. نعتذر عن الإزعاج ونشكر صبركم.')
@section('actions')
    <a href="javascript:location.reload()" class="btn-e btn-e-primary">🔄 حاول مرة أخرى</a>
@endsection