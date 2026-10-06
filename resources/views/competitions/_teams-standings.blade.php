{{-- E19: ترتيب الفرق. نهائي (مخزَّن عند الاعتماد) أو مؤقت. صيغة: مجموع أفضل 3 نتائج صحيحة لأعضاء الفريق (لقطة التسجيل). لا يغيّر ترتيب الأفراد ولا جوائزهم. --}}
<section class="glass rounded-3xl p-5 anim-fade-up" aria-labelledby="teams-board-title">
    <h2 id="teams-board-title" class="font-display font-black text-lg text-white">{{ $standings['final'] ? 'النتائج النهائية للفرق' : 'ترتيب الفرق المؤقت (غير نهائي)' }}</h2>
    <p class="text-xs text-slate-500 mt-1 mb-3">نقاط الفريق = مجموع أفضل 3 نتائج صحيحة لأعضائه المسجَّلين بالفريق وقت التسجيل بالمنافسة.</p>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">ترتيب الفرق</caption>
            <thead class="text-xs text-slate-400">
                <tr><th scope="col" class="px-3 py-2 text-start">#</th><th scope="col" class="px-3 py-2 text-start">الفريق</th><th scope="col" class="px-3 py-2">النقاط</th><th scope="col" class="px-3 py-2">أعضاء محتسَبون</th></tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                @forelse ($standings['rows'] as $row)
                    <tr>
                        <td class="px-3 py-2 text-slate-400">{{ $row->rank }}</td>
                        <td class="px-3 py-2"><a href="{{ route('teams.show', $row->team) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row->team->name }}</a></td>
                        <td class="px-3 py-2 text-center text-gold font-bold">{{ $row->score }}</td>
                        <td class="px-3 py-2 text-center">{{ $row->counted }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">لا نتائج صحيحة لفرق بعد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
