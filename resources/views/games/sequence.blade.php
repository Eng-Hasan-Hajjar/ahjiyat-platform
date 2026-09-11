{{-- لعبة ترتيب التسلسل - العناصر تُخلط هون سيرفريًا فقط للعرض، والترتيب
     الصحيح الفعلي محفوظ بـ solution_data ولا يصل أبداً للمتصفح --}}
@php
    $shuffledItems = collect($puzzle->game_config['items'] ?? [])
        ->map(fn ($label, $originalIndex) => ['idx' => $originalIndex, 'label' => $label])
        ->shuffle()
        ->values()
        ->all();
@endphp

<div x-data="sequenceGame({{ Illuminate\Support\Js::from($shuffledItems) }})" class="mb-5">
    <p class="text-sm text-slate-400 mb-4">
        رتّب العناصر بالضغط على الأسهم حتى تصل للترتيب الصحيح باعتقادك، ثم أرسل إجابتك 👇
    </p>

    <ul class="space-y-2">
        <template x-for="(item, position) in order" :key="item.idx">
            <li class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3">
                <span class="w-7 h-7 shrink-0 grid place-items-center rounded-full bg-amethyst/20 text-amethyst font-black text-sm"
                      x-text="position + 1"></span>
                <span class="flex-1 text-slate-200 font-semibold" x-text="item.label"></span>
                <button type="button" @click="moveUp(position)" :disabled="position === 0"
                        class="w-9 h-9 shrink-0 grid place-items-center rounded-lg border border-white/10 hover:border-amethyst disabled:opacity-30 disabled:cursor-not-allowed transition"
                        aria-label="نقل للأعلى">↑</button>
                <button type="button" @click="moveDown(position)" :disabled="position === order.length - 1"
                        class="w-9 h-9 shrink-0 grid place-items-center rounded-lg border border-white/10 hover:border-amethyst disabled:opacity-30 disabled:cursor-not-allowed transition"
                        aria-label="نقل للأسفل">↓</button>
            </li>
        </template>
    </ul>

    <input type="hidden" name="submission" :value="submissionJson">
</div>