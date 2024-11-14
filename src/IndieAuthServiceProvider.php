<?php

namespace janboddez\IndieAuth;

use Illuminate\Support\ServiceProvider;

class IndieAuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'indieauth');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/indieauth'),
        ]);
    }
}
