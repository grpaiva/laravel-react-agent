<?php

namespace Grpaiva\LaravelReactAgent;

use Illuminate\Support\ServiceProvider;
use Grpaiva\LaravelReactAgent\Services\ReActAgent;

class ReActAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge our package config so Laravel sees default values
        $this->mergeConfigFrom(__DIR__ . '/../config/react-agent.php', 'react-agent');

        // Bind ReActAgent as a singleton so we can inject it
        $this->app->singleton('react.agent', function ($app) {
            return new ReActAgent();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/react-agent.php' => config_path('react-agent.php'),
        ], 'react-agent-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'react-agent-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
