@props(['id', 'action', 'title' => 'تأكيد الحذف', 'message' => 'هل أنت متأكد من رغبتك في الحذف؟ لا يمكن التراجع عن هذا الإجراء.'])

<div class="modal fade" id="{{ $id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0" style="border-radius:var(--radius);box-shadow:var(--shadow-lg);">
            <div class="modal-body text-center p-4">
                <div style="font-size:2.5rem;margin-bottom:12px;">⚠️</div>
                <h5 style="font-weight:700;margin-bottom:8px;">{{ $title }}</h5>
                <p style="font-size:.9rem;color:var(--text-mid);margin-bottom:20px;">{{ $message }}</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-4" data-bs-dismiss="modal">إلغاء</button>
                    <form action="{{ $action }}" method="POST" class="d-inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm px-4">حذف</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>