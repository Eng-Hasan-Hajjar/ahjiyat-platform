@extends('layouts.guest')

@section('title', 'توثيق البريد')

@section('content')
    <h1 class="font-display font-bold text-lg text-ink mb-3 text-center">وثّق بريدك الإلكتروني</h1>
    <p class="text-sm text-ink/60 text-center mb-5">
        أرسلنا رابط توثيق إلى بريدك الإلكتروني. افتحه لتفعيل حسابك بالكامل وتقدر تبلش تجمع جواهر.
    </p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="w-full bg-amethyst text-white font-bold py-2.5 rounded-lg hover:bg-amethyst-700 transition">
            إعادة إرسال رابط التوثيق
        </button>
    </form>
@endsection
