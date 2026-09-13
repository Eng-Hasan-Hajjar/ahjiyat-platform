<?php

return [

    'definitions' => [
        '' => \App\GameEngine\Definitions\LegacyGameTypeDefinition::class,
        'sequence' => \App\GameEngine\Definitions\SequenceGameTypeDefinition::class,
        'memory' => \App\GameEngine\Definitions\MemoryGameTypeDefinition::class,
        'spot_difference' => \App\GameEngine\Definitions\SpotDifferenceGameTypeDefinition::class,
    ],

    'filament_schemas' => [
        'sequence' => \App\Filament\GameTypeAuthoring\SequenceFilamentSchema::class,
        'memory' => \App\Filament\GameTypeAuthoring\MemoryFilamentSchema::class,
        'spot_difference' => \App\Filament\GameTypeAuthoring\SpotDifferenceFilamentSchema::class,
    ],

];