<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register services for dependency injection
        $this->app->singleton(\App\Services\ProvablyFairService::class);
        $this->app->singleton(\App\Services\ScoringService::class);
        $this->app->singleton(\App\Services\GameService::class, function ($app) {
            return new \App\Services\GameService(
                $app->make(\App\Services\ProvablyFairService::class),
                $app->make(\App\Services\ScoringService::class)
            );
        });
    }
}
