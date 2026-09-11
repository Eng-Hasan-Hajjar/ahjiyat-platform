<?php

use App\Filament\GameTypeAuthoring\FilamentGameTypeSchemaRegistry;
use Filament\Forms\Components\Component;

test('authoring registry returns fields for every registered game type without a central if/else', function () {
    $fields = app(FilamentGameTypeSchemaRegistry::class)->allFields();

    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        expect($field)->toBeInstanceOf(Component::class);
    }
});