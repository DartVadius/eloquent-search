<?php

namespace Shifton\EloquentSearch;

use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/eloquent-search.php', 'eloquent-search');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/eloquent-search.php' => config_path('eloquent-search.php'),
        ], 'eloquent-search-config');
    }
}
