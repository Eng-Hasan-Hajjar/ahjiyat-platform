<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Filesystem\Filesystem;

class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // نعدل الـ loader نفسه الذي يستخدمه Laravel
        $this->app->extend('translation.loader', function ($loader, $app) {
            return new FileLoader(new Filesystem, resource_path('lang'));
        });
    }

    public function boot(): void
    {
        //
    }
}
