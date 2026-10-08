@extends('layouts.app')

@section('title', 'إعدادات الحساب')
@section('content_width', 'max-w-2xl')

@section('content')
    <div class="space-y-5">
        {{-- E22: تنقل الإعدادات (الحساب | الهوية | الخصوصية) - تمييز واضح بين تعديل الحساب وتخصيص الهوية. --}}
        @include('profile.partials.settings-tabs', ['active' => 'account'])


        <div class="puzzle-card !p-6 md:!p-8 anim-fade-up">
            <div class="flex items-center gap-4 mb-6">
                <span class="gem-facet w-14 h-14 grid place-items-center text-lg font-black text-white bg-gradient-to-br from-amethyst to-gold shrink-0">
                    {{ mb_substr($user->name, 0, 1) }}
                </span>
                <div class="min-w-0">
                    <h1 class="font-display font-black text-xl text-white truncate">إعدادات الحساب</h1>
                    <span class="text-xs text-slate-400 font-semibold block truncate">{{ $user->name }}</span>
                    <span class="text-xs text-slate-500 font-semibold">عضو منذ {{ $user->created_at->format('Y-m-d') }}</span>
                </div>
            </div>

            @if (session('success'))
                <div class="rounded-xl bg-emerald/10 border border-emerald/30 text-emerald text-sm font-bold px-4 py-3 mb-4">
                    {{ session('success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="name" class="block text-sm font-bold text-slate-300 mb-2">الاسم</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required
                           class="input-gem">
                    @error('name')
                        <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <label for="email" class="block text-sm font-bold text-slate-300 mb-2">البريد الإلكتروني</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required
                           class="input-gem">
                    @error('email')
                        <span class="text-xs text-rose font-semibold mt-1.5 block">{{ $message }}</span>
                    @enderror
                    @unless ($user->hasVerifiedEmail())
                        <span class="text-xs text-gold font-semibold mt-1.5 block">
                            بريدك غير موثّق بعد. تغييره سيتطلب إعادة التوثيق.
                        </span>
                    @endunless
                </div>

                <button type="submit" class="btn-gem w-full justify-center !mt-6">
                    حفظ التعديلات
                </button>
            </form>
        </div>

        <div class="puzzle-card !p-6 md:!p-8 anim-fade-up d-1">
            <h2 class="font-display font-black text-lg text-white mb-1">تخصيص الهوية</h2>
            <p class="text-xs text-slate-500 mb-4">الصورة الرمزية والإطار والشارة واللقب والغلاف: كل ما يظهر به ملفك للآخرين.</p>
            <a href="{{ route('profile.customize') }}" class="btn-gem w-full justify-center !py-2.5 text-sm inline-flex">
                تخصيص الهوية ←
            </a>
        </div>

        <div class="glass rounded-3xl p-6 md:p-8 anim-fade-up d-1">
            <h2 class="font-display font-black text-white text-base mb-2">الإشعارات</h2>
            <p class="text-xs text-slate-400 mb-4">اختر ما يصلك من تذكيرات وإشعارات داخل المنصة.</p>
            <a href="{{ route('notifications.preferences') }}" class="chip inline-flex">تفضيلات الإشعارات</a>
        </div>

        <div id="privacy" class="puzzle-card !p-6 md:!p-8 anim-fade-up d-2 scroll-mt-24">
            <h2 class="font-display font-black text-lg text-white mb-1">خصوصية الملف الشخصي</h2>
            <p class="text-xs text-slate-500 mb-4">تحدِّد من يستطيع رؤية ملفك العام (الهوية والإحصاءات الآمنة فقط - لا بريدك أو رصيدك أبدًا).</p>

            <form method="POST" action="{{ route('profile.visibility.update') }}" class="space-y-3">
                @csrf
                @method('PATCH')

                @foreach ([
                    \App\Models\User::VISIBILITY_PRIVATE => ['خاص', 'لا أحد يراه سوى نفسك'],
                    \App\Models\User::VISIBILITY_MEMBERS => ['للأعضاء فقط', 'أي مستخدم مسجَّل يستطيع رؤيته'],
                    \App\Models\User::VISIBILITY_PUBLIC => ['عام', 'أي زائر يستطيع رؤيته'],
                ] as $value => [$label, $description])
                    <label class="flex items-center gap-3 rounded-xl bg-white/5 border border-white/10 px-4 py-3 cursor-pointer {{ $user->profile_visibility === $value ? '!border-amethyst' : '' }}">
                        <input type="radio" name="profile_visibility" value="{{ $value }}" {{ $user->profile_visibility === $value ? 'checked' : '' }} class="shrink-0">
                        <span class="min-w-0">
                            <span class="block font-bold text-white text-sm">{{ $label }}</span>
                            <span class="block text-xs text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach

                <button type="submit" class="btn-gem w-full justify-center !py-2.5 text-sm !mt-4">
                    حفظ الخصوصية
                </button>
            </form>

            <a href="{{ route('players.show', $user) }}" class="block text-center text-xs text-amethyst underline mt-4">
                معاينة ملفي العام
            </a>
        </div>

    </div>
@endsection