<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        تم إرسال كود تحقق إلى بريدك الإلكتروني. أدخل الكود لإكمال تسجيل الدخول.
    </div>

    @if (session('status'))
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4">
            <ul class="text-sm text-red-600 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('otp.verify') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="code" value="رمز التحقق (6 أرقام)" />
            <x-text-input id="code" class="block mt-1 w-full"
                          type="text"
                          name="code"
                          inputmode="numeric"
                          autocomplete="one-time-code"
                          maxlength="6"
                          required
                          autofocus />
        </div>

        <div class="flex items-center gap-2">
            <x-primary-button type="submit">
                تحقق
            </x-primary-button>
        </div>
    </form>

    <div class="flex items-center gap-2 mt-4">
        <form method="POST" action="{{ route('otp.resend') }}">
            @csrf
            <x-secondary-button type="submit">
                إعادة إرسال
            </x-secondary-button>
        </form>

        <form method="POST" action="{{ route('otp.logout') }}">
            @csrf
            <x-secondary-button type="submit">
                تسجيل خروج
            </x-secondary-button>
        </form>
    </div>
</x-guest-layout>
