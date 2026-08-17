@extends('layouts.guest')

@section('title', 'توثيق البريد')

@section('content')
    <h1 class="font-display font-black text-xl text-white mb-3 text-center">وثّق بريدك الإلكتروني</h1>
    <p class="text-sm text-slate-400 text-center mb-6 leading-relaxed">
        أرسلنا رابط توثيق إلى بريدك الإلكتروني. افتحه لتفعيل حسابك بالكامل وتقدر تبلش تجمع جواهر.
    </p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn-gem w-full justify-center">
            إعادة إرسال رابط التوثيق
        </button>
    </form>
@endsection
