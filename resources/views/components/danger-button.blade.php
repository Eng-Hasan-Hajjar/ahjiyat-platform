{{-- E23: زر خطِر من رموز الثيم (.btn-danger): لون خطر + نص واضح، لا اللون وحده. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-danger']) }}>
    {{ $slot }}
</button>
