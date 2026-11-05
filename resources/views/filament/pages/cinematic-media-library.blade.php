<x-filament-panels::page>
    @php
        $d = $this->data();
        $btn = 'rounded-lg bg-primary-600 text-white px-4 py-2 text-sm font-bold';
        $accept = ['video' => 'video/mp4', 'poster' => 'image/jpeg,image/png,image/webp', 'subtitle' => '.vtt,text/vtt', 'audio' => 'audio/mpeg,audio/mp4'][$d['role']];
    @endphp

    <nav class="flex flex-wrap gap-2" aria-label="نوع الوسيط">
        @foreach ($d['roles'] as $key => $label)
            <a href="{{ \App\Filament\Pages\CinematicMediaLibraryPage::getUrl(['role' => $key]) }}" class="rounded-full border px-4 py-1.5 text-sm font-bold {{ $d['role'] === $key ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-white/10' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <p class="text-xs text-gray-500">
        تُفحص الملفات على الخادم (النوع الحقيقي لا الامتداد، الحجم، وخصائص الفيديو). الحدود الحالية: {{ $d['limits']['max_bytes'] ?? 0 ? number_format(($d['limits']['max_bytes']) / 1048576, 0).' ميغابايت' : '—' }}@if (! empty($d['limits']['max_duration_seconds'])) · المدة حتى {{ $d['limits']['max_duration_seconds'] }} ثانية @endif@if (! empty($d['limits']['max_width'])) · الأبعاد حتى {{ $d['limits']['max_width'] }}×{{ $d['limits']['max_height'] }} @endif.
        حدود PHP (upload_max_filesize / post_max_size) يضبطها مسؤول الاستضافة.
    </p>

    @if ($d['canUpload'])
        <section class="rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3" data-media-upload
            x-data="{ busy: false, msg: '', ok: false, async send(ev) {
                const input = ev.target.querySelector('input[type=file]');
                if (!input.files.length) { this.msg = 'اختر ملفًا أولًا.'; this.ok = false; return; }
                this.busy = true; this.msg = '';
                const body = new FormData(); body.append('role', '{{ $d['role'] }}'); body.append('file', input.files[0]);
                try {
                    const res = await fetch('{{ $d['uploadUrl'] }}', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: body });
                    const data = await res.json().catch(() => ({}));
                    this.ok = res.ok;
                    this.msg = res.ok ? (data.created ? 'تم الرفع والفحص بنجاح.' : 'الملف موجود مسبقًا (لم يُكرَّر).') + ((data.warnings || []).length ? ' تنبيهات: ' + data.warnings.join(' — ') : '') : (data.message || 'فشل الرفع.');
                    if (res.ok) { input.value = ''; setTimeout(() => window.location.reload(), 1200); }
                } catch (e) { this.ok = false; this.msg = 'تعذّر الاتصال بالخادم.'; }
                this.busy = false;
            } }">
            <h3 class="font-bold">رفع {{ $d['roles'][$d['role']] }}</h3>
            <form x-on:submit.prevent="send($event)" class="flex flex-wrap items-center gap-3">
                <input type="file" accept="{{ $accept }}" class="text-sm">
                <button type="submit" class="{{ $btn }}" x-bind:disabled="busy"><span x-text="busy ? 'جارٍ الرفع والفحص…' : 'رفع'"></span></button>
            </form>
            <p class="text-sm font-bold" x-show="msg" x-bind:class="ok ? 'text-emerald-600' : 'text-red-600'" x-text="msg" role="status"></p>
        </section>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" data-media-table>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-white/5 text-xs"><tr><th class="p-3 text-start">الملف</th><th class="p-3 text-start">الخصائص</th><th class="p-3 text-start">الاستعمال</th><th class="p-3 text-start">الحالة</th><th class="p-3"></th></tr></thead>
            <tbody>
                @forelse ($d['assets'] as $asset)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="p-3"><div class="font-bold break-all">{{ $asset->original_name }}</div><div class="text-xs text-gray-500" dir="ltr">{{ $asset->public_id }}</div></td>
                        <td class="p-3 text-xs" dir="ltr">{{ $asset->mime }} · {{ number_format($asset->size_bytes / 1024, 0) }} KB @if ($asset->duration_ms) · {{ round($asset->duration_ms / 1000, 1) }}s @endif @if ($asset->width) · {{ $asset->width }}×{{ $asset->height }} @endif @if ($asset->video_codec) · {{ $asset->video_codec }}/{{ $asset->audio_codec ?: '—' }} @endif</td>
                        <td class="p-3">{{ (int) ($d['usage'][$asset->id] ?? 0) }} مشهد</td>
                        <td class="p-3">{{ $asset->status === 'ready' ? 'جاهز' : 'مسحوب' }}</td>
                        <td class="p-3">
                            @if ($d['canUpload'])
                                @if ($asset->status === 'ready')
                                    <button type="button" wire:click="retire('{{ $asset->public_id }}')" wire:confirm="سحب الملف؟ تظهر المشاهد المرتبطة به «غير متاحة» ولا يُحذف الملف." class="text-xs underline text-red-600">سحب</button>
                                @else
                                    <button type="button" wire:click="restore('{{ $asset->public_id }}')" class="text-xs underline">استعادة</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-center text-gray-500">لا توجد ملفات من هذا النوع.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
