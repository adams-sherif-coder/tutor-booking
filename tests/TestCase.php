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
        if (config('database.default') === 'mysql' && ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            throw new RuntimeException('MySQL tests require a disposable database name ending in _test.');
        }

        return $app;
    }
}
