<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sifrious\Aleph\AlephServiceProvider;
use Sifrious\CloudflareConnector\CloudflareServiceProvider;
use Sifrious\Funes\FunesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FunesServiceProvider::class, AlephServiceProvider::class, CloudflareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
