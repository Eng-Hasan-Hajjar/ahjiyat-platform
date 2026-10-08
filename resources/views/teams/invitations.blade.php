@extends('layouts.app')

@section('title', 'دعواتي')

@section('content')
    <div class="max-w-2xl mx-auto space-y-6">
        <x-page-header title="دعوات الفرق" icon="mail" :back="route('teams.index')" backLabel="الفرق" class="!mb-0 anim-fade-up" />

        <div class="space-y-3">
            @forelse ($invitations as $invitation)
                <div class="glass rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3 anim-fade-up">
                    <div class="min-w-0">
                        <div class="font-bold text-white truncate">{{ $invitation->team->name }}</div>
                        <div class="text-xs text-slate-400">دعوة من {{ $invitation->inviter?->name ?? 'مشرف الفريق' }} · تنتهي {{ $invitation->expires_at->format('Y-m-d') }}</div>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('teams.invitations.accept', $invitation) }}">@csrf <button type="submit" class="btn-gem !py-2 !px-4 text-sm">قبول</button></form>
                        <form method="POST" action="{{ route('teams.invitations.decline', $invitation) }}">@csrf <button type="submit" class="chip focus:outline-none focus-visible:ring-2 focus-visible:ring-amethyst">رفض</button></form>
                    </div>
                </div>
            @empty
                <p class="glass rounded-2xl px-4 py-12 text-center text-slate-500 text-sm">لا دعوات معلّقة.</p>
            @endforelse
        </div>
    </div>
@endsection
