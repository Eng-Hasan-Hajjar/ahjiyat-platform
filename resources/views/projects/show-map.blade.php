{{-- 
    Include this partial inside projects/show.blade.php when coordinates are available
    Usage: @include('projects.show-map', ['project' => $project])
--}}

@if($project->latitude && $project->longitude)
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-geo-alt me-2 text-primary"></i>موقع المشروع على الخريطة
    </div>
    <div class="card-body p-0" style="border-radius:0 0 var(--radius) var(--radius);overflow:hidden;">
        <div id="projectMap" style="height:300px;width:100%;"></div>
    </div>
    <div class="card-footer" style="font-size:.85rem;color:var(--text-mid);">
        <i class="bi bi-pin-map me-1"></i>{{ $project->address }}، {{ $project->city }}
    </div>
</div>

@push('scripts')
<script>
// Google Maps integration
// Replace YOUR_API_KEY with the actual key from .env GOOGLE_MAPS_API_KEY
function initProjectMap() {
    const lat = {{ $project->latitude }};
    const lng = {{ $project->longitude }};
    const mapEl = document.getElementById('projectMap');
    if (!mapEl || typeof google === 'undefined') return;

    const map = new google.maps.Map(mapEl, {
        center:    { lat, lng },
        zoom:      15,
        mapTypeId: 'roadmap',
        styles: [
            { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        ],
        mapTypeControl:    false,
        streetViewControl: false,
    });

    const marker = new google.maps.Marker({
        position: { lat, lng },
        map,
        title:     '{{ $project->title }}',
        animation: google.maps.Animation.DROP,
        icon: {
            url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(`
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
                    <circle cx="20" cy="20" r="18" fill="#2E7D4F" stroke="white" stroke-width="3"/>
                    <text x="20" y="26" text-anchor="middle" fill="white" font-size="18">🏗️</text>
                </svg>
            `),
            scaledSize: new google.maps.Size(40, 40),
            anchor:     new google.maps.Point(20, 20),
        }
    });

    const infoWindow = new google.maps.InfoWindow({
        content: `
            <div style="font-family:Tajawal,sans-serif;direction:rtl;max-width:220px;padding:6px;">
                <strong style="color:#2E7D4F;">{{ $project->title }}</strong><br>
                <small style="color:#666;">{{ $project->address }}, {{ $project->city }}</small><br>
                <span style="color:#ef4444;font-size:.8rem;">نسبة الضرر: {{ $project->damage_percentage }}%</span>
            </div>
        `,
    });

    marker.addListener('click', () => infoWindow.open(map, marker));
}
</script>
@if(config('app.google_maps_key'))
<script async defer
    src="https://maps.googleapis.com/maps/api/js?key={{ config('app.google_maps_key') }}&callback=initProjectMap">
</script>
@else
<script>
// Fallback: OpenStreetMap via Leaflet (no API key needed)
document.addEventListener('DOMContentLoaded', function() {
    const mapEl = document.getElementById('projectMap');
    if (!mapEl) return;

    const lat = {{ $project->latitude }};
    const lng = {{ $project->longitude }};

    // Load Leaflet dynamically
    const cssLink = document.createElement('link');
    cssLink.rel  = 'stylesheet';
    cssLink.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(cssLink);

    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = function() {
        const map = L.map('projectMap').setView([lat, lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        const icon = L.divIcon({
            html: '<div style="background:#2E7D4F;color:#fff;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.3);">🏗️</div>',
            className: '',
            iconSize: [36, 36],
            iconAnchor: [18, 18],
        });

        L.marker([lat, lng], { icon })
            .addTo(map)
            .bindPopup(`
                <div style="font-family:Tajawal,sans-serif;direction:rtl;text-align:right;">
                    <strong style="color:#2E7D4F;">{{ $project->title }}</strong><br>
                    <small>{{ $project->address }}, {{ $project->city }}</small>
                </div>
            `)
            .openPopup();
    };
    document.head.appendChild(script);
});
</script>
@endif
@endpush
@endif