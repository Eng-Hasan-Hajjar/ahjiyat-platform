@foreach (['success','error'] as $k)
  @if (session($k))
    <div class="alert alert-{{ $k==='success' ? 'success' : 'danger' }} alert-dismissible fade show" role="alert">
      {{ session($k) }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif
@endforeach
