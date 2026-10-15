<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // RefreshDatabase wipes the schema: never let it near a non-test database.
        // This runs before any testing trait is set up.
        $database = (string) $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]; expected a *_test database.");
        }

        return $app;
    }
}
