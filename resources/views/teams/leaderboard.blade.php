@extends('layouts.app')

@section('title', 'جدول الفرق')

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">
        <x-page-header title="جدول الفرق" subtitle="ترتيب بعدد الانتصارات ثم المراكز الثلاثة الأولى ثم المشاركات، من المنافسات المعتمَدة فقط." icon="chart" :back="route('teams.index')" backLabel="الفرق" class="!mb-0 anim-fade-up" />

        <div class="glass rounded-3xl overflow-x-auto anim-fade-up">
            <table class="w-full text-sm">
                <caption class="sr-only">جدول الفرق</caption>
                <thead class="text-xs text-slate-400">
                    <tr><th scope="col" class="px-4 py-3 text-start">#</th><th scope="col" class="px-4 py-3 text-start">الفريق</th><th scope="col" class="px-4 py-3">انتصارات</th><th scope="col" class="px-4 py-3">ضمن الثلاثة</th><th scope="col" class="px-4 py-3">منافسات</th></tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @forelse ($table as $i => $row)
                        <tr>
                            <td class="px-4 py-3 text-slate-400">{{ ($table->currentPage() - 1) * $table->perPage() + $loop->iteration }}</td>
                            <td class="px-4 py-3"><a href="{{ route('teams.show', $row->team) }}" class="font-bold text-white hover:text-amethyst focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst rounded">{{ $row->team->name }}</a></td>
                            <td class="px-4 py-3 text-center text-gold font-bold">{{ $row->wins }}</td>
                            <td class="px-4 py-3 text-center">{{ $row->top3 }}</td>
                            <td class="px-4 py-3 text-center">{{ $row->events }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">لا نتائج فرق معتمَدة بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $table->links() }}</div>
    </div>
@endsection
