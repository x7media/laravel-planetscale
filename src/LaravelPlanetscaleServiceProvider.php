<?php

namespace X7media\LaravelPlanetscale;

use Illuminate\Support\ServiceProvider;
use X7media\LaravelPlanetscale\Console\Commands\PscaleMigrateCommand;

class LaravelPlanetscaleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/planetscale.php', 'planetscale');

        $this->app->singleton(LaravelPlanetscale::class, function ($app) {
            return new LaravelPlanetscale(config('planetscale.service_token.id'), config('planetscale.service_token.value'));
        });
    }

    public function provides(): array
    {
        return ['laravel-planetscale'];
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__ . '/../config/planetscale.php' => config_path('planetscale.php'),
        ], 'laravel-planetscale-config');

        $this->commands([PscaleMigrateCommand::class]);
    }
}
