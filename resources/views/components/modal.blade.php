@props(['id', 'title', 'size' => ''])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered {{ $size ? 'modal-'.$size : '' }}">
        <div class="modal-content border-0" style="border-radius:var(--radius);box-shadow:var(--shadow-lg);">
            <div class="modal-header border-bottom" style="padding:16px 20px;">
                <h5 class="modal-title fw-bold" id="{{ $id }}Label">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body" style="padding:20px;">
                {{ $slot }}
            </div>
            @isset($footer)
            <div class="modal-footer border-top" style="padding:14px 20px;">
                {{ $footer }}
            </div>
            @endisset
        </div>
    </div>
</div>