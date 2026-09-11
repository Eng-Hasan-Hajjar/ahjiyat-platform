<?php
// app/Filament/GameTypeAuthoring/Contracts/FilamentGameTypeSchema.php
namespace App\Filament\GameTypeAuthoring\Contracts;

/**
 * عقد خاص بـFilament فقط - عمداً خارج app/GameEngine بالكامل، حتى لا
 * يعتمد محرك الألعاب الأساسي على أي إطار واجهة. أي واجهة تأليف مستقبلية
 * (Sponsor Portal، إلخ) تحصل على عقدها الخاص المكافئ بدل إجبارها على
 * Filament\Forms\Components.
 */
interface FilamentGameTypeSchema
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    public function fields(): array;
}