@extends('layouts.app')

@section('title', 'دفع تجريبي (محاكاة)')
@section('meta_description', 'صفحة محاكاة للدفع المستضاف للتطوير والاختبار فقط.')

@section('content')
    <x-page-header title="دفع تجريبي (محاكاة)" :subtitle="$purchase->product_name" icon="sparkles" class="anim-fade-up" />

    <section class="rounded-2xl border border-rose/40 bg-rose/10 p-5 mb-6 anim-fade-up d-1" data-commerce-sandbox>
        <p class="font-display font-black text-lg text-white mb-1">هذه محاكاة وليست عملية دفع حقيقية</p>
        <p class="text-sm text-slate-200">تظهر هذه الصفحة في بيئات التطوير والاختبار فقط لتجربة تدفّق الدفع المستضاف. لن يُخصم أي مبلغ.</p>
        <p class="mt-3 font-display font-black text-2xl text-white" dir="ltr">{{ $amount }}</p>
        <p class="text-xs text-slate-300 mt-1">مرجع: <span class="font-mono" dir="ltr">{{ $purchase->reference_code }}</span></p>
        <div class="mt-4 flex flex-wrap gap-3">
            @foreach (['paid' => 'محاكاة: دفع ناجح', 'failed' => 'محاكاة: فشل', 'cancelled' => 'محاكاة: إلغاء'] as $outcome => $text)
                <form method="POST" action="{{ route('commerce.sandbox.complete', ['session' => $session, 'outcome' => $outcome]) }}">
                    @csrf
                    <button type="submit" class="btn-gem !py-2.5 !px-6 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst" data-sandbox-{{ $outcome }}>{{ $text }}</button>
                </form>
            @endforeach
        </div>
    </section>
@endsection
