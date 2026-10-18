<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">
                حفظ إعدادات الدفع
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
