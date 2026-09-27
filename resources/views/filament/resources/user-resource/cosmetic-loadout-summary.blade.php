<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.8rem;">
    @php
        $slotLabels = [
            \App\Models\StoreItem::SLOT_AVATAR => 'صورة رمزية',
            \App\Models\StoreItem::SLOT_FRAME => 'إطار',
            \App\Models\StoreItem::SLOT_BADGE => 'شارة',
            \App\Models\StoreItem::SLOT_TITLE => 'لقب',
            \App\Models\StoreItem::SLOT_BACKGROUND => 'خلفية',
        ];
    @endphp
    @foreach ($slotLabels as $slot => $label)
        <div style="padding:.8rem 1rem; border-radius:.7rem; border:1px solid rgba(148,163,184,.25); background:rgba(139,92,246,.06);">
            <div style="font-weight:800; font-size:.75rem; color:#94a3b8; margin-bottom:.3rem;">{{ $label }}</div>
            @if ($loadout[$slot])
                <div style="font-weight:800; font-size:.9rem;">{{ $loadout[$slot]->name }}</div>
                @if ($slot === \App\Models\StoreItem::SLOT_TITLE && $loadout[$slot]->cosmetic_text)
                    <div style="font-size:.75rem; color:#64748b;">"{{ $loadout[$slot]->cosmetic_text }}"</div>
                @endif
            @else
                <div style="color:#64748b; font-size:.85rem;">غير مُجهَّز</div>
            @endif
        </div>
    @endforeach
</div>