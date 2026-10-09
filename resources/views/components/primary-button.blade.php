{{-- E23: زر أساسي بنفس هوية .btn-gem (كان رمادي/نيلي قديمًا بلا علاقة بالثيم). --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-gem !py-2 !px-5 text-sm']) }}>
    {{ $slot }}
</button>
