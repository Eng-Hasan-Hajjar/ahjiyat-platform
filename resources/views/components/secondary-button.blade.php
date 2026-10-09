{{-- E23: زر ثانوي من رموز الثيم (.btn-secondary). --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn-secondary']) }}>
    {{ $slot }}
</button>
