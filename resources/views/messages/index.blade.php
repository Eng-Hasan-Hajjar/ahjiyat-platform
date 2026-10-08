@extends('layouts.app')

@section('title', 'الرسائل')

@section('content')
    {{-- E22: سطح المكتب: قائمة المحادثات + لوحة «اختر محادثة»؛ الجوال: القائمة وحدها بعرض كامل. المحتوى والمنطق كما كانا (نص عادي، عدّادات). --}}
    <div class="lg:grid lg:grid-cols-[19rem_minmax(0,1fr)] lg:gap-5 lg:items-start">
        <div class="space-y-4 min-w-0">
            <x-page-header title="الرسائل" icon="chat" subtitle="الأصدقاء، فريقك، والدردشة العامة. نص عادي فقط." class="!mb-0" />
            @include('messages._sidebar')
        </div>

        <section class="hidden lg:grid place-items-center glass rounded-3xl min-h-[26rem] px-8 text-center" aria-label="لا محادثة مفتوحة">
            <div>
                <span class="mx-auto mb-4 grid place-items-center w-16 h-16 rounded-3xl bg-amethyst/15 text-amethyst"><x-ui-icon name="chat" class="w-8 h-8" /></span>
                <h2 class="font-display font-black text-lg text-white">اختر محادثة</h2>
                <p class="text-sm text-slate-400 mt-1">اختر صديقًا أو فريقك أو الدردشة العامة من القائمة لتبدأ.</p>
            </div>
        </section>
    </div>
@endsection
