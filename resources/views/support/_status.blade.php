{{-- E32: شارة حالة الطلب. لا نعتمد اللون وحده (النص دائمًا). «تم التحقق» فقط بعد تحقق الفريق؛ قبلها لا ندّعي نجاح الدفع أبدًا. --}}
@php
    $tones = ['awaiting_payment' => 'warning', 'submitted' => 'primary', 'verified' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', 'expired' => 'neutral', 'refunded' => 'neutral'];
@endphp
<x-status-badge :tone="$tones[$payment->status] ?? 'neutral'" :small="$small ?? false">{{ $payment->statusLabel() }}</x-status-badge>
