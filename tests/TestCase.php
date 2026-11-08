<?php

namespace Tests;

use Laravel\Lumen\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * Feature tests run migrate:fresh, so refuse to boot against any database
     * that is not a disposable *_testing database (see phpunit.xml).
     *
     * @return \Laravel\Lumen\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (!str_ends_with($database, '_testing') && $database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against database [{$database}]: tests wipe their database, " .
                'so it must be a throwaway *_testing database.'
            );
        }

        return $app;
    }

    /** The last HTTP response (public so Pest helper functions can read it). */
    public function lastResponse()
    {
        return $this->response;
    }

    /** Swap a container binding for this test. */
    public function bindInstance(string $abstract, $instance): void
    {
        $this->app->instance($abstract, $instance);
    }
}
