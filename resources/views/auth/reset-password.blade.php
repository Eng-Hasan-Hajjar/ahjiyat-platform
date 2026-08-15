<x-guest-layout>
    <div dir="rtl" class="w-full">

        <div class="bg-white rounded-2xl border border-[#eef2f7]
                    shadow-[0px_0px_1px_rgba(0,0,0,0.05),0px_30px_90px_rgba(0,0,0,0.10)]
                    px-6 py-8 sm:px-10 sm:py-10">

            <div class="flex justify-center mb-6">
                <img src="{{ asset('images/namaa-logo.png') }}" alt="شعار نماء أكاديمي" class="h-16 sm:h-18 w-auto">
            </div>

            <h1 class="text-center font-extrabold text-[20px] sm:text-[24px] text-[#0b1220] mb-6">
                إعادة تعيين كلمة المرور
            </h1>

            <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <x-input-label for="email" :value="__('البريد الإلكتروني')" class="text-right" />
                    <x-text-input id="email"
                                  class="block mt-2 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-200"
                                  type="email"
                                  name="email"
                                  :value="old('email', $request->email)"
                                  required autofocus autocomplete="username" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password" :value="__('كلمة المرور الجديدة')" class="text-right" />
                    <x-text-input id="password"
                                  class="block mt-2 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-200"
                                  type="password"
                                  name="password"
                                  required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور')" class="text-right" />
                    <x-text-input id="password_confirmation"
                                  class="block mt-2 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-200"
                                  type="password"
                                  name="password_confirmation"
                                  required autocomplete="new-password" />
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                </div>

                <button type="submit"
                        class="w-full sm:w-auto min-w-[220px] mx-auto flex items-center justify-center
                               px-10 py-3 rounded-xl text-white font-bold transition-all"
                        style="
                            background: linear-gradient(90deg, #0ea5e9 0%, #10b981 100%);
                            box-shadow: 0 14px 35px rgba(16,185,129,.22), 0 12px 25px rgba(14,165,233,.18);
                        "
                        onmouseover="this.style.filter='brightness(0.95)'"
                        onmouseout="this.style.filter='none'">
                    حفظ كلمة المرور
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
