<?php

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Grpaiva\LaravelReactAgent\Providers\ReActAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Setup any application environment needs.
     */
    protected function defineEnvironment($app): void
    {
        // Use sqlite in memory for fast tests
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Load any service providers for your package.
     * This ensures your migrations, config, etc. are picked up in tests.
     */
    protected function getPackageProviders($app): array
    {
        return [
            ReActAgentServiceProvider::class,
        ];
    }
}
