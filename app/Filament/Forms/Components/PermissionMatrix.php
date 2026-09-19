<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class PermissionMatrix extends Field
{
    protected string $view = 'filament.forms.components.permission-matrix';

    public function getModules(): array
    {
        return config('permissions', []);
    }
}